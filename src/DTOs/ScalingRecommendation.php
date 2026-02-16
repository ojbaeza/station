<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class ScalingRecommendation implements JsonSerializable
{
    public function __construct(
        public int $current,
        public int $recommended,
        public string $reason,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            current: (int) ($data['current'] ?? 0),
            recommended: (int) ($data['recommended'] ?? 0),
            reason: (string) ($data['reason'] ?? ''),
        );
    }

    /**
     * @return array{current: int, recommended: int, reason: string}
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'recommended' => $this->recommended,
            'reason' => $this->reason,
        ];
    }

    /**
     * @return array{current: int, recommended: int, reason: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
