<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class BulkActionResult implements JsonSerializable
{
    /**
     * @param array<int, array{id: string, message: string}> $errors
     */
    public function __construct(
        public bool $success,
        public int $processed,
        public int $failed,
        public array $errors = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? false),
            processed: (int) ($data['processed'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
            errors: $data['errors'] ?? [],
        );
    }

    /**
     * @return array{success: bool, processed: int, failed: int, errors: array<int, array{id: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'processed' => $this->processed,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }

    /**
     * @return array{success: bool, processed: int, failed: int, errors: array<int, array{id: string, message: string}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
