<?php

declare(strict_types=1);

namespace Station\Contracts;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\MetricsSnapshot;
use Station\DTOs\PaginatedResult;
use Station\DTOs\QueueStats;
use Station\DTOs\TimeSeriesPoint;

interface MetricsRepositoryInterface
{
    /**
     * Record metrics for a queue.
     *
     * @param array{
     *     jobs_processed: int,
     *     jobs_failed: int,
     *     jobs_pending: int,
     *     avg_processing_time: int,
     *     avg_wait_time: int,
     *     peak_memory: int,
     *     active_workers: int
     * } $metrics
     */
    public function record(string $queue, array $metrics, ?string $connection = null): void;

    /**
     * Get metrics for a time range.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getForRange(
        string $queue,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?string $connection = null,
    ): Collection;

    /**
     * Get aggregated metrics.
     */
    public function getAggregated(string $queue, int $minutes = 60, ?string $connection = null): MetricsAggregation;

    /**
     * Get current snapshot.
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
     * Get recent metrics for a queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(string $queue, int $minutes, ?string $connection = null): array;

    /**
     * Get all recent metrics across all queues.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllRecent(int $minutes, int $limit = 100, ?string $connection = null): array;

    /**
     * Paginate all recent metrics across all queues.
     */
    public function paginateAllRecent(int $minutes, int $page = 1, int $perPage = 25, ?string $connection = null): PaginatedResult;

    /**
     * Get global aggregated metrics for a period (across all queues).
     */
    public function getGlobalAggregated(int $minutes = 60, ?string $connection = null): MetricsAggregation;

    /**
     * Get time-series metrics grouped into time buckets.
     *
     * @return array<int, TimeSeriesPoint>
     */
    public function getTimeSeries(int $minutes, int $buckets = 30, ?string $connection = null, ?string $queue = null): array;

    /**
     * Record multiple metrics entries in a single bulk insert.
     *
     * @param array<int, array{queue: string, metrics: array{jobs_processed: int, jobs_failed: int, jobs_pending: int, avg_processing_time: int, avg_wait_time: int, peak_memory: int, active_workers: int}, connection: ?string}> $entries
     */
    public function recordBatch(array $entries): void;

    /**
     * Prune old metrics.
     */
    public function prune(int $hours): int;
}
