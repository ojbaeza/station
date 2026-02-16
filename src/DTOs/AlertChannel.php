<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;
use Station\Enums\AlertChannelType;

final readonly class AlertChannel implements JsonSerializable
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $id,
        public string $name,
        public AlertChannelType $type,
        public bool $enabled,
        public array $config = [],
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            type: $data['type'] instanceof AlertChannelType ? $data['type'] : AlertChannelType::from((string) $data['type']),
            enabled: (bool) ($data['enabled'] ?? true),
            config: \is_string($data['config'] ?? null) ? (array) json_decode($data['config'], true) : ($data['config'] ?? []),
            created_at: $data['created_at'] ?? null,
            updated_at: $data['updated_at'] ?? null,
        );
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
            'config' => $this->config,
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
