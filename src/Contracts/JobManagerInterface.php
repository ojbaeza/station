<?php

declare(strict_types=1);

namespace Station\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Station\Core\Job;
use Station\Core\PendingDispatch;
use Station\DTOs\JobStats;
use Throwable;

interface JobManagerInterface
{
    /**
     * Create a pending dispatch for a job.
     */
    public function job(object $job): PendingDispatch;

    /**
     * Dispatch a job to the queue.
     *
     * @param array<int, string> $tags
     */
    public function dispatch(
        object $job,
        ?string $queue = null,
        ?CarbonImmutable $delay = null,
        ?string $batchId = null,
        array $tags = [],
        ?string $connection = null,
    ): string;

    /**
     * Dispatch a job synchronously.
     */
    public function dispatchSync(object $job): void;

    /**
     * Find a job by ID.
     */
    public function find(string $id): ?Job;

    /**
     * Delete a job.
     */
    public function delete(string $id): void;

    /**
     * Retry a failed job.
     */
    public function retry(string $id): bool;

    /**
     * Retry all failed jobs.
     */
    public function retryAll(?string $queue = null): int;

    /**
     * Mark a job as complete.
     */
    public function complete(string $id, int $processingTime, int $memoryUsed): void;

    /**
     * Mark a job as failed.
     *
     * @param array<string, mixed> $context
     */
    public function fail(string $id, Throwable $exception, array $context = []): void;

    /**
     * Get jobs by status.
     *
     * @return Collection<int, Job>
     */
    public function getByStatus(string $status, ?string $queue = null, int $limit = 100): Collection;

    /**
     * Get recent jobs.
     *
     * @return Collection<int, Job>
     */
    public function getRecent(int $limit = 10, ?string $queue = null): Collection;

    /**
     * Get job statistics.
     */
    public function getStats(?string $queue = null): JobStats;

    /**
     * Search jobs.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, Job>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): Collection;

    /**
     * Count jobs.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;

    /**
     * Prune completed jobs older than hours.
     */
    public function pruneCompleted(int $hours): int;

    /**
     * Cancel a job.
     */
    public function cancel(string $id): bool;

    /**
     * Retry all failed jobs.
     */
    public function retryAllFailed(?string $queue = null): int;
}
