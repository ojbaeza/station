<?php

declare(strict_types=1);

namespace Station\Core;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch as LaravelBatch;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Station\Enums\BatchStatus;

/**
 * @implements Arrayable<string, mixed>
 */
final class Batch implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public ?string $name = null,
        public string $queue = 'default',
        public string $status = BatchStatus::Pending->value,
        public int $totalJobs = 0,
        public int $pendingJobs = 0,
        public int $processedJobs = 0,
        public int $failedJobs = 0,
        public int $allowedFailures = 0,
        /** @var array<int, string> */
        public array $failedJobIds = [],
        /** @var array<string, mixed>|null */
        public ?array $options = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $cancelledAt = null,
        public ?CarbonImmutable $finishedAt = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?string $connection = null,
    ) {}

    /**
     * Create a new batch.
     *
     * @param array<string, mixed> $options
     */
    public static function create(
        string $id,
        int $totalJobs,
        ?string $name = null,
        string $queue = 'default',
        int $allowedFailures = 0,
        array $options = [],
        ?string $connection = null,
    ): self {
        return new self(
            id: $id,
            name: $name,
            queue: $queue,
            totalJobs: $totalJobs,
            pendingJobs: $totalJobs,
            allowedFailures: $allowedFailures,
            options: $options,
            createdAt: CarbonImmutable::now(),
            updatedAt: CarbonImmutable::now(),
            connection: $connection,
        );
    }

    /**
     * Create a Station batch from a Laravel Bus batch.
     */
    public static function fromLaravelBatch(LaravelBatch $laravelBatch): self
    {
        return new self(
            id: $laravelBatch->id,
            name: $laravelBatch->name,
            totalJobs: $laravelBatch->totalJobs,
            pendingJobs: $laravelBatch->pendingJobs,
            processedJobs: $laravelBatch->totalJobs - $laravelBatch->pendingJobs,
            failedJobs: $laravelBatch->failedJobs,
            failedJobIds: $laravelBatch->failedJobIds,
            createdAt: $laravelBatch->createdAt ? CarbonImmutable::instance($laravelBatch->createdAt) : CarbonImmutable::now(), // @phpstan-ignore ternary.alwaysTrue
            finishedAt: $laravelBatch->finishedAt ? CarbonImmutable::instance($laravelBatch->finishedAt) : null,
            cancelledAt: $laravelBatch->cancelledAt ? CarbonImmutable::instance($laravelBatch->cancelledAt) : null,
        );
    }

    /**
     * Create a batch instance from a database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'] ?? null,
            queue: $row['queue'] ?? 'default',
            status: $row['status'] ?? BatchStatus::Pending->value,
            totalJobs: (int) ($row['total_jobs'] ?? 0),
            pendingJobs: (int) ($row['pending_jobs'] ?? 0),
            processedJobs: (int) ($row['processed_jobs'] ?? 0),
            failedJobs: (int) ($row['failed_jobs'] ?? 0),
            allowedFailures: (int) ($row['allowed_failures'] ?? 0),
            failedJobIds: isset($row['failed_job_ids'])
                ? (\is_string($row['failed_job_ids']) ? json_decode($row['failed_job_ids'], true) : $row['failed_job_ids'])
                : [],
            options: isset($row['options'])
                ? (\is_string($row['options']) ? json_decode($row['options'], true) : $row['options'])
                : null,
            startedAt: isset($row['started_at']) ? CarbonImmutable::parse($row['started_at']) : null,
            cancelledAt: isset($row['cancelled_at']) ? CarbonImmutable::parse($row['cancelled_at']) : null,
            finishedAt: isset($row['finished_at']) ? CarbonImmutable::parse($row['finished_at']) : null,
            createdAt: isset($row['created_at']) ? CarbonImmutable::parse($row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? CarbonImmutable::parse($row['updated_at']) : null,
            connection: $row['connection'] ?? null,
        );
    }

    /**
     * Get the progress percentage.
     */
    public function progress(): float
    {
        if ($this->totalJobs === 0) {
            return 0.0;
        }

        return round(($this->processedJobs / $this->totalJobs) * 100, 2);
    }

    /**
     * Check if the batch is finished.
     */
    public function finished(): bool
    {
        return $this->isFinished();
    }

    /**
     * Check if the batch is finished (alias).
     */
    public function isFinished(): bool
    {
        return \in_array($this->status, [
            BatchStatus::Completed->value,
            BatchStatus::Failed->value,
            BatchStatus::Cancelled->value,
        ], true);
    }

    /**
     * Check if the batch is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === BatchStatus::Cancelled->value;
    }

    /**
     * Check if the batch has exceeded allowed failures.
     */
    public function hasExceededAllowedFailures(): bool
    {
        return $this->failedJobs > $this->allowedFailures;
    }

    /**
     * Convert to array for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'status' => $this->status,
            'total_jobs' => $this->totalJobs,
            'pending_jobs' => $this->pendingJobs,
            'processed_jobs' => $this->processedJobs,
            'failed_jobs' => $this->failedJobs,
            'allowed_failures' => $this->allowedFailures,
            'failed_job_ids' => json_encode($this->failedJobIds),
            'options' => $this->options !== null ? json_encode($this->options) : null,
            'started_at' => $this->startedAt?->toDateTimeString(),
            'cancelled_at' => $this->cancelledAt?->toDateTimeString(),
            'finished_at' => $this->finishedAt?->toDateTimeString(),
            'created_at' => $this->createdAt?->toDateTimeString(),
            'updated_at' => $this->updatedAt?->toDateTimeString(),
        ];
    }

    /**
     * Convert to array for JSON serialization (API responses).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'status' => $this->status,
            'total_jobs' => $this->totalJobs,
            'pending_jobs' => $this->pendingJobs,
            'processed_jobs' => $this->processedJobs,
            'failed_jobs' => $this->failedJobs,
            'allowed_failures' => $this->allowedFailures,
            'progress_percent' => $this->progress(),
            'started_at' => $this->startedAt?->toIso8601String(),
            'cancelled_at' => $this->cancelledAt?->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
