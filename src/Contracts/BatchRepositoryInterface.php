<?php

declare(strict_types=1);

namespace Station\Contracts;

use Illuminate\Bus\Batch as LaravelBatch;
use Illuminate\Support\Collection;
use Station\Core\Batch;

interface BatchRepositoryInterface
{
    /**
     * Store a new batch.
     */
    public function store(Batch $batch): void;

    /**
     * Find a batch by ID.
     */
    public function find(string $id): ?Batch;

    /**
     * Update a batch.
     */
    public function update(Batch $batch): void;

    /**
     * Delete a batch.
     */
    public function delete(string $id): void;

    /**
     * Get batches by status.
     *
     * @return Collection<int, Batch>
     */
    public function getByStatus(string $status, int $limit = 100): Collection;

    /**
     * Get active batches (pending or processing).
     *
     * @return Collection<int, Batch>
     */
    public function getActive(): Collection;

    /**
     * Get recent batches.
     *
     * @return Collection<int, Batch>
     */
    public function getRecent(int $limit = 10): Collection;

    /**
     * Sync counters from a Laravel Bus batch into station_batches.
     */
    public function syncFromLaravel(string $id, LaravelBatch $laravelBatch): void;

    /**
     * Atomically increment processed count and decrement pending count.
     *
     * @return int The remaining pending jobs count after the increment.
     */
    public function incrementProcessed(string $id): int;

    /**
     * Atomically increment failed count and record the failed job ID.
     *
     * @return int The remaining pending jobs count after the increment.
     */
    public function incrementFailed(string $id, string $jobId): int;

    /**
     * Mark a batch as started (from pending).
     */
    public function markAsStarted(string $id): void;

    /**
     * Mark a batch as processing (from any status, e.g. after retry).
     */
    public function markAsProcessing(string $id): void;

    /**
     * Mark a batch as finished.
     */
    public function markAsFinished(string $id, string $status): void;

    /**
     * Cancel a batch.
     */
    public function cancel(string $id): void;

    /**
     * Prune old batches.
     */
    public function prune(int $completedHours, int $cancelledHours, int $failedHours): int;

    /**
     * Paginate batches.
     *
     * @param array<string, mixed> $filters
     * @return array{data: Collection<int, Batch>, total: int, page: int, per_page: int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 15): array;

    /**
     * Retry a batch.
     */
    public function retry(string $id): int;
}
