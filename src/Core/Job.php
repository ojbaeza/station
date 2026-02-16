<?php

declare(strict_types=1);

namespace Station\Core;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Ramsey\Uuid\Uuid;
use Station\Enums\JobStatus;

/**
 * @implements Arrayable<string, mixed>
 */
final class Job implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $jobClass,
        public readonly string $payload,
        public string $status = JobStatus::Pending->value,
        public int $attempts = 0,
        public int $maxTries = 3,
        public int $timeout = 60,
        public int $priority = 0,
        public ?string $batchId = null,
        /** @var array<int, string> */
        public array $tags = [],
        public ?string $workerId = null,
        public ?int $memoryUsed = null,
        public ?int $processingTime = null,
        public ?CarbonImmutable $availableAt = null,
        public ?CarbonImmutable $reservedAt = null,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $completedAt = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?string $connection = null,
        public ?string $exception = null,
    ) {}

    /**
     * Create a new job instance for a dispatchable job.
     */
    public static function create(
        object $job,
        string $queue,
        ?CarbonImmutable $delay = null,
        ?string $batchId = null,
    ): self {
        $id = Uuid::uuid7()->toString();
        $jobClass = $job::class;
        $payload = serialize($job);

        $maxTries = 3;
        if (property_exists($job, 'tries')) {
            $maxTries = (int) $job->tries;
        }

        $timeout = 60;
        if (property_exists($job, 'timeout')) {
            $timeout = (int) $job->timeout;
        }

        $tags = [];
        if (method_exists($job, 'tags')) {
            $tags = $job->tags();
        }

        return new self(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: $payload,
            maxTries: $maxTries,
            timeout: $timeout,
            tags: $tags,
            batchId: $batchId,
            availableAt: $delay,
            createdAt: CarbonImmutable::now(),
            updatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Create a job instance from a database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            queue: $row['queue'],
            jobClass: $row['job_class'],
            payload: $row['payload'],
            status: $row['status'] ?? JobStatus::Pending->value,
            attempts: (int) ($row['attempts'] ?? 0),
            maxTries: (int) ($row['max_tries'] ?? 3),
            timeout: (int) ($row['timeout'] ?? 60),
            priority: (int) ($row['priority'] ?? 0),
            batchId: $row['batch_id'] ?? null,
            tags: isset($row['tags']) ? (\is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags']) : [],
            workerId: $row['worker_id'] ?? null,
            memoryUsed: isset($row['memory_used']) ? (int) $row['memory_used'] : null,
            processingTime: isset($row['processing_time']) ? (int) $row['processing_time'] : null,
            availableAt: isset($row['available_at']) ? CarbonImmutable::parse($row['available_at']) : null,
            reservedAt: isset($row['reserved_at']) ? CarbonImmutable::parse($row['reserved_at']) : null,
            startedAt: isset($row['started_at']) ? CarbonImmutable::parse($row['started_at']) : null,
            completedAt: isset($row['completed_at']) ? CarbonImmutable::parse($row['completed_at']) : null,
            createdAt: isset($row['created_at']) ? CarbonImmutable::parse($row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? CarbonImmutable::parse($row['updated_at']) : null,
            connection: $row['connection'] ?? null,
            exception: $row['exception'] ?? null,
        );
    }

    /**
     * Get the unserialized job instance.
     */
    public function getJobInstance(): object
    {
        return unserialize($this->payload);
    }

    /**
     * Check if the job is available for processing.
     */
    public function isAvailable(): bool
    {
        if ($this->status !== JobStatus::Pending->value) {
            return false;
        }

        if ($this->availableAt === null) {
            return true;
        }

        return $this->availableAt->isPast();
    }

    /**
     * Check if the job has exceeded max attempts.
     */
    public function hasExceededMaxAttempts(): bool
    {
        return $this->attempts >= $this->maxTries;
    }

    /**
     * Get the job ID.
     */
    public function getId(): string
    {
        return $this->id;
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
            'queue' => $this->queue,
            'connection' => $this->connection,
            'job_class' => $this->jobClass,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_tries' => $this->maxTries,
            'timeout' => $this->timeout,
            'priority' => $this->priority,
            'batch_id' => $this->batchId,
            'tags' => $this->tags,
            'worker_id' => $this->workerId,
            'memory_used' => $this->memoryUsed,
            'processing_time' => $this->processingTime,
            'available_at' => $this->availableAt?->toDateTimeString(),
            'reserved_at' => $this->reservedAt?->toDateTimeString(),
            'started_at' => $this->startedAt?->toDateTimeString(),
            'completed_at' => $this->completedAt?->toDateTimeString(),
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
            'name' => $this->jobClass,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_tries' => $this->maxTries,
            'timeout' => $this->timeout,
            'priority' => $this->priority,
            'batch_id' => $this->batchId,
            'tags' => $this->tags,
            'worker_id' => $this->workerId,
            'processing_time' => $this->processingTime,
            'available_at' => $this->availableAt?->toIso8601String(),
            'reserved_at' => $this->reservedAt?->toIso8601String(),
            'started_at' => $this->startedAt?->toIso8601String(),
            'completed_at' => $this->completedAt?->toIso8601String(),
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'exception' => $this->exception,
            'failed_at' => $this->status === JobStatus::Failed->value ? $this->completedAt?->toIso8601String() : null,
        ];
    }
}
