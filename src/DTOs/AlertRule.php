<?php

declare(strict_types=1);

namespace Station\DTOs;

use Carbon\CarbonImmutable;
use JsonSerializable;
use Station\Enums\AlertType;

final readonly class AlertRule implements JsonSerializable
{
    /**
     * @param array<int, string> $channel_ids
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public AlertType $type,
        public bool $enabled,
        public array $condition,
        public int $window,
        public array $channel_ids,
        public int $cooldown,
        public array $metadata = [],
        public string $source = 'config',
        public ?string $last_triggered_at = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $channelIds = $data['channel_ids'] ?? $data['channels'] ?? [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            type: $data['type'] instanceof AlertType ? $data['type'] : AlertType::from((string) $data['type']),
            enabled: (bool) ($data['enabled'] ?? true),
            condition: \is_string($data['condition'] ?? null) ? (array) json_decode($data['condition'], true) : ($data['condition'] ?? []),
            window: (int) ($data['window'] ?? 300),
            channel_ids: \is_string($channelIds) ? (array) json_decode($channelIds, true) : ($channelIds),
            cooldown: (int) ($data['cooldown'] ?? 300),
            metadata: \is_string($data['metadata'] ?? null) ? (array) json_decode($data['metadata'], true) : ($data['metadata'] ?? []),
            source: (string) ($data['source'] ?? 'config'),
            last_triggered_at: $data['last_triggered_at'] ?? null,
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
    }

    public function isInCooldown(): bool
    {
        if ($this->last_triggered_at === null) {
            return false;
        }

        return CarbonImmutable::parse($this->last_triggered_at)
            ->addSeconds($this->cooldown)
            ->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'enabled' => $this->enabled,
            'condition' => $this->condition,
            'window' => $this->window,
            'channel_ids' => $this->channel_ids,
            'cooldown' => $this->cooldown,
            'metadata' => $this->metadata,
            'source' => $this->source,
            'last_triggered_at' => $this->last_triggered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
