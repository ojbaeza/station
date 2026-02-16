<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class QueueStats implements JsonSerializable
{
    public function __construct(
        public int $size,
        public bool $paused,
        public int $workers,
        public float $throughput,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            size: (int) ($data['size'] ?? 0),
            paused: (bool) ($data['paused'] ?? false),
            workers: (int) ($data['workers'] ?? 0),
            throughput: (float) ($data['throughput'] ?? 0),
        );
    }

    /**
     * @return array{size: int, paused: bool, workers: int, throughput: float}
     */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'paused' => $this->paused,
            'workers' => $this->workers,
            'throughput' => $this->throughput,
        ];
    }

    /**
     * @return array{size: int, paused: bool, workers: int, throughput: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
