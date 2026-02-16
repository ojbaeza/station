<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class ConnectionStatus implements JsonSerializable
{
    public function __construct(
        public bool $connected,
        public int $latency_ms,
        public ?string $driver = null,
        public ?string $dashboard_url = null,
        public ?string $message = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connected: (bool) ($data['connected'] ?? false),
            latency_ms: (int) ($data['latency_ms'] ?? 0),
            driver: $data['driver'] ?? null,
            dashboard_url: $data['dashboard_url'] ?? null,
            message: $data['message'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'connected' => $this->connected,
            'latency_ms' => $this->latency_ms,
        ];

        if ($this->driver !== null) {
            $result['driver'] = $this->driver;
        }

        if ($this->dashboard_url !== null) {
            $result['dashboard_url'] = $this->dashboard_url;
        }

        if ($this->message !== null) {
            $result['message'] = $this->message;
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
