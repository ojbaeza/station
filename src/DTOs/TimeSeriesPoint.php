<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class TimeSeriesPoint implements JsonSerializable
{
    public function __construct(
        public string $timestamp,
        public int $jobs_processed,
        public int $jobs_failed,
        public float $avg_wait_time,
        public float $avg_processing_time,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            timestamp: (string) ($data['timestamp'] ?? ''),
            jobs_processed: (int) ($data['jobs_processed'] ?? 0),
            jobs_failed: (int) ($data['jobs_failed'] ?? 0),
            avg_wait_time: (float) ($data['avg_wait_time'] ?? 0),
            avg_processing_time: (float) ($data['avg_processing_time'] ?? 0),
        );
    }

    /**
     * @return array{timestamp: string, jobs_processed: int, jobs_failed: int, avg_wait_time: float, avg_processing_time: float}
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'jobs_processed' => $this->jobs_processed,
            'jobs_failed' => $this->jobs_failed,
            'avg_wait_time' => $this->avg_wait_time,
            'avg_processing_time' => $this->avg_processing_time,
        ];
    }

    /**
     * @return array{timestamp: string, jobs_processed: int, jobs_failed: int, avg_wait_time: float, avg_processing_time: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
