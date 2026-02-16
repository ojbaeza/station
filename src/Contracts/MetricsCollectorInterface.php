<?php

declare(strict_types=1);

namespace Station\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\MetricsSnapshot;
use Station\DTOs\PaginatedResult;
use Station\DTOs\QueueStats;

interface MetricsCollectorInterface
{
    /**
     * Check if metrics collection is enabled.
     */
    public function isEnabled(): bool;

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
    ): void;

    /**
     * Record a job completion.
     */
    public function recordJobCompletion(
        string $queue,
        int $processingTime,
        int $waitTime,
        int $memoryUsed,
        ?string $connection = null,
    ): void;

    /**
     * Record a job failure.
     */
    public function recordJobFailure(string $queue, ?string $connection = null): void;

    /**
     * Flush any buffered metrics to storage.
     */
    public function flush(): void;

    /**
     * Get metrics for a time range.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getForRange(
        string $queue,
        DateTimeInterface $start,
        DateTimeInterface $end,
    ): Collection;

    /**
     * Get aggregated metrics for a queue.
     */
    public function getAggregated(string $queue, int $minutes = 60): MetricsAggregation;

    /**
     * Get current metrics snapshot.
     */
    public function getSnapshot(): MetricsSnapshot;

    /**
     * Get queue-specific stats.
     *
     * @param list<string> $additionalQueues Extra queue names to include (e.g. from configured connections)
     * @return array<string, QueueStats>
     */
    public function getQueueStats(array $additionalQueues = []): array;

    /**
     * Get stats suitable for the dashboard overview.
     *
     * @return array<string, mixed>
     */
    public function stats(): array;

    /**
     * Prune old metrics.
     */
    public function prune(int $hours): int;

    /**
     * Get throughput (jobs per minute).
     */
    public function getThroughput(?string $queue = null): float;

    /**
     * Get average wait time in seconds.
     */
    public function getAverageWaitTime(?string $queue = null): float;

    /**
     * Get average processing time in seconds.
     */
    public function getAverageProcessingTime(?string $queue = null): float;

    /**
     * Get failure rate (0.0 to 1.0).
     */
    public function getFailureRate(?string $queue = null): float;

    /**
     * Get metrics for a given period.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(string $period = '1h'): array;

    /**
     * Get historical metrics records for a period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistoricalMetrics(string $period = '1h', int $limit = 100): array;

    /**
     * Get paginated historical metrics records for a period.
     */
    public function paginateHistoricalMetrics(string $period = '1h', int $page = 1, int $perPage = 25, ?string $connection = null): PaginatedResult;

    /**
     * Get aggregated metrics for a specific period.
     */
    public function getAggregatedForPeriod(string $period = '1h', ?string $connection = null): MetricsAggregation;
}
