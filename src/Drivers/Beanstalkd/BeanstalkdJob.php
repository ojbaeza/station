<?php

declare(strict_types=1);

namespace Station\Drivers\Beanstalkd;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Values\Job as PheanstalkJob;

final class BeanstalkdJob extends Job implements JobContract
{
    private readonly BeanstalkdQueue $beanstalkd;

    private readonly PheanstalkJob $job;

    public function __construct(
        Container $container,
        BeanstalkdQueue $beanstalkd,
        PheanstalkJob $job,
        string $connectionName,
        string $queue,
    ) {
        $this->container = $container;
        $this->beanstalkd = $beanstalkd;
        $this->job = $job;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    /**
     * Release the job back into the queue.
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        $priority = $this->beanstalkd->getDefaultPriority();
        $this->beanstalkd->releaseJob($this->job, $priority, $delay);
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        $this->beanstalkd->deleteJob($this->job);
    }

    /**
     * Bury the job in the queue.
     */
    public function bury(): void
    {
        $this->beanstalkd->buryJob($this->job, $this->beanstalkd->getDefaultPriority());
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        // Check payload first
        $payload = $this->payload();

        if (isset($payload['attempts'])) {
            return (int) $payload['attempts'];
        }

        // Beanstalkd provides reserves count in job stats
        $stats = $this->beanstalkd->getJobStats($this->job);

        return (int) ($stats['reserves'] ?? 1);
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        $payload = $this->payload();

        return $payload['uuid'] ?? (string) $this->job->getId();
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return $this->job->getData();
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Get the underlying Pheanstalk job.
     */
    public function getPheanstalkJob(): PheanstalkJob
    {
        return $this->job;
    }

    /**
     * Get job stats from Beanstalkd.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->beanstalkd->getJobStats($this->job);
    }

    /**
     * Get the job priority.
     */
    public function getPriority(): int
    {
        $stats = $this->getStats();

        return (int) ($stats['pri'] ?? PheanstalkPublisherInterface::DEFAULT_PRIORITY);
    }

    /**
     * Get time-to-run remaining.
     */
    public function getTimeLeft(): int
    {
        $stats = $this->getStats();

        return (int) ($stats['time-left'] ?? 0);
    }

    /**
     * Touch the job to reset TTR.
     */
    public function touch(): void
    {
        $this->beanstalkd->touchJob($this->job);
    }

    /**
     * Fire the job.
     */
    public function fire(): void
    {
        $payload = $this->payload();

        // Handle Station job format (job dispatched via Station::dispatch)
        // Data is nested inside 'data' key by Laravel's createStringPayload
        $data = $payload['data'] ?? [];

        if (isset($data['station_job_id'])) {
            $serializedJob = $data['payload'] ?? null;

            if ($serializedJob && \is_string($serializedJob)) {
                // Unserialize the job instance (it was serialized when dispatched)
                $instance = unserialize($serializedJob);

                if (\is_object($instance) && method_exists($instance, 'handle')) {
                    // Set queue properties if the job uses InteractsWithQueue
                    if (method_exists($instance, 'setJob')) {
                        $instance->setJob($this);
                    }

                    // Call the handle method with dependency injection
                    $this->container->call([$instance, 'handle']);
                }
            }

            return;
        }

        // Handle standard Laravel job format (job dispatched via Laravel's dispatch())
        parent::fire();
    }

    /**
     * Get the decoded body of the job.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return json_decode($this->job->getData(), true) ?? [];
    }

    /**
     * Parse the job class and method from payload.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string}
     */
    protected function parseJobClassAndMethod(array $payload): array
    {
        $job = $payload['job'] ?? $payload['displayName'] ?? 'UnknownJob';
        [$class, $method] = str_contains($job, '@') ? explode('@', $job) : [$job, 'handle'];

        return [$class, $method];
    }
}
