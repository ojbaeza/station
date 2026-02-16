<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class HealthCheckResult implements JsonSerializable
{
    /**
     * @param array<string, array<string, mixed>> $checks
     * @param array<string, array<string, mixed>>|null $connections
     */
    public function __construct(
        public string $status,
        public string $timestamp,
        public array $checks,
        public ?array $connections = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: (string) ($data['status'] ?? 'unknown'),
            timestamp: (string) ($data['timestamp'] ?? ''),
            checks: $data['checks'] ?? [],
            connections: $data['connections'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status,
            'timestamp' => $this->timestamp,
            'checks' => $this->checks,
        ];

        if ($this->connections !== null) {
            $result['connections'] = $this->connections;
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
