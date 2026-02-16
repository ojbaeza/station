<?php

declare(strict_types=1);

namespace Station\Drivers\Kafka;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use RdKafka\Message;

final class KafkaJob extends Job implements JobContract
{
    private readonly KafkaQueue $kafka;

    private readonly Message $message;

    public function __construct(
        Container $container,
        KafkaQueue $kafka,
        Message $message,
        string $connectionName,
        string $queue,
    ) {
        $this->container = $container;
        $this->kafka = $kafka;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    /**
     * Release the job back into the queue.
     *
     * Note: Kafka doesn't support releasing messages back to the queue.
     * We re-push the job to the topic.
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        // Get payload and increment attempts
        $payload = $this->payload();
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
        $newPayload = json_encode($payload) ?: '{}';

        if ($delay > 0) {
            $this->kafka->laterRaw($delay, $newPayload, $this->queue);
        } else {
            $this->kafka->pushRaw($newPayload, $this->queue);
        }

        // Commit the original message offset
        $this->kafka->commitOffset($this->message);
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        // In Kafka, "deleting" means committing the offset
        $this->kafka->commitOffset($this->message);
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

        // Construct a unique ID from topic, partition, and offset
        return $payload['uuid'] ?? \sprintf(
            '%s-%d-%d',
            $this->message->topic_name,
            $this->message->partition,
            $this->message->offset,
        );
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return $this->message->payload;
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Get the underlying Kafka message.
     */
    public function getKafkaMessage(): Message
    {
        return $this->message;
    }

    /**
     * Get the message key.
     */
    public function getKey(): string
    {
        return $this->message->key ?: '';
    }

    /**
     * Get the message partition.
     */
    public function getPartition(): int
    {
        return $this->message->partition;
    }

    /**
     * Get the message offset.
     */
    public function getOffset(): int
    {
        return $this->message->offset;
    }

    /**
     * Get the message timestamp.
     */
    public function getTimestamp(): int
    {
        return $this->message->timestamp;
    }

    /**
     * Get the message headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->message->headers;
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
        return json_decode($this->message->payload, true) ?? [];
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
