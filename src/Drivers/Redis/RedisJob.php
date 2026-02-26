<?php

declare(strict_types=1);

namespace Station\Drivers\Redis;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

final class RedisJob extends Job implements JobContract
{
    private readonly RedisQueue $redis;

    private readonly string $rawPayload;

    public function __construct(
        Container $container,
        RedisQueue $redis,
        string $rawPayload,
        string $connectionName,
        string $queue,
    ) {
        $this->container = $container;
        $this->redis = $redis;
        $this->rawPayload = $rawPayload;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    /**
     * Release the job back into the queue.
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        // Remove from reserved
        $this->redis->deleteReserved($this->queue, $this->rawPayload);

        // Re-queue with delay
        if ($delay > 0) {
            $this->redis->laterRaw($delay, $this->preparePayloadForRelease(), $this->queue);
        } else {
            $this->redis->pushRaw($this->preparePayloadForRelease(), $this->queue);
        }
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        $this->redis->deleteReserved($this->queue, $this->rawPayload);
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        $payload = $this->payload();

        return (int) ($payload['attempts'] ?? 1);
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        $payload = $this->payload();

        return $payload['uuid'] ?? $payload['id'] ?? '';
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return $this->rawPayload;
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return $this->queue;
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
                $instance = unserialize($serializedJob, ['allowed_classes' => true]);

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
        return json_decode($this->rawPayload, true) ?? [];
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

    /**
     * Prepare the payload for release (increment attempts).
     */
    private function preparePayloadForRelease(): string
    {
        $payload = $this->payload();
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;

        return json_encode($payload) ?: '{}';
    }
}
