<?php

declare(strict_types=1);

namespace Station\Drivers\Redis;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Station\Contracts\AggregateDriverInfoInterface;
use Station\Contracts\DriverInterface;
use Station\Drivers\Traits\ChecksPauseStatus;
use Throwable;

final class RedisQueue extends Queue implements AggregateDriverInfoInterface, DriverInterface, QueueContract
{
    use ChecksPauseStatus;

    /**
     * @param array<string, mixed> $driverConfig
     */
    public function __construct(
        private readonly RedisConnection $connection,
        private readonly string $defaultQueue,
        private array $driverConfig = [],
    ) {}

    /**
     * Get the size of the queue.
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);
        $conn = $this->connection->getConnection();

        // Size = waiting + delayed + reserved
        $waiting = $conn->llen($this->connection->key("queues:{$queue}"));
        $delayed = $conn->zcard($this->connection->key("queues:{$queue}:delayed"));
        $reserved = $conn->zcard($this->connection->key("queues:{$queue}:reserved"));

        return $waiting + $delayed + $reserved;
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
        $conn = $this->connection->getConnection();

        $conn->rpush($this->connection->key("queues:{$queue}"), $payload);

        $decoded = json_decode($payload, true);

        return $decoded['uuid'] ?? Uuid::uuid7()->toString();
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
        $conn = $this->connection->getConnection();

        $availableAt = $this->availableAt($delay);

        $conn->zadd(
            $this->connection->key("queues:{$queue}:delayed"),
            $availableAt,
            $payload,
        );

        $decoded = json_decode($payload, true);

        return $decoded['uuid'] ?? Uuid::uuid7()->toString();
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

        $conn = $this->connection->getConnection();

        // Pop from the queue
        $payload = $conn->lpop($this->connection->key("queues:{$queue}"));

        if ($payload === false || !\is_string($payload)) {
            return null;
        }

        // Add to reserved set
        $reservedAt = time();
        $timeout = $this->driverConfig['retry_after'] ?? 60;

        $conn->zadd(
            $this->connection->key("queues:{$queue}:reserved"),
            $reservedAt + $timeout,
            $payload,
        );

        return new RedisJob(
            $this->container,
            $this,
            $payload,
            $this->getConnectionName(),
            $queue,
        );
    }

    /**
     * Clear all jobs from a queue.
     */
    public function clear(string $queue): int
    {
        $conn = $this->connection->getConnection();
        $prefix = $this->connection->key("queues:{$queue}");

        $count = $conn->llen($prefix);
        $count += $conn->zcard("{$prefix}:delayed");
        $count += $conn->zcard("{$prefix}:reserved");

        $conn->del([
            $prefix,
            "{$prefix}:delayed",
            "{$prefix}:reserved",
        ]);

        return $count;
    }

    /**
     * Pause a queue.
     */
    public function pause(string $queue): void
    {
        // Redis cache for fast lookups
        $this->connection->getConnection()->set(
            $this->connection->key("queues:{$queue}:paused"),
            '1',
        );

        // Database for consistency with other drivers
        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'redis'],
            ['paused' => true, 'paused_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * Resume a paused queue.
     */
    public function resume(string $queue): void
    {
        // Redis cache
        $this->connection->getConnection()->del(
            $this->connection->key("queues:{$queue}:paused"),
        );

        // Database for consistency
        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'redis'],
            ['paused' => false, 'paused_at' => null, 'updated_at' => now()],
        );
    }

    /**
     * Check if a queue is paused.
     *
     * Checks Redis key first (cross-process, no TTL needed), then falls back
     * to the trait's time-based DB check.
     */
    public function isPaused(string $queue): bool
    {
        try {
            $paused = $this->connection->getConnection()->get(
                $this->connection->key("queues:{$queue}:paused"),
            );

            if ($paused === '1') {
                return true;
            }
        } catch (Throwable) {
            // Redis unavailable, fall through to DB
        }

        return $this->queryPauseStatus($queue);
    }

    /**
     * Get the dead letter queue contents.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDeadLetterQueue(string $queue, int $limit = 50): array
    {
        $conn = $this->connection->getConnection();
        $dlqKey = $this->connection->key("queues:{$queue}:failed");

        $messages = $conn->lrange($dlqKey, 0, $limit - 1);
        $result = [];

        foreach ($messages as $index => $payload) {
            $decoded = json_decode($payload, true) ?? [];
            $result[] = [
                'id' => $index,
                'body' => $payload,
                'data' => $decoded,
            ];
        }

        return $result;
    }

    /**
     * Requeue a message from the dead letter queue.
     */
    public function requeueFromDeadLetter(string $queue, string $messageId): bool
    {
        $conn = $this->connection->getConnection();
        $dlqKey = $this->connection->key("queues:{$queue}:failed");

        $index = (int) $messageId;
        $payload = $conn->lindex($dlqKey, $index);

        if ($payload === null || $payload === false) {
            return false;
        }

        // Remove from DLQ and push to main queue
        $conn->lrem($dlqKey, $payload, 1);
        $this->pushRaw($payload, $queue);

        return true;
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
            $this->connection->getConnection()->ping();
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
     * Get driver-specific detailed info for metrics dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDriverInfo(string $queue): array
    {
        $conn = $this->connection->getConnection();

        $ready = $conn->llen($this->connection->key("queues:{$queue}"));
        $delayed = $conn->zcard($this->connection->key("queues:{$queue}:delayed"));
        $reserved = $conn->zcard($this->connection->key("queues:{$queue}:reserved"));

        $info = [
            'driver' => 'redis',
            'size' => $ready + $delayed + $reserved,
            'ready' => $ready,
            'delayed' => $delayed,
            'reserved' => $reserved,
        ];

        $this->addRedisServerStats($info);

        return $info;
    }

    /**
     * Get driver info aggregated across all discoverable queues.
     *
     * @return array<string, mixed>
     */
    public function getAllDriverInfo(): array
    {
        $conn = $this->connection->getConnection();
        $prefix = $this->connection->getPrefix();
        $pattern = $prefix . 'queues:*';

        try {
            // SCAN for queue keys and deduplicate to base queue names
            $cursor = null;
            $discoveredKeys = [];
            $scanLimit = 50;

            do {
                /** @var array{0: int, 1: array<string>} $result */
                $result = $conn->scan($cursor, ['match' => $pattern, 'count' => 100]); // @phpstan-ignore argument.type
                $cursor = (int) $result[0];
                foreach ($result[1] as $key) {
                    $discoveredKeys[] = $key;
                    if (\count($discoveredKeys) >= 500) {
                        break 2;
                    }
                }
            } while ($cursor !== 0);

            // Strip prefix and suffixes to get unique queue names
            $prefixLen = \strlen($prefix . 'queues:');
            $queueNames = [];

            foreach ($discoveredKeys as $key) {
                $stripped = substr($key, $prefixLen);
                // Remove known suffixes
                $stripped = preg_replace('/:(?:delayed|reserved|notify|paused|failed)$/', '', $stripped);
                if ($stripped !== '' && $stripped !== null) {
                    $queueNames[$stripped] = true;
                }
            }

            $queueNames = array_keys($queueNames);

            // Cap discovered queues
            if (\count($queueNames) > $scanLimit) {
                $queueNames = \array_slice($queueNames, 0, $scanLimit);
            }
        } catch (Throwable) {
            return $this->getDriverInfo($this->defaultQueue);
        }

        if (\count($queueNames) === 0) {
            return $this->getDriverInfo($this->defaultQueue);
        }

        $totalReady = 0;
        $totalDelayed = 0;
        $totalReserved = 0;
        $queues = [];
        $queueCount = \count($queueNames);

        foreach ($queueNames as $queue) {
            try {
                $ready = $conn->llen($this->connection->key("queues:{$queue}"));
                $delayed = $conn->zcard($this->connection->key("queues:{$queue}:delayed"));
                $reserved = $conn->zcard($this->connection->key("queues:{$queue}:reserved"));
            } catch (Throwable) {
                continue;
            }

            $totalReady += $ready;
            $totalDelayed += $delayed;
            $totalReserved += $reserved;

            if (\count($queues) < 10) {
                $queues[$queue] = [
                    'size' => $ready + $delayed + $reserved,
                    'ready' => $ready,
                    'delayed' => $delayed,
                    'reserved' => $reserved,
                ];
            }
        }

        $info = [
            'driver' => 'redis',
            'size' => $totalReady + $totalDelayed + $totalReserved,
            'ready' => $totalReady,
            'delayed' => $totalDelayed,
            'reserved' => $totalReserved,
            'queues' => $queues,
            'queues_total' => $queueCount,
        ];

        $this->addRedisServerStats($info);

        return $info;
    }

    /**
     * Delete a reserved job.
     */
    public function deleteReserved(string $queue, string $payload): void
    {
        $this->connection->getConnection()->zrem(
            $this->connection->key("queues:{$queue}:reserved"),
            $payload,
        );
    }

    /**
     * Move job to failed queue.
     */
    public function moveToFailed(string $queue, string $payload): void
    {
        $conn = $this->connection->getConnection();

        // Remove from reserved
        $this->deleteReserved($queue, $payload);

        // Add to failed queue
        $conn->rpush(
            $this->connection->key("queues:{$queue}:failed"),
            $payload,
        );
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
     * Add Redis server stats (memory, clients, ops) to the info array.
     *
     * @param array<string, mixed> $info
     */
    private function addRedisServerStats(array &$info): void
    {
        try {
            $conn = $this->connection->getConnection();

            /** @var array<string, string> $memoryInfo */
            $memoryInfo = $conn->info('memory');
            $info['memory_used'] = (int) ($memoryInfo['used_memory'] ?? 0);
            $info['memory_peak'] = (int) ($memoryInfo['used_memory_peak'] ?? 0);

            /** @var array<string, string> $clientsInfo */
            $clientsInfo = $conn->info('clients');
            $info['connected_clients'] = (int) ($clientsInfo['connected_clients'] ?? 0);

            /** @var array<string, string> $statsInfo */
            $statsInfo = $conn->info('stats');
            $info['ops_per_sec'] = (int) ($statsInfo['instantaneous_ops_per_sec'] ?? 0);
        } catch (Throwable) {
            // Redis INFO may not be available in all configurations
        }
    }

    /**
     * Migrate delayed jobs that are ready.
     */
    private function migrateDelayedJobs(string $queue): void
    {
        $conn = $this->connection->getConnection();
        $now = time();

        // Get jobs that are ready
        $jobs = $conn->zrangebyscore(
            $this->connection->key("queues:{$queue}:delayed"),
            '-inf',
            (string) $now,
            ['limit' => [0, 100]],
        );

        foreach ($jobs as $payload) {
            // Remove from delayed
            $removed = $conn->zrem(
                $this->connection->key("queues:{$queue}:delayed"),
                $payload,
            );

            // Push to main queue if removed successfully
            if ($removed) {
                $conn->rpush($this->connection->key("queues:{$queue}"), $payload);
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
