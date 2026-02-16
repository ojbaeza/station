<?php

declare(strict_types=1);

namespace Station\Contracts;

use Illuminate\Support\Collection;
use Station\Core\Job;
use Station\DTOs\JobStats;
use Station\DTOs\PaginatedResult;

interface JobRepositoryInterface
{
    /**
     * Store a new job.
     */
    public function store(Job $job): void;

    /**
     * Find a job by ID.
     */
    public function find(string $id): ?Job;

    /**
     * Update a job.
     */
    public function update(Job $job): void;

    /**
     * Delete a job.
     */
    public function delete(string $id): void;

    /**
     * Get jobs by status.
     *
     * @return Collection<int, Job>
     */
    public function getByStatus(string $status, ?string $queue = null, int $limit = 100): Collection;

    /**
     * Get jobs by queue.
     *
     * @return Collection<int, Job>
     */
    public function getByQueue(string $queue, int $limit = 100): Collection;

    /**
     * Get jobs by batch ID.
     *
     * @return Collection<int, Job>
     */
    public function getByBatchId(string $batchId): Collection;

    /**
     * Get jobs by tags.
     *
     * @param array<string> $tags
     * @return Collection<int, Job>
     */
    public function getByTags(array $tags, int $limit = 100): Collection;

    /**
     * Reserve a job for processing.
     */
    public function reserve(string $queue, string $workerId): ?Job;

    /**
     * Mark a job as completed.
     */
    public function complete(string $id, int $processingTime, int $memoryUsed): void;

    /**
     * Mark a job as failed.
     */
    public function fail(string $id, string $exception, array $context = []): void;

    /**
     * Release a job back to the queue.
     */
    public function release(string $id, int $delay = 0): void;

    /**
     * Get stuck jobs (processing too long without heartbeat).
     *
     * @return Collection<int, Job>
     */
    public function getStuckJobs(int $timeout): Collection;

    /**
     * Get job statistics.
     */
    public function getStats(?string $queue = null): JobStats;

    /**
     * Get recent jobs.
     *
     * @return Collection<int, Job>
     */
    public function getRecent(int $limit = 10, ?string $queue = null): Collection;

    /**
     * Prune old completed jobs.
     */
    public function pruneCompleted(int $hours): int;

    /**
     * Search jobs.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, Job>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): Collection;

    /**
     * Count jobs matching filters.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int;

    /**
     * Get failed jobs.
     *
     * @return Collection<int, Job>
     */
    public function getFailed(?string $queue = null, ?int $hours = null, int $limit = 50): Collection;

    /**
     * Flush (delete) all failed jobs.
     */
    public function flushFailed(?string $queue = null, ?int $hours = null): int;

    /**
     * Get statistics grouped by queue.
     *
     * @return array<string, JobStats>
     */
    public function getStatsByQueue(): array;

    /**
     * Paginate jobs.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 15): PaginatedResult;

    /**
     * Get job events/history.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getEvents(string $jobId): Collection;

    /**
     * Paginate failed jobs.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateFailed(array $filters = [], int $page = 1, int $perPage = 15): PaginatedResult;

    /**
     * Find a failed job by ID.
     */
    public function findFailed(string $id): ?Job;

    /**
     * Delete a failed job.
     */
    public function deleteFailed(string $id): void;

    /**
     * Get jobs by batch ID (alias for getByBatchId).
     *
     * @return Collection<int, Job>
     */
    public function getByBatch(string $batchId): Collection;

    /**
     * Get list of all queue names.
     *
     * @return array<int, string>
     */
    public function getQueues(): array;

    /**
     * Track a job being queued (dispatched).
     *
     * @param array<string, mixed> $payload
     * @param array<int, string> $tags
     */
    public function trackQueued(string $id, string $name, string $queue, string $connection, array $payload, ?string $batchId = null, array $tags = []): void;

    /**
     * Track a job starting to process.
     */
    public function trackProcessing(string $id, string $queue): void;

    /**
     * Track a job completing successfully.
     */
    public function trackCompleted(string $id): void;

    /**
     * Track a job failing.
     *
     * @param array{job_class?: string, queue?: string, payload?: string, batch_id?: ?string, tags?: array<int, string>, attempts?: int, connection?: ?string} $context
     */
    public function trackFailed(string $id, string $exception, array $context = []): void;

    /**
     * Get distinct tags from recent jobs.
     *
     * @return array<int, string>
     */
    public function getDistinctTags(int $limit = 100): array;

    /**
     * Add a tag to an existing job.
     */
    public function addTag(string $id, string $tag): void;

    /**
     * Remove a tag from an existing job.
     */
    public function removeTag(string $id, string $tag): void;
}
