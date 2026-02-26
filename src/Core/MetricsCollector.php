<?php

declare(strict_types=1);

namespace Station\Core;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Station\Contracts\MetricsCollectorInterface;
use Station\Contracts\MetricsRepositoryInterface;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\MetricsSnapshot;
use Station\DTOs\PaginatedResult;
use Station\DTOs\QueueStats;
use Station\DTOs\TimeSeriesPoint;
use Station\Enums\MetricsPeriod;

final class MetricsCollector implements MetricsCollectorInterface
{
    private const BUFFER_FLUSH_SIZE = 50;

    /** @var array<int, array{queue: string, metrics: array{jobs_processed: int, jobs_failed: int, jobs_pending: int, avg_processing_time: int, avg_wait_time: int, peak_memory: int, active_workers: int}, connection: ?string}> */
    private static array $buffer = [];

    public function __construct(
        private readonly MetricsRepositoryInterface $repository,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Reset the static buffer (call after fork to avoid parent state leaking into child).
     */
    public static function resetBuffer(): void
    {
        self::$buffer = [];
    }

    /**
     * Check if metrics collection is enabled.
     */
    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? true)
            && ($this->config['metrics']['enabled'] ?? true);
    }

    /**
     * Record metrics for a queue.
     */
    public function record(
        string $queue,
        int $jobsProcessed,
        int $jobsFailed,
        int $jobsPending,
        int $avgProcessingTime,
        int $avgWaitTime,
        int $peakMemory,
        int $activeWorkers,
        ?string $connection = null,
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        // Apply sampling rate
        $sampleRate = $this->config['metrics']['sample_rate'] ?? 100;
        if ($sampleRate < 100 && random_int(1, 100) > $sampleRate) {
            return;
        }

        $this->repository->record($queue, [
            'jobs_processed' => $jobsProcessed,
            'jobs_failed' => $jobsFailed,
            'jobs_pending' => $jobsPending,
            'avg_processing_time' => $avgProcessingTime,
            'avg_wait_time' => $avgWaitTime,
            'peak_memory' => $peakMemory,
            'active_workers' => $activeWorkers,
        ], $connection);
    }

    /**
     * Record a job completion (buffered, sampled).
     */
    public function recordJobCompletion(
        string $queue,
        int $processingTime,
        int $waitTime,
        int $memoryUsed,
        ?string $connection = null,
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        if (!$this->passesSampling()) {
            return;
        }

        self::$buffer[] = [
            'queue' => $queue,
            'metrics' => [
                'jobs_processed' => 1,
                'jobs_failed' => 0,
                'jobs_pending' => 0,
                'avg_processing_time' => max(0, $processingTime),
                'avg_wait_time' => max(0, $waitTime),
                'peak_memory' => max(0, $memoryUsed),
                'active_workers' => 0,
            ],
            'connection' => $connection,
        ];

        if (\count(self::$buffer) >= self::BUFFER_FLUSH_SIZE) {
            $this->flush();
        }
    }

    /**
     * Record a job failure (buffered, sampled).
     */
    public function recordJobFailure(string $queue, ?string $connection = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!$this->passesSampling()) {
            return;
        }

        self::$buffer[] = [
            'queue' => $queue,
            'metrics' => [
                'jobs_processed' => 0,
                'jobs_failed' => 1,
                'jobs_pending' => 0,
                'avg_processing_time' => 0,
                'avg_wait_time' => 0,
                'peak_memory' => 0,
                'active_workers' => 0,
            ],
            'connection' => $connection,
        ];

        if (\count(self::$buffer) >= self::BUFFER_FLUSH_SIZE) {
            $this->flush();
        }
    }

    /**
     * Flush buffered metrics to storage.
     */
    public function flush(): void
    {
        if (self::$buffer === []) {
            return;
        }

        $entries = self::$buffer;
        self::$buffer = [];

        $this->repository->recordBatch($entries);
    }

    /**
     * Get metrics for a time range.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getForRange(
        string $queue,
        DateTimeInterface $start,
        DateTimeInterface $end,
    ): Collection {
        return $this->repository->getForRange($queue, $start, $end);
    }

    /**
     * Get aggregated metrics for a queue.
     */
    public function getAggregated(string $queue, int $minutes = 60): MetricsAggregation
    {
        return $this->repository->getAggregated($queue, $minutes);
    }

    /**
     * Get current metrics snapshot.
     */
    public function getSnapshot(): MetricsSnapshot
    {
        return $this->repository->getSnapshot();
    }

    /**
     * Get queue-specific stats.
     *
     * @param list<string> $additionalQueues Extra queue names to include (e.g. from configured connections)
     * @return array<string, QueueStats>
     */
    public function getQueueStats(array $additionalQueues = []): array
    {
        return $this->repository->getQueueStats($additionalQueues);
    }

    /**
     * Get stats suitable for the dashboard overview.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $snapshot = $this->getSnapshot();
        $queueStats = $this->getQueueStats();

        return [
            'jobs_per_minute' => $snapshot->jobs_per_minute,
            'jobs_processed_last_hour' => $snapshot->jobs_processed_last_hour,
            'failed_jobs' => $snapshot->failed_jobs,
            'failed_rate_percent' => $snapshot->failed_rate_percent,
            'average_processing_time_ms' => $snapshot->average_processing_time_ms,
            'active_workers' => $snapshot->active_workers,
            'pending_jobs' => $snapshot->pending_jobs,
            'queues' => $queueStats,
        ];
    }

    /**
     * Prune old metrics.
     */
    public function prune(int $hours): int
    {
        return $this->repository->prune($hours);
    }

    /**
     * Get throughput (jobs per minute).
     */
    public function getThroughput(?string $queue = null): float
    {
        $snapshot = $this->getSnapshot();

        return $snapshot->jobs_per_minute;
    }

    /**
     * Get average wait time in seconds.
     */
    public function getAverageWaitTime(?string $queue = null): float
    {
        if ($queue !== null) {
            $aggregated = $this->getAggregated($queue);

            return $aggregated->avg_wait_time;
        }

        // Compute weighted average across all queues
        $queueStats = $this->getQueueStats();
        $totalWeight = 0;
        $weightedSum = 0.0;

        foreach ($queueStats as $queueName => $stats) {
            $aggregated = $this->getAggregated($queueName);
            $processed = $aggregated->jobs_processed;
            if ($processed > 0) {
                $weightedSum += $aggregated->avg_wait_time * $processed;
                $totalWeight += $processed;
            }
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
    }

    /**
     * Get average processing time in seconds.
     */
    public function getAverageProcessingTime(?string $queue = null): float
    {
        if ($queue !== null) {
            $aggregated = $this->getAggregated($queue);

            return $aggregated->avg_processing_time;
        }

        $snapshot = $this->getSnapshot();

        return $snapshot->average_processing_time_ms / 1000.0; // Convert ms to seconds
    }

    /**
     * Get failure rate (0.0 to 1.0).
     */
    public function getFailureRate(?string $queue = null): float
    {
        if ($queue !== null) {
            $aggregated = $this->getAggregated($queue);

            return $aggregated->failure_rate;
        }

        $snapshot = $this->getSnapshot();

        return $snapshot->failed_rate_percent / 100.0; // Convert percentage to rate
    }

    /**
     * Get metrics for a given period.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(string $period = '1h'): array
    {
        $minutes = $this->periodToMinutes($period);

        $snapshot = $this->getSnapshot();
        $queueStats = $this->getQueueStats();

        return [
            'period' => $period,
            'jobs_per_minute' => $snapshot->jobs_per_minute,
            'jobs_processed' => $snapshot->jobs_processed_last_hour,
            'failed_jobs' => $snapshot->failed_jobs,
            'failed_rate_percent' => $snapshot->failed_rate_percent,
            'average_processing_time_ms' => $snapshot->average_processing_time_ms,
            'active_workers' => $snapshot->active_workers,
            'pending_jobs' => $snapshot->pending_jobs,
            'queues' => $queueStats,
        ];
    }

    /**
     * Get historical metrics records for a period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistoricalMetrics(string $period = '1h', int $limit = 100): array
    {
        $minutes = $this->periodToMinutes($period);

        return $this->repository->getAllRecent($minutes, $limit);
    }

    /**
     * Get paginated historical metrics records for a period.
     */
    public function paginateHistoricalMetrics(string $period = '1h', int $page = 1, int $perPage = 25, ?string $connection = null): PaginatedResult
    {
        $minutes = $this->periodToMinutes($period);

        return $this->repository->paginateAllRecent($minutes, $page, $perPage, $connection);
    }

    /**
     * Get aggregated metrics for a specific period.
     */
    public function getAggregatedForPeriod(string $period = '1h', ?string $connection = null): MetricsAggregation
    {
        $minutes = $this->periodToMinutes($period);

        return $this->repository->getGlobalAggregated($minutes, $connection);
    }

    /**
     * Get time-series metrics for a period.
     *
     * @return array<int, TimeSeriesPoint>
     */
    public function getTimeSeries(string $period = '1h', int $buckets = 30, ?string $connection = null, ?string $queue = null): array
    {
        $minutes = $this->periodToMinutes($period);

        return $this->repository->getTimeSeries($minutes, $buckets, $connection, $queue);
    }

    /**
     * Check if this metric passes the sampling rate.
     */
    private function passesSampling(): bool
    {
        $sampleRate = $this->config['metrics']['sample_rate'] ?? 100;

        if ($sampleRate >= 100) {
            return true;
        }

        return random_int(1, 100) <= $sampleRate;
    }

    /**
     * Convert period string to minutes.
     */
    private function periodToMinutes(string $period): int
    {
        $metricsPeriod = MetricsPeriod::tryFrom($period);

        return $metricsPeriod?->toMinutes() ?? MetricsPeriod::OneHour->toMinutes();
    }
}
