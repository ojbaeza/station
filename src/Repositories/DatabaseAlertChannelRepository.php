<?php

declare(strict_types=1);

namespace Station\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\DTOs\AlertChannel;

final class DatabaseAlertChannelRepository implements AlertChannelRepositoryInterface
{
    private readonly string $table;

    public function __construct(
        private readonly ConnectionInterface $connection,
        string $tablePrefix = 'station_',
    ) {
        $this->table = $tablePrefix . 'alert_channels';
    }

    public function store(AlertChannel $channel): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)->insert([
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type->value,
            'enabled' => $channel->enabled,
            'config' => json_encode($channel->config),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function find(string $id): ?AlertChannel
    {
        $row = $this->connection->table($this->table)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return AlertChannel::fromArray((array) $row);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->connection->table($this->table)
            ->whereIn('id', $ids)
            ->get()
            ->map(static fn($row): AlertChannel => AlertChannel::fromArray((array) $row))
            ->all();
    }

    public function getAll(): array
    {
        return $this->connection->table($this->table)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(static fn($row): AlertChannel => AlertChannel::fromArray((array) $row))
            ->all();
    }

    public function update(string $id, array $data): void
    {
        $update = [];

        foreach (['name', 'enabled'] as $field) {
            if (\array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (\array_key_exists('type', $data)) {
            $update['type'] = \is_string($data['type']) ? $data['type'] : $data['type']->value;
        }

        if (\array_key_exists('config', $data)) {
            $update['config'] = json_encode($data['config']);
        }

        $update['updated_at'] = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->table)->where('id', $id)->update($update);
    }

    public function delete(string $id): void
    {
        $this->connection->table($this->table)->where('id', $id)->delete();
    }

    public function existsById(string $id): bool
    {
        return $this->connection->table($this->table)->where('id', $id)->exists();
    }

    public function findByName(string $name): ?AlertChannel
    {
        $row = $this->connection->table($this->table)->where('name', $name)->first();

        if ($row === null) {
            return null;
        }

        return AlertChannel::fromArray((array) $row);
    }
}
