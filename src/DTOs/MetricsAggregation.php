<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class MetricsAggregation implements JsonSerializable
{
    public function __construct(
        public int $jobs_processed,
        public int $jobs_failed,
        public float $avg_processing_time,
        public float $avg_wait_time,
        public float $failure_rate,
        public ?float $throughput = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobs_processed: (int) ($data['jobs_processed'] ?? 0),
            jobs_failed: (int) ($data['jobs_failed'] ?? 0),
            avg_processing_time: (float) ($data['avg_processing_time'] ?? 0),
            avg_wait_time: (float) ($data['avg_wait_time'] ?? 0),
            failure_rate: (float) ($data['failure_rate'] ?? 0),
            throughput: isset($data['throughput']) ? (float) $data['throughput'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'jobs_processed' => $this->jobs_processed,
            'jobs_failed' => $this->jobs_failed,
            'avg_processing_time' => $this->avg_processing_time,
            'avg_wait_time' => $this->avg_wait_time,
            'failure_rate' => $this->failure_rate,
        ];

        if ($this->throughput !== null) {
            $result['throughput'] = $this->throughput;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
