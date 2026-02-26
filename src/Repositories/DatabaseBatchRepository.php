<?php

declare(strict_types=1);

namespace Station\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch as LaravelBatch;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Station\Contracts\BatchRepositoryInterface;
use Station\Core\Batch;
use Station\Enums\BatchStatus;

final class DatabaseBatchRepository implements BatchRepositoryInterface
{
    private string $table;

    public function __construct(
        private readonly ConnectionInterface $connection,
        string $tablePrefix = 'station_',
    ) {
        $this->table = $tablePrefix . 'batches';
    }

    public function store(Batch $batch): void
    {
        $this->connection->table($this->table)->insert($batch->toArray());
    }

    public function find(string $id): ?Batch
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return Batch::fromArray((array) $row);
    }

    public function update(Batch $batch): void
    {
        $data = $batch->toArray();
        $data['updated_at'] = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)
            ->where('id', $batch->id)
            ->update($data);
    }

    public function delete(string $id): void
    {
        $this->connection->table($this->table)->where('id', $id)->delete();
    }

    public function getByStatus(string $status, int $limit = 100): Collection
    {
        return $this->connection->table($this->table)
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(static fn($row): Batch => Batch::fromArray((array) $row));
    }

    public function getActive(): Collection
    {
        return $this->connection->table($this->table)
            ->whereIn('status', [BatchStatus::Pending->value, BatchStatus::Processing->value])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(static fn($row): Batch => Batch::fromArray((array) $row));
    }

    public function getRecent(int $limit = 10): Collection
    {
        return $this->connection->table($this->table)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(static fn($row): Batch => Batch::fromArray((array) $row));
    }

    public function syncFromLaravel(string $id, LaravelBatch $laravelBatch): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'total_jobs' => $laravelBatch->totalJobs,
                'pending_jobs' => $laravelBatch->pendingJobs,
                'processed_jobs' => $laravelBatch->totalJobs - $laravelBatch->pendingJobs,
                'failed_jobs' => $laravelBatch->failedJobs,
                'failed_job_ids' => json_encode($laravelBatch->failedJobIds),
                'updated_at' => $now,
            ]);
    }

    public function incrementProcessed(string $id): int
    {
        $now = CarbonImmutable::now()->toDateTimeString();
        $clampExpr = $this->clampPendingExpression();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'processed_jobs' => $this->connection->raw('processed_jobs + 1'),
                'pending_jobs' => $this->connection->raw($clampExpr),
                'updated_at' => $now,
            ]);

        return (int) $this->connection->table($this->table)
            ->where('id', $id)
            ->value('pending_jobs');
    }

    public function incrementFailed(string $id, string $jobId): int
    {
        $now = CarbonImmutable::now()->toDateTimeString();
        $clampExpr = $this->clampPendingExpression();

        // Atomic counter increment only — no lock, no JSON manipulation.
        // failed_job_ids is populated lazily from Laravel's batch at finish time.
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'failed_jobs' => $this->connection->raw('failed_jobs + 1'),
                'processed_jobs' => $this->connection->raw('processed_jobs + 1'),
                'pending_jobs' => $this->connection->raw($clampExpr),
                'updated_at' => $now,
            ]);

        return (int) $this->connection->table($this->table)
            ->where('id', $id)
            ->value('pending_jobs');
    }

    public function markAsStarted(string $id): void
    {
        $now = CarbonImmutable::now();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->where('status', BatchStatus::Pending->value)
            ->update([
                'status' => BatchStatus::Processing->value,
                'started_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);
    }

    public function markAsProcessing(string $id): void
    {
        $now = CarbonImmutable::now();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => BatchStatus::Processing->value,
                'finished_at' => null,
                'cancelled_at' => null,
                'updated_at' => $now->toDateTimeString(),
            ]);
    }

    public function markAsFinished(string $id, string $status): bool
    {
        $now = CarbonImmutable::now();

        $affected = $this->connection->table($this->table)
            ->where('id', $id)
            ->whereNotIn('status', [
                BatchStatus::Completed->value,
                BatchStatus::Failed->value,
                BatchStatus::Cancelled->value,
            ])
            ->update([
                'status' => $status,
                'finished_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

        return $affected > 0;
    }

    public function cancel(string $id): void
    {
        $now = CarbonImmutable::now();

        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => BatchStatus::Cancelled->value,
                'cancelled_at' => $now->toDateTimeString(),
                'finished_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);
    }

    public function prune(int $completedHours, int $cancelledHours, int $failedHours): int
    {
        $now = CarbonImmutable::now();
        $deleted = 0;

        // Prune completed batches
        $deleted += $this->connection->table($this->table)
            ->where('status', BatchStatus::Completed->value)
            ->where('finished_at', '<', $now->subHours($completedHours)->toDateTimeString())
            ->delete();

        // Prune cancelled batches
        $deleted += $this->connection->table($this->table)
            ->where('status', BatchStatus::Cancelled->value)
            ->where('cancelled_at', '<', $now->subHours($cancelledHours)->toDateTimeString())
            ->delete();

        // Prune failed batches
        $deleted += $this->connection->table($this->table)
            ->where('status', BatchStatus::Failed->value)
            ->where('finished_at', '<', $now->subHours($failedHours)->toDateTimeString())
            ->delete();

        return $deleted;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $query = $this->connection->table($this->table);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['connection'])) {
            $query->where('connection', $filters['connection']);
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $data = (clone $query)
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(static fn($row): Batch => Batch::fromArray((array) $row));

        return $this->formatPaginationResult($data, $total, $page, $perPage);
    }

    public function retry(string $id): int
    {
        $batch = $this->find($id);

        if ($batch === null) {
            return 0;
        }

        $now = CarbonImmutable::now();

        // Reset batch status
        $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => BatchStatus::Pending->value,
                'failed_jobs' => 0,
                'failed_job_ids' => json_encode([]),
                'started_at' => null,
                'finished_at' => null,
                'cancelled_at' => null,
                'updated_at' => $now->toDateTimeString(),
            ]);

        return \count($batch->failedJobIds);
    }

    /**
     * Format pagination result with Laravel-compatible metadata.
     *
     * @param Collection<int, Batch> $data
     * @return array<string, mixed>
     */
    private function formatPaginationResult(Collection $data, int $total, int $page, int $perPage): array
    {
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $from = $total > 0 ? (($page - 1) * $perPage) + 1 : null;
        $to = $total > 0 ? min($page * $perPage, $total) : null;

        // Build pagination links
        $links = [];

        // Previous link
        $links[] = [
            'url' => $page > 1 ? $this->buildPageUrl($page - 1) : null,
            'label' => '&laquo; Previous',
            'active' => false,
        ];

        // Page number links (limit to reasonable number)
        $startPage = max(1, $page - 2);
        $endPage = min($lastPage, $page + 2);

        if ($startPage > 1) {
            $links[] = ['url' => $this->buildPageUrl(1), 'label' => '1', 'active' => false];
            if ($startPage > 2) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $links[] = [
                'url' => $this->buildPageUrl($i),
                'label' => (string) $i,
                'active' => $i === $page,
            ];
        }

        if ($endPage < $lastPage) {
            if ($endPage < $lastPage - 1) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
            }
            $links[] = ['url' => $this->buildPageUrl($lastPage), 'label' => (string) $lastPage, 'active' => false];
        }

        // Next link
        $links[] = [
            'url' => $page < $lastPage ? $this->buildPageUrl($page + 1) : null,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
            'links' => $links,
            'prev_page_url' => $page > 1 ? $this->buildPageUrl($page - 1) : null,
            'next_page_url' => $page < $lastPage ? $this->buildPageUrl($page + 1) : null,
        ];
    }

    /**
     * Return a SQL expression that decrements pending_jobs but clamps at zero.
     * Uses CASE WHEN for cross-database compatibility (MySQL + SQLite).
     */
    private function clampPendingExpression(): string
    {
        return 'CASE WHEN pending_jobs > 0 THEN pending_jobs - 1 ELSE 0 END';
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
