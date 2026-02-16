<?php

declare(strict_types=1);

namespace Station\Drivers\Beanstalkd;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Pheanstalk\Values\Job as PheanstalkJob;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\TubeName;
use Station\Contracts\AggregateDriverInfoInterface;
use Station\Contracts\DriverInterface;
use Station\Drivers\Traits\ChecksPauseStatus;
use Throwable;

final class BeanstalkdQueue extends Queue implements AggregateDriverInfoInterface, DriverInterface, QueueContract
{
    use ChecksPauseStatus;

    public function __construct(
        private readonly BeanstalkdConnection $connection,
        private readonly string $defaultQueue,
    ) {}

    /**
     * Get the size of the queue.
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);
        $client = $this->connection->getClient();

        try {
            $stats = $client->statsTube(new TubeName($queue));

            // TubeStats is a value object with properties in Pheanstalk 5.x
            return $stats->currentJobsReady
                + $stats->currentJobsReserved
                + $stats->currentJobsDelayed;
        } catch (Throwable) {
            return 0;
        }
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

        $priority = $options['priority'] ?? $this->connection->getDefaultPriority();
        $delay = $options['delay'] ?? 0;
        $ttr = $options['ttr'] ?? $this->connection->getTtr();

        // In Pheanstalk 5.x, useTube() returns void so we can't chain
        $client->useTube(new TubeName($queue));
        $job = $client->put($payload, $priority, $delay, $ttr);

        return (string) $job->getId();
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

        $delaySeconds = $this->secondsUntil($delay);

        // In Pheanstalk 5.x, useTube() returns void so we can't chain
        $client->useTube(new TubeName($queue));
        $job = $client->put(
            $payload,
            $this->connection->getDefaultPriority(),
            $delaySeconds,
            $this->connection->getTtr(),
        );

        return (string) $job->getId();
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

        try {
            // In Pheanstalk 5.x, watchOnly() doesn't exist, so we use watch() + ignore()
            $client->watch(new TubeName($queue));

            // Only ignore default tube if we're watching a different tube
            // (Beanstalkd requires at least one watched tube)
            if ($queue !== 'default') {
                $client->ignore(new TubeName('default'));
            }

            $timeout = max(0, $this->connection->getReserveTimeout());
            $job = $client->reserveWithTimeout($timeout);
        } catch (Throwable) {
            return null;
        }

        if ($job === null) {
            return null;
        }

        return new BeanstalkdJob(
            $this->container,
            $this,
            $job,
            $this->getConnectionName(),
            $queue,
        );
    }

    /**
     * Clear all jobs from a queue.
     */
    public function clear(string $queue): int
    {
        $client = $this->connection->getClient();
        $tube = new TubeName($queue);
        $count = 0;

        // Select the tube first (Pheanstalk 5.x returns void from useTube)
        $client->useTube($tube);

        // Delete ready jobs
        while (true) {
            try {
                $job = $client->peekReady();

                if ($job === null) {
                    break;
                }

                $client->delete($job);
                $count++;
            } catch (Throwable) {
                break;
            }
        }

        // Delete delayed jobs
        while (true) {
            try {
                $job = $client->peekDelayed();

                if ($job === null) {
                    break;
                }

                $client->delete($job);
                $count++;
            } catch (Throwable) {
                break;
            }
        }

        // Delete buried jobs
        while (true) {
            try {
                $job = $client->peekBuried();

                if ($job === null) {
                    break;
                }

                $client->delete($job);
                $count++;
            } catch (Throwable) {
                break;
            }
        }

        return $count;
    }

    /**
     * Pause a queue.
     */
    public function pause(string $queue): void
    {
        $client = $this->connection->getClient();
        $tube = new TubeName($queue);

        // Pause the tube for a long time (1 week)
        $client->pauseTube($tube, 604800);

        // Also track in database for consistency with other drivers
        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'beanstalkd'],
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
        $client = $this->connection->getClient();
        $tube = new TubeName($queue);

        // Resume by setting pause to 0
        $client->pauseTube($tube, 0);

        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'beanstalkd'],
            ['paused' => false, 'paused_at' => null, 'updated_at' => now()],
        );

        $this->pauseCache[$queue] = false;
        $this->pauseCacheTime[$queue] = microtime(true);
    }

    /**
     * Get buried jobs (dead letter equivalent).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBuriedJobs(string $queue, int $limit = 50): array
    {
        $client = $this->connection->getClient();
        $tube = new TubeName($queue);
        $jobs = [];

        // Kick buried jobs to ready state temporarily to peek them
        // Note: This is a workaround since Beanstalkd doesn't support listing buried jobs directly
        try {
            $stats = $client->statsTube($tube);
            // In Pheanstalk 5.x, TubeStats is a value object with properties
            $buriedCount = min($stats->currentJobsBuried, $limit);

            if ($buriedCount === 0) {
                return [];
            }

            // Select the tube first (Pheanstalk 5.x returns void from useTube)
            $client->useTube($tube);

            // Peek at the buried job (only gets one at a time)
            for ($i = 0; $i < $buriedCount; $i++) {
                try {
                    $job = $client->peekBuried();

                    if ($job === null) {
                        break;
                    }

                    $jobs[] = [
                        'id' => $job->getId(),
                        'body' => $job->getData(),
                    ];
                    // Kick to get the next one visible
                    $client->kickJob($job);
                    // Re-bury it - use watch() + ignore() instead of watchOnly()
                    $client->watch($tube);
                    if ($queue !== 'default') {
                        $client->ignore(new TubeName('default'));
                    }
                    $reservedJob = $client->reserveWithTimeout(1);
                    if ($reservedJob !== null) {
                        $client->bury($reservedJob);
                    }
                } catch (Throwable) {
                    break;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $jobs;
    }

    /**
     * Kick buried jobs back to ready state.
     */
    public function kickBuriedJobs(string $queue, int $count = 1): int
    {
        $client = $this->connection->getClient();

        try {
            $client->useTube(new TubeName($queue));

            return $client->kick($count);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Kick a specific buried job.
     */
    public function kickJob(string $queue, int $jobId): bool
    {
        $client = $this->connection->getClient();

        try {
            $job = new PheanstalkJob(new JobId($jobId), '');
            $client->kickJob($job);

            return true;
        } catch (Throwable) {
            return false;
        }
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
            // Use listTubes as a simple health check instead of stats()
            // stats() can fail with older Beanstalkd versions missing the 'draining' key
            $this->connection->getClient()->listTubes();
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
     * Delete a job.
     */
    public function deleteJob(PheanstalkJob $job): void
    {
        $this->connection->getClient()->delete($job);
    }

    /**
     * Release a job back onto the queue.
     */
    public function releaseJob(PheanstalkJob $job, int $priority, int $delay): void
    {
        $this->connection->getClient()->release($job, $priority, $delay);
    }

    /**
     * Bury a job.
     */
    public function buryJob(PheanstalkJob $job, int $priority): void
    {
        $this->connection->getClient()->bury($job, $priority);
    }

    /**
     * Touch a job to reset its TTR.
     */
    public function touchJob(PheanstalkJob $job): void
    {
        $this->connection->getClient()->touch($job);
    }

    /**
     * Get job stats.
     *
     * @return array<string, mixed>
     */
    public function getJobStats(PheanstalkJob $job): array
    {
        try {
            $stats = $this->connection->getClient()->statsJob($job);

            // Convert JobStats value object to array for backward compatibility
            return [
                'id' => $stats->id,
                'tube' => $stats->tube,
                'state' => $stats->state,
                'pri' => $stats->priority,
                'age' => $stats->age,
                'delay' => $stats->delay,
                'time-to-release' => $stats->timeToRelease,
                'time-left' => $stats->timeLeft,
                'file' => $stats->file,
                'reserves' => $stats->reserves,
                'timeouts' => $stats->timeouts,
                'releases' => $stats->releases,
                'buries' => $stats->buries,
                'kicks' => $stats->kicks,
                'ttr' => $stats->timeLeft + $stats->age, // Approximate TTR
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get driver-specific detailed info for metrics dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDriverInfo(string $queue): array
    {
        $client = $this->connection->getClient();

        $info = [
            'driver' => 'beanstalkd',
            'size' => $this->size($queue),
        ];

        try {
            $stats = $client->statsTube(new TubeName($queue));
            $info['ready'] = $stats->currentJobsReady;
            $info['reserved'] = $stats->currentJobsReserved;
            $info['delayed'] = $stats->currentJobsDelayed;
            $info['buried'] = $stats->currentJobsBuried;
            $info['total_jobs'] = $stats->totalJobs;
            $info['watchers'] = $stats->currentWatching;
        } catch (Throwable) {
            // Tube may not exist yet
        }

        return $info;
    }

    /**
     * Get driver info aggregated across all discoverable queues.
     *
     * @return array<string, mixed>
     */
    public function getAllDriverInfo(): array
    {
        $client = $this->connection->getClient();

        try {
            $tubes = $client->listTubes();
        } catch (Throwable) {
            return $this->getDriverInfo($this->defaultQueue);
        }

        $totalSize = 0;
        $totalReady = 0;
        $totalReserved = 0;
        $totalDelayed = 0;
        $totalBuried = 0;
        $totalJobs = 0;
        $totalWatchers = 0;
        $queues = [];
        $queueCount = 0;

        foreach ($tubes as $tube) {
            $tubeName = (string) $tube;

            try {
                $stats = $client->statsTube(new TubeName($tubeName));
            } catch (Throwable) {
                continue;
            }

            $ready = $stats->currentJobsReady;
            $reserved = $stats->currentJobsReserved;
            $delayed = $stats->currentJobsDelayed;
            $buried = $stats->currentJobsBuried;
            $watchers = $stats->currentWatching;
            $total = $stats->totalJobs;

            // Skip tubes with no activity
            if ($ready + $reserved + $delayed + $watchers === 0) {
                continue;
            }

            $size = $ready + $reserved + $delayed;
            $totalSize += $size;
            $totalReady += $ready;
            $totalReserved += $reserved;
            $totalDelayed += $delayed;
            $totalBuried += $buried;
            $totalJobs += $total;
            $totalWatchers += $watchers;
            $queueCount++;

            if (\count($queues) < 10) {
                $queues[$tubeName] = [
                    'size' => $size,
                    'ready' => $ready,
                    'reserved' => $reserved,
                    'delayed' => $delayed,
                    'buried' => $buried,
                    'total_jobs' => $total,
                    'watchers' => $watchers,
                ];
            }
        }

        return [
            'driver' => 'beanstalkd',
            'size' => $totalSize,
            'ready' => $totalReady,
            'reserved' => $totalReserved,
            'delayed' => $totalDelayed,
            'buried' => $totalBuried,
            'total_jobs' => $totalJobs,
            'watchers' => $totalWatchers,
            'queues' => $queues,
            'queues_total' => $queueCount,
        ];
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
     * Get the default priority.
     */
    public function getDefaultPriority(): int
    {
        return $this->connection->getDefaultPriority();
    }

    /**
     * Get the dead letter queue contents.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDeadLetterQueue(string $queue, int $limit = 50): array
    {
        // Beanstalkd uses buried jobs as the equivalent of a dead letter queue
        $buriedQueue = $queue . '.buried';
        $jobs = [];

        try {
            $client = $this->connection->getClient();
            $client->useTube(new TubeName($queue));

            for ($i = 0; $i < $limit; $i++) {
                $job = $client->peekBuried();

                if ($job === null) {
                    break;
                }

                $stats = $client->statsJob($job);

                $jobs[] = [
                    'id' => (string) $job->getId(),
                    'payload' => $job->getData(),
                    'buried_at' => null, // Beanstalkd doesn't track this
                    'reserves' => $stats->reserves,
                    'timeouts' => $stats->timeouts,
                    'releases' => $stats->releases,
                ];
            }
        } catch (Throwable) {
            // Queue may not exist or no buried jobs
        }

        return $jobs;
    }

    /**
     * Requeue a message from the dead letter queue.
     */
    public function requeueFromDeadLetter(string $queue, string $messageId): bool
    {
        try {
            $client = $this->connection->getClient();
            $job = new PheanstalkJob(new JobId((int) $messageId), '');

            $client->kickJob($job);

            return true;
        } catch (Throwable) {
            return false;
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
