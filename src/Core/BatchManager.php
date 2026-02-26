<?php

declare(strict_types=1);

namespace Station\Core;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Station\Contracts\BatchRepositoryInterface;
use Station\Enums\BatchStatus;
use Station\Events\BatchCancelled;
use Station\Events\BatchCompleted;
use Station\Events\BatchCreated;
use Station\Events\BatchFailed;
use Station\Events\BatchProgress;

final class BatchManager
{
    public function __construct(
        private readonly BatchRepositoryInterface $repository,
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Create a new batch.
     *
     * @param array<int, object> $jobs
     * @param array<string, mixed> $options
     */
    public function create(
        array $jobs,
        ?string $name = null,
        string $queue = 'default',
        int $allowedFailures = 0,
        array $options = [],
        ?string $connection = null,
    ): Batch {
        $pendingBatch = Bus::batch($jobs)
            ->onQueue($queue)
            ->allowFailures();

        if ($name !== null) {
            $pendingBatch->name($name);
        }

        if ($connection !== null) {
            $pendingBatch->onConnection($connection);
        }

        $laravelBatch = $pendingBatch->dispatch();

        // Create Station batch record with the same ID as Laravel's batch
        $batch = Batch::create(
            id: $laravelBatch->id,
            totalJobs: \count($jobs),
            name: $name,
            queue: $queue,
            allowedFailures: $allowedFailures,
            options: $options,
            connection: $connection,
        );

        $this->repository->store($batch);

        // Mark batch as processing now that jobs are dispatched
        $this->repository->markAsStarted($batch->id);

        $this->events->dispatch(new BatchCreated($batch, \count($jobs), $options));

        // Return fresh batch with updated status
        return $this->repository->find($batch->id) ?? $batch;
    }

    /**
     * Find a batch by ID.
     */
    public function find(string $id): ?Batch
    {
        return $this->repository->find($id);
    }

    /**
     * Get active batches.
     *
     * @return Collection<int, Batch>
     */
    public function getActive(): Collection
    {
        return $this->repository->getActive();
    }

    /**
     * Get recent batches.
     *
     * @return Collection<int, Batch>
     */
    public function getRecent(int $limit = 10): Collection
    {
        return $this->repository->getRecent($limit);
    }

    /**
     * Get batches by status.
     *
     * @return Collection<int, Batch>
     */
    public function getByStatus(string $status, int $limit = 100): Collection
    {
        return $this->repository->getByStatus($status, $limit);
    }

    /**
     * Record a job completion within a batch.
     */
    public function recordJobCompletion(string $batchId): void
    {
        // Use atomic counters on station_batches directly.
        // We cannot rely on Bus::findBatch() here because Laravel fires
        // JobProcessed BEFORE updating job_batches counters.
        // Returns the remaining pending count to avoid a full SELECT on every job.
        $pendingJobs = $this->repository->incrementProcessed($batchId);

        // Only do the full SELECT + event dispatch when the batch is finishing.
        // This avoids hydrating a Batch object on every single job completion.
        if ($pendingJobs <= 0) {
            $batch = $this->repository->find($batchId);

            if ($batch === null) {
                return;
            }

            $this->events->dispatch(new BatchProgress(
                $batch,
                $batch->processedJobs,
                $batch->failedJobs,
                $batch->progress(),
            ));

            $this->finishBatch($batch);
        }
    }

    /**
     * Record a job failure within a batch.
     */
    public function recordJobFailure(string $batchId, string $jobId): void
    {
        // Use atomic counters on station_batches directly.
        // We cannot rely on Bus::findBatch() here because Laravel fires
        // JobFailed BEFORE updating job_batches counters.
        // Returns the remaining pending count to avoid a full SELECT on every job.
        $pendingJobs = $this->repository->incrementFailed($batchId, $jobId);

        // Only do the full SELECT when the batch needs a status decision.
        $batch = $this->repository->find($batchId);

        if ($batch === null) {
            return;
        }

        if ($batch->hasExceededAllowedFailures()) {
            $this->events->dispatch(new BatchProgress(
                $batch,
                $batch->processedJobs,
                $batch->failedJobs,
                $batch->progress(),
            ));

            $this->failBatch($batch);

            return;
        }

        if ($pendingJobs <= 0) {
            $this->events->dispatch(new BatchProgress(
                $batch,
                $batch->processedJobs,
                $batch->failedJobs,
                $batch->progress(),
            ));

            $this->finishBatch($batch);
        }
    }

    /**
     * Cancel a batch.
     */
    public function cancel(string $id): bool
    {
        $batch = $this->repository->find($id);

        if ($batch === null || $batch->isFinished()) {
            return false;
        }

        // Cancel via Laravel — jobs using Batchable trait will check cancelled()
        $laravelBatch = Bus::findBatch($id);
        $laravelBatch?->cancel();

        $this->repository->cancel($id);

        $batch = $this->repository->find($id);

        if ($batch !== null) {
            $this->events->dispatch(new BatchCancelled($batch));
        }

        return true;
    }

    /**
     * Retry failed jobs in a batch.
     */
    public function retryFailed(string $id): int
    {
        $laravelBatch = Bus::findBatch($id);

        if ($laravelBatch === null) {
            return 0;
        }

        $failedCount = \count($laravelBatch->failedJobIds);

        // Re-dispatch failed jobs via Laravel
        $laravelBatch->retry(); // @phpstan-ignore method.notFound (method exists at runtime since Laravel 11)

        // Reset Station batch status so dashboard shows it as active again
        $this->repository->markAsProcessing($id);

        return $failedCount;
    }

    /**
     * Prune old batches.
     */
    public function prune(): int
    {
        return $this->repository->prune(
            $this->config['pruning']['completed_after'] ?? 24,
            $this->config['pruning']['cancelled_after'] ?? 72,
            $this->config['pruning']['failed_after'] ?? 168,
        );
    }

    /**
     * Finish a batch (completed or with some failures within allowed limit).
     */
    private function finishBatch(Batch $batch): void
    {
        $status = $batch->failedJobs > $batch->allowedFailures
            ? BatchStatus::Failed->value
            : BatchStatus::Completed->value;

        if (!$this->repository->markAsFinished($batch->id, $status)) {
            return; // Already finished by another worker
        }

        $batch = $this->repository->find($batch->id);

        if ($batch === null) {
            return;
        }

        if ($status === BatchStatus::Completed->value) {
            $duration = $batch->finishedAt && $batch->startedAt
                ? (int) $batch->finishedAt->diffInSeconds($batch->startedAt)
                : 0;

            $this->events->dispatch(new BatchCompleted(
                $batch,
                $duration,
                $batch->processedJobs,
            ));
        } else {
            // Lazy-sync failed_job_ids from Laravel for the event
            $laravelBatch = Bus::findBatch($batch->id);
            $failedJobIds = $laravelBatch !== null ? $laravelBatch->failedJobIds : [];

            $this->events->dispatch(new BatchFailed(
                $batch,
                $failedJobIds,
                null,
            ));
        }
    }

    /**
     * Fail a batch (exceeded allowed failures threshold).
     */
    private function failBatch(Batch $batch): void
    {
        if (!$this->repository->markAsFinished($batch->id, BatchStatus::Failed->value)) {
            return; // Already finished by another worker
        }

        // Cancel remaining jobs via Laravel
        $laravelBatch = Bus::findBatch($batch->id);
        $laravelBatch?->cancel();

        $batch = $this->repository->find($batch->id);

        if ($batch !== null) {
            // Lazy-sync failed_job_ids from Laravel for the event
            $failedJobIds = $laravelBatch !== null ? $laravelBatch->failedJobIds : [];

            $this->events->dispatch(new BatchFailed(
                $batch,
                $failedJobIds,
                null,
            ));
        }
    }
}
