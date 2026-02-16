<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class MetricsSnapshot implements JsonSerializable
{
    public function __construct(
        public float $jobs_per_minute,
        public int $jobs_processed_last_hour,
        public int $failed_jobs,
        public float $failed_rate_percent,
        public int $average_processing_time_ms,
        public int $active_workers,
        public int $pending_jobs,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobs_per_minute: (float) ($data['jobs_per_minute'] ?? 0),
            jobs_processed_last_hour: (int) ($data['jobs_processed_last_hour'] ?? 0),
            failed_jobs: (int) ($data['failed_jobs'] ?? 0),
            failed_rate_percent: (float) ($data['failed_rate_percent'] ?? 0),
            average_processing_time_ms: (int) ($data['average_processing_time_ms'] ?? 0),
            active_workers: (int) ($data['active_workers'] ?? 0),
            pending_jobs: (int) ($data['pending_jobs'] ?? 0),
        );
    }

    /**
     * @return array{jobs_per_minute: float, jobs_processed_last_hour: int, failed_jobs: int, failed_rate_percent: float, average_processing_time_ms: int, active_workers: int, pending_jobs: int}
     */
    public function toArray(): array
    {
        return [
            'jobs_per_minute' => $this->jobs_per_minute,
            'jobs_processed_last_hour' => $this->jobs_processed_last_hour,
            'failed_jobs' => $this->failed_jobs,
            'failed_rate_percent' => $this->failed_rate_percent,
            'average_processing_time_ms' => $this->average_processing_time_ms,
            'active_workers' => $this->active_workers,
            'pending_jobs' => $this->pending_jobs,
        ];
    }

    /**
     * @return array{jobs_per_minute: float, jobs_processed_last_hour: int, failed_jobs: int, failed_rate_percent: float, average_processing_time_ms: int, active_workers: int, pending_jobs: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
