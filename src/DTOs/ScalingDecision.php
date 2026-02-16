<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class ScalingDecision implements JsonSerializable
{
    public function __construct(
        public string $action,
        public int $from,
        public int $to,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            action: (string) ($data['action'] ?? ''),
            from: (int) ($data['from'] ?? 0),
            to: (int) ($data['to'] ?? 0),
        );
    }

    /**
     * @return array{action: string, from: int, to: int}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }

    /**
     * @return array{action: string, from: int, to: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
