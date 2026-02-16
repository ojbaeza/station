<?php

declare(strict_types=1);

namespace Station\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\DTOs\JobStats;
use Station\DTOs\PaginatedResult;
use Station\Enums\JobStatus;

final class DatabaseJobRepository implements JobRepositoryInterface
{
    private string $table;

    private string $failedTable;

    private string $eventsTable;

    public function __construct(
        private readonly ConnectionInterface $connection,
        string $tablePrefix = 'station_',
    ) {
        $this->table = $tablePrefix . 'jobs';
        $this->failedTable = $tablePrefix . 'failed_jobs';
        $this->eventsTable = $tablePrefix . 'job_events';
    }

    public function store(Job $job): void
    {
        $data = $job->toArray();
        $data['tags'] = json_encode($data['tags'] ?? []);
        $this->connection->table($this->table)->insert($data);
    }

    public function find(string $id): ?Job
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return Job::fromArray((array) $row);
    }

    public function update(Job $job): void
    {
        $data = $job->toArray();
        $data['tags'] = json_encode($data['tags'] ?? []);
        $data['updated_at'] = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)
            ->where('id', $job->id)
            ->update($data);
    }

    public function delete(string $id): void
    {
        $this->connection->table($this->table)->where('id', $id)->delete();
    }

    public function getByStatus(string $status, ?string $queue = null, int $limit = 100): Collection
    {
        $query = $this->connection->table($this->table)
            ->where('status', $status)
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit($limit);

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        return $query->get()->map(static fn($row): Job => Job::fromArray((array) $row));
    }

    public function getByQueue(string $queue, int $limit = 100): Collection
    {
        return $this->connection->table($this->table)
            ->where('queue', $queue)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(static fn($row): Job => Job::fromArray((array) $row));
    }

    public function getByBatchId(string $batchId): Collection
    {
        return $this->connection->table($this->table)
            ->where('batch_id', $batchId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(static fn($row): Job => Job::fromArray((array) $row));
    }

    public function getByTags(array $tags, int $limit = 100): Collection
    {
        $query = $this->connection->table($this->table);

        foreach ($tags as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(static fn($row): Job => Job::fromArray((array) $row));
    }

    public function reserve(string $queue, string $workerId): ?Job
    {
        $now = CarbonImmutable::now();

        // Find an available job
        $job = $this->connection->table($this->table)
            ->where('queue', $queue)
            ->where('status', JobStatus::Pending->value)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', $now->toDateTimeString());
            })
            ->orderBy('priority', 'desc')
            ->orderBy('available_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->first();

        if ($job === null) {
            return null;
        }

        // Try to reserve it (optimistic locking)
        $updated = $this->connection->table($this->table)
            ->where('id', $job->id)
            ->where('status', JobStatus::Pending->value)
            ->update([
                'status' => JobStatus::Reserved->value,
                'worker_id' => $workerId,
                'reserved_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

        if ($updated === 0) {
            // Someone else got it, try again
            return $this->reserve($queue, $workerId);
        }

        return Job::fromArray((array) $job);
    }

    public function complete(string $id, int $processingTime, int $memoryUsed): void
    {
        $now = CarbonImmutable::now();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => JobStatus::Completed->value,
                'processing_time' => $processingTime,
                'memory_used' => $memoryUsed,
                'completed_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);
    }

    public function fail(string $id, string $exception, array $context = []): void
    {
        $now = CarbonImmutable::now();

        $job = $this->find($id);

        if ($job === null) {
            return;
        }

        // Update job status
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => JobStatus::Failed->value,
                'completed_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

        // Store in failed jobs table
        $this->connection->table($this->failedTable)->insert([
            'id' => Uuid::uuid7()->toString(),
            'original_id' => $id,
            'queue' => $job->queue,
            'job_class' => $job->jobClass,
            'payload' => $job->payload,
            'exception' => $exception,
            'context' => json_encode($context),
            'batch_id' => $job->batchId,
            'tags' => json_encode($job->tags),
            'attempts' => $job->attempts,
            'failed_at' => $now->toDateTimeString(),
        ]);
    }

    public function release(string $id, int $delay = 0): void
    {
        $now = CarbonImmutable::now();
        $availableAt = $delay > 0 ? $now->addSeconds($delay) : null;

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => JobStatus::Pending->value,
                'worker_id' => null,
                'reserved_at' => null,
                'started_at' => null,
                'available_at' => $availableAt?->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);
    }

    public function getStuckJobs(int $timeout): Collection
    {
        $threshold = CarbonImmutable::now()->subSeconds($timeout);

        return $this->connection->table($this->table)
            ->where('status', JobStatus::Processing->value)
            ->where('started_at', '<', $threshold->toDateTimeString())
            ->get()
            ->map(static fn($row): Job => Job::fromArray((array) $row));
    }

    public function getStats(?string $queue = null): JobStats
    {
        $query = $this->connection->table($this->table)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status');

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        $results = $query->get()->pluck('count', 'status')->toArray();

        return new JobStats(
            pending: (int) ($results[JobStatus::Pending->value] ?? 0),
            processing: (int) ($results[JobStatus::Processing->value] ?? 0),
            completed: (int) ($results[JobStatus::Completed->value] ?? 0),
            failed: (int) ($results[JobStatus::Failed->value] ?? 0),
        );
    }

    public function getRecent(int $limit = 10, ?string $queue = null): Collection
    {
        $query = $this->connection->table($this->table)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        return $query->get()->map(static fn($row): Job => Job::fromArray((array) $row));
    }

    public function pruneCompleted(int $hours): int
    {
        $threshold = CarbonImmutable::now()->subHours($hours);

        return $this->connection->table($this->table)
            ->where('status', JobStatus::Completed->value)
            ->where('completed_at', '<', $threshold->toDateTimeString())
            ->delete();
    }

    public function search(array $filters, int $limit = 50, int $offset = 0): Collection
    {
        $query = $this->connection->table($this->table);

        if (isset($filters['queue'])) {
            $query->where('queue', $filters['queue']);
        }

        if (isset($filters['connection'])) {
            $query->where('connection', $filters['connection']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['job_class'])) {
            $query->where('job_class', 'like', '%' . $filters['job_class'] . '%');
        }

        if (isset($filters['batch_id'])) {
            $query->where('batch_id', $filters['batch_id']);
        }

        if (isset($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        if (isset($filters['since'])) {
            $query->where('created_at', '>=', $filters['since']);
        }

        if (isset($filters['until'])) {
            $query->where('created_at', '<=', $filters['until']);
        }

        if (isset($filters['search'])) {
            $query->where(static function ($q) use ($filters): void {
                $search = '%' . $filters['search'] . '%';
                $q->where('id', 'like', $search)
                    ->orWhere('job_class', 'like', $search);
            });
        }

        if (!empty($filters['exclude_classes'])) {
            $query->whereNotIn('job_class', $filters['exclude_classes']);
        }

        if (!empty($filters['only_classes'])) {
            $query->whereIn('job_class', $filters['only_classes']);
        }

        return $query->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(static fn($row): Job => Job::fromArray((array) $row));
    }

    public function count(array $filters = []): int
    {
        $query = $this->connection->table($this->table);

        if (isset($filters['queue'])) {
            $query->where('queue', $filters['queue']);
        }

        if (isset($filters['connection'])) {
            $query->where('connection', $filters['connection']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['job_class'])) {
            $query->where('job_class', 'like', '%' . $filters['job_class'] . '%');
        }

        if (isset($filters['batch_id'])) {
            $query->where('batch_id', $filters['batch_id']);
        }

        if (isset($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        if (isset($filters['search'])) {
            $query->where(static function ($q) use ($filters): void {
                $search = '%' . $filters['search'] . '%';
                $q->where('id', 'like', $search)
                    ->orWhere('job_class', 'like', $search);
            });
        }

        if (!empty($filters['exclude_classes'])) {
            $query->whereNotIn('job_class', $filters['exclude_classes']);
        }

        if (!empty($filters['only_classes'])) {
            $query->whereIn('job_class', $filters['only_classes']);
        }

        return $query->count();
    }

    public function getFailed(?string $queue = null, ?int $hours = null, int $limit = 50): Collection
    {
        $query = $this->connection->table($this->failedTable)
            ->orderBy('failed_at', 'desc')
            ->limit($limit);

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        if ($hours !== null) {
            $threshold = CarbonImmutable::now()->subHours($hours);
            $query->where('failed_at', '<', $threshold->toDateTimeString());
        }

        return $query->get()->map(static function ($row): Job {
            $data = (array) $row;

            // Map failed job columns back to Job format
            return Job::fromArray([
                'id' => $data['original_id'] ?? $data['id'],
                'job_class' => $data['job_class'],
                'queue' => $data['queue'],
                'payload' => $data['payload'],
                'status' => JobStatus::Failed->value,
                'attempts' => $data['attempts'] ?? 1,
                'batch_id' => $data['batch_id'],
                'tags' => \is_string($data['tags'] ?? null) ? json_decode($data['tags'], true) : ($data['tags'] ?? []),
                'created_at' => $data['failed_at'],
                'completed_at' => $data['failed_at'],
                'exception' => $data['exception'] ?? null,
            ]);
        });
    }

    public function flushFailed(?string $queue = null, ?int $hours = null): int
    {
        $query = $this->connection->table($this->failedTable);

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        if ($hours !== null) {
            $threshold = CarbonImmutable::now()->subHours($hours);
            $query->where('failed_at', '<', $threshold->toDateTimeString());
        }

        return $query->delete();
    }

    /**
     * @return array<string, JobStats>
     */
    public function getStatsByQueue(): array
    {
        $stats = [];

        $queues = $this->connection->table($this->table)
            ->select('queue')
            ->distinct()
            ->pluck('queue');

        foreach ($queues as $queue) {
            $stats[$queue] = $this->getStats($queue);
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 15): PaginatedResult
    {
        $total = $this->count($filters);
        $offset = ($page - 1) * $perPage;
        $data = $this->search($filters, $perPage, $offset);

        return $this->formatPaginationResult($data, $total, $page, $perPage);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getEvents(string $jobId): Collection
    {
        return $this->connection->table($this->eventsTable)
            ->where('job_id', $jobId)
            ->orderBy('occurred_at', 'asc')
            ->get()
            ->map(static fn($row) => (array) $row);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function paginateFailed(array $filters = [], int $page = 1, int $perPage = 15): PaginatedResult
    {
        $query = $this->connection->table($this->failedTable);

        if (isset($filters['queue'])) {
            $query->where('queue', $filters['queue']);
        }

        if (isset($filters['connection'])) {
            $query->where('connection', $filters['connection']);
        }

        if (isset($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        // Deduplicate: keep only the latest failure per original job (UUID7 is time-ordered)
        $table = $this->failedTable;
        $query->whereIn("{$table}.id", static function ($sub) use ($table, $filters): void {
            $sub->selectRaw('MAX(id)')
                ->from($table);
            if (isset($filters['queue'])) {
                $sub->where('queue', $filters['queue']);
            }
            if (isset($filters['connection'])) {
                $sub->where('connection', $filters['connection']);
            }
            if (isset($filters['tag'])) {
                $sub->whereJsonContains('tags', $filters['tag']);
            }
            $sub->groupByRaw('COALESCE(original_id, id)');
        });

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $data = (clone $query)
            ->orderBy('failed_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(static function ($row): Job {
                $data = (array) $row;

                return Job::fromArray([
                    'id' => $data['original_id'] ?? $data['id'],
                    'job_class' => $data['job_class'],
                    'queue' => $data['queue'],
                    'connection' => $data['connection'] ?? null,
                    'payload' => $data['payload'],
                    'status' => JobStatus::Failed->value,
                    'attempts' => $data['attempts'] ?? 1,
                    'batch_id' => $data['batch_id'],
                    'tags' => \is_string($data['tags'] ?? null) ? json_decode($data['tags'], true) : ($data['tags'] ?? []),
                    'created_at' => $data['failed_at'],
                    'completed_at' => $data['failed_at'],
                    'exception' => $data['exception'] ?? null,
                ]);
            });

        return $this->formatPaginationResult($data, $total, $page, $perPage);
    }

    public function findFailed(string $id): ?Job
    {
        $row = $this->connection->table($this->failedTable)
            ->where('original_id', $id)
            ->orWhere('id', $id)
            ->orderBy('failed_at', 'desc')
            ->first();

        if ($row === null) {
            return null;
        }

        $data = (array) $row;

        return Job::fromArray([
            'id' => $data['original_id'] ?? $data['id'],
            'job_class' => $data['job_class'],
            'queue' => $data['queue'],
            'connection' => $data['connection'] ?? null,
            'payload' => $data['payload'],
            'status' => JobStatus::Failed->value,
            'attempts' => $data['attempts'] ?? 1,
            'batch_id' => $data['batch_id'] ?? null,
            'tags' => \is_string($data['tags'] ?? null) ? json_decode($data['tags'], true) : ($data['tags'] ?? []),
            'created_at' => $data['failed_at'],
            'completed_at' => $data['failed_at'],
            'exception' => $data['exception'] ?? null,
        ]);
    }

    public function deleteFailed(string $id): void
    {
        $this->connection->table($this->failedTable)
            ->where('original_id', $id)
            ->orWhere('id', $id)
            ->delete();
    }

    /**
     * @return Collection<int, Job>
     */
    public function getByBatch(string $batchId): Collection
    {
        return $this->getByBatchId($batchId);
    }

    /**
     * @return array<int, string>
     */
    public function getQueues(): array
    {
        return $this->connection->table($this->table)
            ->select('queue')
            ->distinct()
            ->pluck('queue')
            ->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $tags
     */
    public function trackQueued(string $id, string $name, string $queue, string $connection, array $payload, ?string $batchId = null, array $tags = []): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)->insert([
            'id' => $id,
            'job_class' => $name,
            'queue' => $queue,
            'connection' => $connection,
            'payload' => json_encode($payload),
            'status' => JobStatus::Pending->value,
            'attempts' => 0,
            'max_tries' => $payload['maxTries'] ?? 3,
            'timeout' => $payload['timeout'] ?? 60,
            'batch_id' => $batchId,
            'tags' => json_encode($tags),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function trackProcessing(string $id, string $queue): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => JobStatus::Processing->value,
                'started_at' => $now,
                'attempts' => $this->connection->raw('attempts + 1'),
                'updated_at' => $now,
            ]);
    }

    public function trackCompleted(string $id): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => JobStatus::Completed->value,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function trackFailed(string $id, string $exception, array $context = []): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        // Update the job status
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => JobStatus::Failed->value,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        // Record in failed_jobs table.
        // Use context data when available to avoid a SELECT round-trip.
        if ($context !== []) {
            $this->connection->table($this->failedTable)->insert([
                'id' => Uuid::uuid7()->toString(),
                'original_id' => $id,
                'job_class' => $context['job_class'] ?? 'Unknown',
                'queue' => $context['queue'] ?? 'default',
                'connection' => $context['connection'] ?? null,
                'payload' => $context['payload'] ?? '{}',
                'exception' => $exception,
                'attempts' => $context['attempts'] ?? 1,
                'batch_id' => $context['batch_id'] ?? null,
                'tags' => json_encode($context['tags'] ?? []),
                'failed_at' => $now,
            ]);
        } else {
            // Fallback: read from station_jobs (for callers that don't provide context)
            $job = $this->connection->table($this->table)->where('id', $id)->first();

            if ($job !== null) {
                $this->connection->table($this->failedTable)->insert([
                    'id' => Uuid::uuid7()->toString(),
                    'original_id' => $id,
                    'job_class' => $job->job_class,
                    'queue' => $job->queue,
                    'connection' => $job->connection ?? null,
                    'payload' => $job->payload,
                    'exception' => $exception,
                    'attempts' => $job->attempts ?? 1,
                    'batch_id' => $job->batch_id,
                    'tags' => \is_string($job->tags) ? $job->tags : json_encode($job->tags ?? []),
                    'failed_at' => $now,
                ]);
            }
        }
    }

    /**
     * Get distinct tags from recent jobs.
     *
     * @return array<int, string>
     */
    public function getDistinctTags(int $limit = 100): array
    {
        $rows = $this->connection->table($this->table)
            ->whereNotNull('tags')
            ->where('tags', '!=', '[]')
            ->orderBy('created_at', 'desc')
            ->limit(1000)
            ->pluck('tags');

        $tags = [];

        foreach ($rows as $tagJson) {
            $decoded = \is_string($tagJson) ? json_decode($tagJson, true) : $tagJson;

            if (\is_array($decoded)) {
                foreach ($decoded as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        $result = array_keys($tags);
        sort($result);

        return \array_slice($result, 0, $limit);
    }

    /**
     * Add a tag to an existing job.
     */
    public function addTag(string $id, string $tag): void
    {
        $job = $this->find($id);

        if ($job === null) {
            return;
        }

        $tags = array_unique([...$job->tags, $tag]);
        $tags = array_values($tags);

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'tags' => json_encode($tags),
                'updated_at' => CarbonImmutable::now()->toDateTimeString(),
            ]);
    }

    /**
     * Remove a tag from an existing job.
     */
    public function removeTag(string $id, string $tag): void
    {
        $job = $this->find($id);

        if ($job === null) {
            return;
        }

        $tags = array_values(array_filter($job->tags, static fn(string $t): bool => $t !== $tag));

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'tags' => json_encode($tags),
                'updated_at' => CarbonImmutable::now()->toDateTimeString(),
            ]);
    }

    /**
     * Format pagination result with Laravel-compatible metadata.
     *
     * @param Collection<int, Job> $data
     */
    private function formatPaginationResult(Collection $data, int $total, int $page, int $perPage): PaginatedResult
    {
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $from = $total > 0 ? (($page - 1) * $perPage) + 1 : null;
        $to = $total > 0 ? min($page * $perPage, $total) : null;
        $urlBuilder = fn(int $p): string => $this->buildPageUrl($p);

        return new PaginatedResult(
            data: $data,
            total: $total,
            per_page: $perPage,
            current_page: $page,
            last_page: $lastPage,
            from: $from,
            to: $to,
            links: PaginatedResult::buildLinks($page, $lastPage, $urlBuilder),
            prev_page_url: $page > 1 ? $this->buildPageUrl($page - 1) : null,
            next_page_url: $page < $lastPage ? $this->buildPageUrl($page + 1) : null,
        );
    }

    /**
     * Build a URL for a specific page.
     */
    private function buildPageUrl(int $page): string
    {
        $query = request()->query();
        $query['page'] = $page;

        return request()->url() . '?' . http_build_query($query);
    }
}
