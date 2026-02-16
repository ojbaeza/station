<?php

declare(strict_types=1);

namespace Station\Drivers\Sqs;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Station\Contracts\DriverInterface;
use Station\Drivers\Traits\ChecksPauseStatus;
use Throwable;

final class SqsQueue extends Queue implements DriverInterface, QueueContract
{
    use ChecksPauseStatus;

    public function __construct(
        private readonly SqsConnection $connection,
        private readonly string $defaultQueue,
    ) {}

    /**
     * Get the size of the queue.
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);
        $client = $this->connection->getClient();
        $url = $this->connection->getQueueUrl($queue);

        $response = $client->getQueueAttributes([
            'QueueUrl' => $url,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ]);

        return (int) ($response['Attributes']['ApproximateNumberOfMessages'] ?? 0);
    }

    /**
     * Push a new job onto the queue.
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn($payload, $queue) => $this->pushRaw($payload, $queue),
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param array<string, mixed> $options
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queue = $this->getQueue($queue);
        $client = $this->connection->getClient();
        $url = $this->connection->getQueueUrl($queue);

        $params = [
            'QueueUrl' => $url,
            'MessageBody' => $payload,
        ];

        // FIFO queue parameters
        if ($this->connection->isFifo()) {
            $params['MessageGroupId'] = $options['message_group_id']
                ?? $this->connection->getMessageGroupId();

            $deduplicationId = $this->connection->getDeduplicationId($payload);

            if ($deduplicationId !== '') {
                $params['MessageDeduplicationId'] = $deduplicationId;
            }
        }

        // Message attributes
        if (isset($options['attributes'])) {
            $params['MessageAttributes'] = $options['attributes'];
        }

        $response = $client->sendMessage($params);

        return $response['MessageId'] ?? Uuid::uuid7()->toString();
    }

    /**
     * Push a new job onto the queue after a delay.
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            fn($payload, $queue, $delay) => $this->laterRaw($delay, $payload, $queue),
        );
    }

    /**
     * Push a raw job onto the queue after a delay.
     */
    public function laterRaw(int $delay, string $payload, ?string $queue = null): string
    {
        $queue = $this->getQueue($queue);
        $client = $this->connection->getClient();
        $url = $this->connection->getQueueUrl($queue);

        // SQS max delay is 15 minutes (900 seconds)
        $delaySeconds = min($this->secondsUntil($delay), 900);

        $params = [
            'QueueUrl' => $url,
            'MessageBody' => $payload,
            'DelaySeconds' => $delaySeconds,
        ];

        // FIFO queue parameters
        if ($this->connection->isFifo()) {
            $params['MessageGroupId'] = $this->connection->getMessageGroupId();

            $deduplicationId = $this->connection->getDeduplicationId($payload);

            if ($deduplicationId !== '') {
                $params['MessageDeduplicationId'] = $deduplicationId;
            }
        }

        $response = $client->sendMessage($params);

        return $response['MessageId'] ?? Uuid::uuid7()->toString();
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        if ($this->isPaused($queue)) {
            return null;
        }

        $client = $this->connection->getClient();
        $url = $this->connection->getQueueUrl($queue);

        $response = $client->receiveMessage([
            'QueueUrl' => $url,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => $this->connection->getWaitTime(),
            'VisibilityTimeout' => $this->connection->getVisibilityTimeout(),
            'AttributeNames' => ['All'],
            'MessageAttributeNames' => ['All'],
        ]);

        if (empty($response['Messages'])) {
            return null;
        }

        $message = $response['Messages'][0];

        return new SqsJob(
            $this->container,
            $this,
            $message,
            $this->getConnectionName(),
            $queue,
            $url,
        );
    }

    /**
     * Clear all jobs from a queue.
     */
    public function clear(string $queue): int
    {
        $client = $this->connection->getClient();
        $url = $this->connection->getQueueUrl($queue);

        $count = $this->size($queue);

        $client->purgeQueue([
            'QueueUrl' => $url,
        ]);

        return $count;
    }

    /**
     * Pause a queue.
     */
    public function pause(string $queue): void
    {
        // SQS doesn't support pausing natively, use database flag
        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'sqs'],
            ['paused' => true, 'paused_at' => now(), 'updated_at' => now()],
        );

        $this->pauseCache[$queue] = true;
        $this->pauseCacheTime[$queue] = microtime(true);
    }

    /**
     * Resume a paused queue.
     */
    public function resume(string $queue): void
    {
        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'sqs'],
            ['paused' => false, 'paused_at' => null, 'updated_at' => now()],
        );

        $this->pauseCache[$queue] = false;
        $this->pauseCacheTime[$queue] = microtime(true);
    }

    /**
     * Get the dead letter queue contents.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDeadLetterQueue(string $queue, int $limit = 50): array
    {
        // DLQ name convention
        $dlqName = $queue . '-dlq';
        $client = $this->connection->getClient();

        try {
            $url = $this->connection->getQueueUrl($dlqName);
        } catch (Throwable) {
            return [];
        }

        $messages = [];
        $received = 0;

        while ($received < $limit) {
            $response = $client->receiveMessage([
                'QueueUrl' => $url,
                'MaxNumberOfMessages' => min(10, $limit - $received),
                'WaitTimeSeconds' => 0,
                'VisibilityTimeout' => 0,
            ]);

            if (empty($response['Messages'])) {
                break;
            }

            foreach ($response['Messages'] as $message) {
                $messages[] = [
                    'id' => $message['MessageId'],
                    'receipt_handle' => $message['ReceiptHandle'],
                    'body' => $message['Body'],
                    'attributes' => $message['Attributes'] ?? [],
                ];
                $received++;
            }
        }

        return $messages;
    }

    /**
     * Requeue a message from the dead letter queue.
     */
    public function requeueFromDeadLetter(string $queue, string $messageId): bool
    {
        $dlqName = $queue . '-dlq';
        $client = $this->connection->getClient();

        try {
            $dlqUrl = $this->connection->getQueueUrl($dlqName);
            $queueUrl = $this->connection->getQueueUrl($queue);
        } catch (Throwable) {
            return false;
        }

        // Find the message
        $response = $client->receiveMessage([
            'QueueUrl' => $dlqUrl,
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => 0,
        ]);

        foreach ($response['Messages'] ?? [] as $message) {
            if ($message['MessageId'] === $messageId) {
                // Send to main queue
                $this->pushRaw($message['Body'], $queue);

                // Delete from DLQ
                $client->deleteMessage([
                    'QueueUrl' => $dlqUrl,
                    'ReceiptHandle' => $message['ReceiptHandle'],
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Get driver-specific health status.
     *
     * @return array{connected: bool, latency_ms: int, message?: string}
     */
    public function healthCheck(): array
    {
        $start = microtime(true);

        try {
            $this->connection->getClient()->listQueues(['MaxResults' => 1]);
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'connected' => true,
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'latency_ms' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a message from the queue.
     */
    public function deleteMessage(string $queueUrl, string $receiptHandle): void
    {
        $this->connection->getClient()->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    /**
     * Change visibility timeout for a message.
     */
    public function changeVisibility(string $queueUrl, string $receiptHandle, int $seconds): void
    {
        $this->connection->getClient()->changeMessageVisibility([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
            'VisibilityTimeout' => $seconds,
        ]);
    }

    /**
     * Get driver-specific detailed info for metrics dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDriverInfo(string $queue): array
    {
        $client = $this->connection->getClient();
        $url = $this->connection->getQueueUrl($queue);

        $info = [
            'driver' => 'sqs',
            'size' => $this->size($queue),
        ];

        try {
            $response = $client->getQueueAttributes([
                'QueueUrl' => $url,
                'AttributeNames' => [
                    'ApproximateNumberOfMessages',
                    'ApproximateNumberOfMessagesNotVisible',
                    'ApproximateNumberOfMessagesDelayed',
                    'QueueArn',
                    'VisibilityTimeout',
                    'MessageRetentionPeriod',
                ],
            ]);

            $attrs = $response['Attributes'] ?? [];
            $info['visible'] = (int) ($attrs['ApproximateNumberOfMessages'] ?? 0);
            $info['in_flight'] = (int) ($attrs['ApproximateNumberOfMessagesNotVisible'] ?? 0);
            $info['delayed'] = (int) ($attrs['ApproximateNumberOfMessagesDelayed'] ?? 0);
            $info['visibility_timeout'] = (int) ($attrs['VisibilityTimeout'] ?? 0);
            $info['retention_period'] = (int) ($attrs['MessageRetentionPeriod'] ?? 0);
            $info['arn'] = $attrs['QueueArn'] ?? '';
        } catch (Throwable) {
            // SQS API may fail, fall back to basic size
        }

        return $info;
    }

    /**
     * Get the connection name.
     */
    public function getConnectionName(): string
    {
        // @phpstan-ignore nullCoalesce.property (connectionName can be unset before setConnectionName is called)
        return $this->connectionName ?? 'station';
    }

    /**
     * Set the connection name.
     */
    public function setConnectionName($name)
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Get the queue name.
     */
    private function getQueue(?string $queue): string
    {
        return $queue ?? $this->defaultQueue;
    }
}
