<?php

declare(strict_types=1);

namespace Station\Drivers\Kafka;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RdKafka\Message;
use RdKafka\TopicPartition;
use RuntimeException;
use Station\Contracts\DriverInterface;
use Station\Drivers\Traits\ChecksPauseStatus;
use Throwable;

final class KafkaQueue extends Queue implements DriverInterface, QueueContract
{
    use ChecksPauseStatus;

    public function __construct(
        private readonly KafkaConnection $connection,
        private readonly string $defaultQueue,
    ) {}

    /**
     * Get the size of the queue.
     *
     * Note: Kafka doesn't provide an easy way to get queue size.
     * This returns an approximation based on watermarks.
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);

        try {
            $consumer = $this->connection->getConsumer();
            $metadata = $consumer->getMetadata(true, null, 5000);

            foreach ($metadata->getTopics() as $topic) {
                if ($topic->getTopic() === $queue) {
                    $totalSize = 0;

                    foreach ($topic->getPartitions() as $partition) {
                        $low = 0;
                        $high = 0;
                        $consumer->queryWatermarkOffsets(
                            $queue,
                            $partition->getId(),
                            $low,
                            $high,
                            5000,
                        );
                        $totalSize += ($high - $low);
                    }

                    return $totalSize;
                }
            }
        } catch (Throwable) {
            // Ignore errors
        }

        return 0;
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
        $producer = $this->connection->getProducer();

        $topic = $producer->newTopic($queue);

        // Use key for partitioning if provided
        $key = $options['key'] ?? null;
        $partition = $options['partition'] ?? RD_KAFKA_PARTITION_UA;

        $topic->produce($partition, 0, $payload, $key);

        // Flush to ensure message is sent
        $result = $producer->flush($this->connection->getFlushTimeout());

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException('Failed to flush Kafka producer: ' . rd_kafka_err2str($result));
        }

        // Return a UUID as message ID (Kafka doesn't provide message IDs during produce)
        $payloadData = json_decode($payload, true);

        return $payloadData['uuid'] ?? Uuid::uuid7()->toString();
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * Note: Kafka doesn't natively support delayed messages.
     * We store the job in a database table and process it later.
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
        $delaySeconds = $this->secondsUntil($delay);

        $payloadData = json_decode($payload, true);
        $uuid = $payloadData['uuid'] ?? Uuid::uuid7()->toString();

        // Store in delayed jobs table
        DB::table('station_kafka_delayed_jobs')->insert([
            'id' => $uuid,
            'queue' => $queue,
            'payload' => $payload,
            'available_at' => now()->addSeconds($delaySeconds),
            'created_at' => now(),
        ]);

        return $uuid;
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

        // First, migrate any delayed jobs that are ready
        $this->migrateDelayedJobs($queue);

        // Subscribe to the topic
        $this->connection->subscribe($queue);

        $consumer = $this->connection->getConsumer();

        try {
            $message = $consumer->consume($this->connection->getConsumeTimeout());
        } catch (Throwable) {
            return null;
        }

        // @phpstan-ignore identical.alwaysFalse (consume() can return null on timeout/error)
        if ($message === null) {
            return null;
        }

        // Handle different message states
        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                return new KafkaJob(
                    $this->container,
                    $this,
                    $message,
                    $this->getConnectionName(),
                    $queue,
                );

            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                return null;

            default:
                // Log error but don't throw
                return null;
        }
    }

    /**
     * Clear all jobs from a queue.
     *
     * Note: Kafka doesn't support clearing topics. This is a no-op.
     */
    public function clear(string $queue): int
    {
        // Kafka topics cannot be cleared - messages are retained based on retention policy
        // The best we can do is delete delayed jobs from the database
        return DB::table('station_kafka_delayed_jobs')
            ->where('queue', $queue)
            ->delete();
    }

    /**
     * Pause a queue.
     *
     * Note: Kafka doesn't support pausing at consumer level directly.
     * We track paused state in database and check in pop().
     */
    public function pause(string $queue): void
    {
        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'kafka'],
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
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'kafka'],
            ['paused' => false, 'paused_at' => null, 'updated_at' => now()],
        );

        $this->pauseCache[$queue] = false;
        $this->pauseCacheTime[$queue] = microtime(true);
    }

    /**
     * Get consumer lag for a topic.
     *
     * @return array<string, array{partition: int, lag: int}>
     */
    public function getConsumerLag(string $queue): array
    {
        $lag = [];

        try {
            $consumer = $this->connection->getConsumer();
            $metadata = $consumer->getMetadata(true, null, 5000);

            foreach ($metadata->getTopics() as $topic) {
                if ($topic->getTopic() !== $queue) {
                    continue;
                }

                foreach ($topic->getPartitions() as $partition) {
                    $partitionId = $partition->getId();

                    // Get high watermark
                    $low = 0;
                    $high = 0;
                    $consumer->queryWatermarkOffsets($queue, $partitionId, $low, $high, 5000);

                    // Get committed offset
                    $topicPartition = new TopicPartition($queue, $partitionId);
                    $committed = $consumer->getCommittedOffsets([$topicPartition], 5000);

                    $committedOffset = 0;
                    foreach ($committed as $tp) {
                        if ($tp->getTopic() === $queue && $tp->getPartition() === $partitionId) {
                            $committedOffset = $tp->getOffset();

                            break;
                        }
                    }

                    $lag["partition_{$partitionId}"] = [
                        'partition' => $partitionId,
                        'lag' => (int) max(0, $high - $committedOffset),
                    ];
                }
            }
        } catch (Throwable) {
            // Ignore errors
        }

        return $lag;
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
            $consumer = $this->connection->getConsumer();
            $consumer->getMetadata(true, null, 5000);
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
     * Commit the offset for a message.
     */
    public function commitOffset(Message $message): void
    {
        if (!$this->connection->isAutoCommit()) {
            $this->connection->getConsumer()->commit($message);
        }
    }

    /**
     * Commit offsets asynchronously.
     */
    public function commitOffsetAsync(Message $message): void
    {
        if (!$this->connection->isAutoCommit()) {
            $this->connection->getConsumer()->commitAsync($message);
        }
    }

    /**
     * Get driver-specific detailed info for metrics dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDriverInfo(string $queue): array
    {
        $info = [
            'driver' => 'kafka',
            'size' => $this->size($queue),
        ];

        try {
            $consumer = $this->connection->getConsumer();
            $metadata = $consumer->getMetadata(true, null, 5000);

            $info['brokers'] = \count($metadata->getBrokers());

            foreach ($metadata->getTopics() as $topic) {
                if ($topic->getTopic() === $queue) {
                    $partitions = $topic->getPartitions();
                    $info['partitions'] = \count($partitions);
                    $totalLag = 0;
                    $lagPerPartition = [];

                    foreach ($partitions as $partition) {
                        $low = 0;
                        $high = 0;
                        $consumer->queryWatermarkOffsets(
                            $queue,
                            $partition->getId(),
                            $low,
                            $high,
                            5000,
                        );
                        $lag = $high - $low;
                        $totalLag += $lag;
                        $lagPerPartition['partition_' . $partition->getId()] = $lag;
                    }

                    $info['consumer_lag'] = $lagPerPartition;
                    $info['total_lag'] = $totalLag;
                    $info['total_messages'] = $totalLag;

                    break;
                }
            }
        } catch (Throwable) {
            // Kafka metadata may not be available
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
     * Get the dead letter queue contents.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDeadLetterQueue(string $queue, int $limit = 50): array
    {
        // Kafka typically uses a separate DLQ topic
        $dlqTopic = $queue . '.dlq';
        $jobs = [];

        try {
            $consumer = $this->connection->getConsumer();
            $consumer->subscribe([$dlqTopic]);

            for ($i = 0; $i < $limit; $i++) {
                $message = $consumer->consume(1000);

                // @phpstan-ignore identical.alwaysFalse (consume() can return null)
                if ($message === null || $message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    break;
                }

                $jobs[] = [
                    'id' => $message->topic_name . ':' . $message->partition . ':' . $message->offset,
                    'payload' => $message->payload,
                    'partition' => $message->partition,
                    'offset' => $message->offset,
                    'timestamp' => $message->timestamp,
                ];
            }

            $consumer->unsubscribe();
        } catch (Throwable) {
            // DLQ topic may not exist
        }

        return $jobs;
    }

    /**
     * Requeue a message from the dead letter queue.
     */
    public function requeueFromDeadLetter(string $queue, string $messageId): bool
    {
        // Parse the message ID (topic:partition:offset)
        $parts = explode(':', $messageId);

        if (\count($parts) !== 3) {
            return false;
        }

        $dlqTopic = $parts[0];
        $partition = (int) $parts[1];
        $offset = (int) $parts[2];

        try {
            $consumer = $this->connection->getConsumer();
            $consumer->assign([new TopicPartition($dlqTopic, $partition, $offset)]);

            $message = $consumer->consume(5000);

            // @phpstan-ignore notIdentical.alwaysTrue (consume() can return null)
            if ($message !== null && $message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                // Re-publish to original topic
                $this->pushRaw($message->payload, $queue);
                $consumer->commit($message);

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Migrate delayed jobs that are ready.
     */
    private function migrateDelayedJobs(string $queue): void
    {
        $jobs = DB::table('station_kafka_delayed_jobs')
            ->where('queue', $queue)
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->limit(100)
            ->get();

        foreach ($jobs as $job) {
            try {
                $this->pushRaw($job->payload, $queue);

                DB::table('station_kafka_delayed_jobs')
                    ->where('id', $job->id)
                    ->delete();
            } catch (Throwable) {
                // Will retry next time
            }
        }
    }

    /**
     * Get the queue name.
     */
    private function getQueue(?string $queue): string
    {
        return $queue ?? $this->defaultQueue;
    }
}
