<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;

final readonly class AlertRecord implements JsonSerializable
{
    /**
     * @param array<string, mixed> $context
     * @param array<int, string> $channels_notified
     */
    public function __construct(
        public ?int $id,
        public string $rule_id,
        public string $rule_name,
        public AlertType $type,
        public AlertSeverity $severity,
        public string $message,
        public array $context = [],
        public array $channels_notified = [],
        public bool $resolved = false,
        public ?string $resolved_at = null,
        public ?string $created_at = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            rule_id: (string) ($data['rule_id'] ?? ''),
            rule_name: (string) ($data['rule_name'] ?? ''),
            type: $data['type'] instanceof AlertType ? $data['type'] : AlertType::from((string) $data['type']),
            severity: $data['severity'] instanceof AlertSeverity ? $data['severity'] : AlertSeverity::from((string) $data['severity']),
            message: (string) ($data['message'] ?? ''),
            context: \is_string($data['context'] ?? null) ? (array) json_decode($data['context'], true) : ($data['context'] ?? []),
            channels_notified: \is_string($data['channels_notified'] ?? null) ? (array) json_decode($data['channels_notified'], true) : ($data['channels_notified'] ?? []),
            resolved: (bool) ($data['resolved'] ?? false),
            resolved_at: $data['resolved_at'] ?? null,
            created_at: $data['created_at'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rule_id' => $this->rule_id,
            'rule_name' => $this->rule_name,
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'context' => $this->context,
            'channels_notified' => $this->channels_notified,
            'resolved' => $this->resolved,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
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
