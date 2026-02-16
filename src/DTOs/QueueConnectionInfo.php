<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class QueueConnectionInfo implements JsonSerializable
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $name,
        public string $driver,
        public bool $is_default,
        public bool $connected,
        public int $latency_ms,
        public ?string $dashboard_url,
        public int $workers,
        public bool $paused,
        public array $config = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            driver: (string) ($data['driver'] ?? ''),
            is_default: (bool) ($data['is_default'] ?? false),
            connected: (bool) ($data['connected'] ?? false),
            latency_ms: (int) ($data['latency_ms'] ?? 0),
            dashboard_url: $data['dashboard_url'] ?? null,
            workers: (int) ($data['workers'] ?? 0),
            paused: (bool) ($data['paused'] ?? false),
            config: $data['config'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver,
            'is_default' => $this->is_default,
            'connected' => $this->connected,
            'latency_ms' => $this->latency_ms,
            'dashboard_url' => $this->dashboard_url,
            'workers' => $this->workers,
            'paused' => $this->paused,
            'config' => $this->config,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
