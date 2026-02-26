<?php

declare(strict_types=1);

namespace Station\Core;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Collection;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\DTOs\JobStats;
use Station\Enums\JobStatus;
use Station\Events\JobDispatched;
use Station\Events\JobFailed;
use Station\Events\JobProcessed;
use Station\Events\JobRetrying;
use Throwable;

final class JobManager implements JobManagerInterface
{
    public function __construct(
        private readonly JobRepositoryInterface $repository,
        private readonly QueueFactory $queue,
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Create a pending dispatch for a job.
     */
    public function job(object $job): PendingDispatch
    {
        return new PendingDispatch($job, $this);
    }

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
    ): string {
        $queue ??= $this->getQueueForJob($job);

        // Merge tags from job class if available
        if (method_exists($job, 'tags')) {
            $tags = array_unique(array_merge($tags, $job->tags()));
        }

        // Enforce tag configuration
        try {
            $tagConfig = config('station.tags', []);
        } catch (Throwable) {
            $tagConfig = [];
        }

        if (!($tagConfig['enabled'] ?? true)) {
            $tags = [];
        } else {
            // Enforce max_length per tag
            $maxLength = (int) ($tagConfig['max_length'] ?? 100);
            $tags = array_map(static fn(string $t): string => substr($t, 0, $maxLength), $tags);

            // Apply auto_tags
            foreach ($tagConfig['auto_tags'] ?? [] as $autoTag) {
                $tags[] = match ($autoTag) {
                    'environment' => 'env:' . app()->environment(),
                    'queue' => 'queue:' . $queue,
                    'connection' => 'connection:' . ($connection ?? config('queue.default')),
                    default => $autoTag,
                };
            }

            $tags = array_values(array_unique($tags));

            // Enforce max_per_job
            $maxPerJob = (int) ($tagConfig['max_per_job'] ?? 10);
            $tags = \array_slice($tags, 0, $maxPerJob);
        }

        $stationJob = Job::create($job, $queue, $delay, $batchId);
        $stationJob->connection = $connection;
        $stationJob->tags = $tags;

        // Store in Station's job tracking
        $this->repository->store($stationJob);

        // Push to the actual queue driver
        $this->pushToQueue($stationJob, $job);

        $this->events->dispatch(new JobDispatched($stationJob));

        return $stationJob->id;
    }

    /**
     * Dispatch a job synchronously.
     */
    public function dispatchSync(object $job): void
    {
        dispatch_sync($job);
    }

    /**
     * Find a job by ID.
     */
    public function find(string $id): ?Job
    {
        return $this->repository->find($id);
    }

    /**
     * Delete a job.
     */
    public function delete(string $id): void
    {
        $this->repository->delete($id);
    }

    /**
     * Retry a failed job.
     */
    public function retry(string $id): bool
    {
        // First try to find in main jobs table
        $job = $this->repository->find($id);

        if ($job !== null && $job->status === JobStatus::Failed->value) {
            // Job exists in main table with failed status - reset and retry
            $job->status = JobStatus::Pending->value;
            $job->attempts = 0;
            $job->workerId = null;
            $job->reservedAt = null;
            $job->startedAt = null;
            $job->completedAt = null;

            $this->repository->update($job);
            $this->pushToQueue($job);
            $this->events->dispatch(new JobRetrying($job, 0, null, null));

            // Remove from failed_jobs table
            $this->repository->deleteFailed($id);

            return true;
        }

        // If not found in main table or not failed, check failed_jobs table
        $failedJob = $this->repository->findFailed($id);

        if ($failedJob === null) {
            return false;
        }

        // Update or create in main jobs table
        if ($job !== null) {
            // Job exists but with different status - reset it
            $job->status = JobStatus::Pending->value;
            $job->attempts = 0;
            $job->workerId = null;
            $job->reservedAt = null;
            $job->startedAt = null;
            $job->completedAt = null;

            $this->repository->update($job);
        } else {
            // Job doesn't exist in main table - store the failed job data
            $failedJob->status = JobStatus::Pending->value;
            $failedJob->attempts = 0;
            $failedJob->workerId = null;
            $failedJob->reservedAt = null;
            $failedJob->startedAt = null;
            $failedJob->completedAt = null;

            $this->repository->store($failedJob);
            $job = $failedJob;
        }

        // Re-push to queue
        $this->pushToQueue($job);
        $this->events->dispatch(new JobRetrying($job, 0, null, null));

        // Remove from failed_jobs table
        $this->repository->deleteFailed($id);

        return true;
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll(?string $queue = null): int
    {
        // Get jobs from the failed_jobs table
        $failedJobs = $this->repository->getFailed($queue);
        $count = 0;

        foreach ($failedJobs as $job) {
            if ($this->retry($job->id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Retry all failed jobs (alias for retryAll).
     */
    public function retryAllFailed(?string $queue = null): int
    {
        return $this->retryAll($queue);
    }

    /**
     * Cancel a job.
     */
    public function cancel(string $id): bool
    {
        $job = $this->repository->find($id);

        if ($job === null) {
            return false;
        }

        if ($job->status === JobStatus::Completed->value || $job->status === JobStatus::Failed->value) {
            return false;
        }

        $this->repository->delete($id);

        return true;
    }

    /**
     * Mark a job as completed.
     */
    public function complete(string $id, int $processingTime, int $memoryUsed): void
    {
        $job = $this->repository->find($id);

        if ($job === null) {
            return;
        }

        $this->repository->complete($id, $processingTime, $memoryUsed);

        $this->events->dispatch(new JobProcessed(
            $job,
            $job->workerId ?? 'unknown',
            $processingTime,
            $memoryUsed,
        ));
    }

    /**
     * Mark a job as failed.
     *
     * @param array<string, mixed> $context
     */
    public function fail(string $id, Throwable $exception, array $context = []): void
    {
        $job = $this->repository->find($id);

        if ($job === null) {
            return;
        }

        $willRetry = $job->attempts < $job->maxTries;

        $this->repository->fail($id, (string) $exception, $context);

        $this->events->dispatch(new JobFailed(
            $job,
            $exception,
            $job->attempts,
            $willRetry,
        ));
    }

    /**
     * Get jobs by status.
     *
     * @return Collection<int, Job>
     */
    public function getByStatus(string $status, ?string $queue = null, int $limit = 100): Collection
    {
        return $this->repository->getByStatus($status, $queue, $limit);
    }

    /**
     * Get recent jobs.
     *
     * @return Collection<int, Job>
     */
    public function getRecent(int $limit = 10, ?string $queue = null): Collection
    {
        return $this->repository->getRecent($limit, $queue);
    }

    /**
     * Get job statistics.
     */
    public function getStats(?string $queue = null): JobStats
    {
        return $this->repository->getStats($queue);
    }

    /**
     * Search jobs.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, Job>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): Collection
    {
        return $this->repository->search($filters, $limit, $offset);
    }

    /**
     * Count jobs.
     *
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        return $this->repository->count($filters);
    }

    /**
     * Prune completed jobs.
     */
    public function pruneCompleted(int $hours): int
    {
        return $this->repository->pruneCompleted($hours);
    }

    /**
     * Get the queue name for a job.
     */
    private function getQueueForJob(object $job): string
    {
        if (property_exists($job, 'queue') && $job->queue !== null) {
            return $job->queue;
        }

        return 'default';
    }

    /**
     * Push a job to the queue.
     *
     * Pushes the original job object so Laravel serializes it properly
     * via CallQueuedHandler with all properties intact.
     */
    private function pushToQueue(Job $stationJob, ?object $originalJob = null): void
    {
        $connection = $stationJob->connection ?? $this->config['default'] ?? 'rabbitmq';

        // Use the original job object, or unserialize from stored payload (for retries)
        $job = $originalJob ?? unserialize($stationJob->payload, ['allowed_classes' => true]);

        // Tag the job with Station's tracking ID so event listeners can find it
        if (\is_object($job)) {
            $job->stationJobId = $stationJob->id; // @phpstan-ignore property.notFound
        }

        if ($stationJob->availableAt !== null && $stationJob->availableAt->isFuture()) {
            $delay = (int) $stationJob->availableAt->diffInSeconds(CarbonImmutable::now());
            $this->queue->connection($connection)->later(
                $delay,
                $job,
                '',
                $stationJob->queue,
            );
        } else {
            $this->queue->connection($connection)->push(
                $job,
                '',
                $stationJob->queue,
            );
        }
    }
}
