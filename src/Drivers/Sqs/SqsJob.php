<?php

declare(strict_types=1);

namespace Station\Drivers\Sqs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

final class SqsJob extends Job implements JobContract
{
    private readonly SqsQueue $sqs;

    /** @var array<string, mixed> */
    private readonly array $message;

    private readonly string $queueUrl;

    public function __construct(
        Container $container,
        SqsQueue $sqs,
        array $message,
        string $connectionName,
        string $queue,
        string $queueUrl,
    ) {
        $this->container = $container;
        $this->sqs = $sqs;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->queueUrl = $queueUrl;
    }

    /**
     * Release the job back into the queue.
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        // Delete from current location
        $this->sqs->deleteMessage($this->queueUrl, $this->message['ReceiptHandle']);

        // Re-queue with delay
        if ($delay > 0) {
            $this->sqs->laterRaw($delay, $this->preparePayloadForRelease(), $this->queue);
        } else {
            $this->sqs->pushRaw($this->preparePayloadForRelease(), $this->queue);
        }
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        $this->sqs->deleteMessage($this->queueUrl, $this->message['ReceiptHandle']);
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

        // SQS provides ApproximateReceiveCount
        return (int) ($this->message['Attributes']['ApproximateReceiveCount'] ?? 1);
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        $payload = $this->payload();

        return $payload['uuid'] ?? $this->message['MessageId'] ?? '';
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return $this->message['Body'];
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Get the SQS message.
     *
     * @return array<string, mixed>
     */
    public function getMessage(): array
    {
        return $this->message;
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
        return json_decode($this->message['Body'], true) ?? [];
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
