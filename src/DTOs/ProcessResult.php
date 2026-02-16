<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class ProcessResult implements JsonSerializable
{
    public function __construct(
        public bool $success,
        public string $message,
        public ?int $pid = null,
        public ?string $command = null,
        public ?int $stopped = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? false),
            message: (string) ($data['message'] ?? ''),
            pid: isset($data['pid']) ? (int) $data['pid'] : null,
            command: $data['command'] ?? null,
            stopped: isset($data['stopped']) ? (int) $data['stopped'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        if ($this->pid !== null) {
            $result['pid'] = $this->pid;
        }

        if ($this->command !== null) {
            $result['command'] = $this->command;
        }

        if ($this->stopped !== null) {
            $result['stopped'] = $this->stopped;
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
