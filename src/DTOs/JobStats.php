<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class JobStats implements JsonSerializable
{
    public function __construct(
        public int $pending,
        public int $processing,
        public int $completed,
        public int $failed,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pending: (int) ($data['pending'] ?? 0),
            processing: (int) ($data['processing'] ?? 0),
            completed: (int) ($data['completed'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
        );
    }

    /**
     * @return array{pending: int, processing: int, completed: int, failed: int}
     */
    public function toArray(): array
    {
        return [
            'pending' => $this->pending,
            'processing' => $this->processing,
            'completed' => $this->completed,
            'failed' => $this->failed,
        ];
    }

    /**
     * @return array{pending: int, processing: int, completed: int, failed: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
