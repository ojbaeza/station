<?php

declare(strict_types=1);

namespace Station\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\DTOs\AlertRecord;
use Station\DTOs\AlertRule;
use Station\DTOs\PaginatedResult;
use Station\Enums\AlertType;

final class DatabaseAlertRepository implements AlertRepositoryInterface
{
    private readonly string $rulesTable;

    private readonly string $historyTable;

    public function __construct(
        private readonly ConnectionInterface $connection,
        string $tablePrefix = 'station_',
    ) {
        $this->rulesTable = $tablePrefix . 'alert_rules';
        $this->historyTable = $tablePrefix . 'alert_history';
    }

    public function storeRule(AlertRule $rule): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->rulesTable)->insert([
            'id' => $rule->id,
            'name' => $rule->name,
            'type' => $rule->type->value,
            'enabled' => $rule->enabled,
            'condition' => json_encode($rule->condition),
            'window' => $rule->window,
            'channel_ids' => json_encode($rule->channel_ids),
            'cooldown' => $rule->cooldown,
            'metadata' => json_encode($rule->metadata),
            'source' => $rule->source,
            'last_triggered_at' => $rule->last_triggered_at,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function findRule(string $id): ?AlertRule
    {
        $row = $this->connection->table($this->rulesTable)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return AlertRule::fromArray((array) $row);
    }

    public function updateRule(string $id, array $data): void
    {
        $update = [];

        foreach (['name', 'enabled', 'window', 'cooldown', 'source'] as $field) {
            if (\array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        foreach (['condition', 'channel_ids', 'metadata'] as $field) {
            if (\array_key_exists($field, $data)) {
                $update[$field] = json_encode($data[$field]);
            }
        }

        if (isset($data['type'])) {
            $update['type'] = $data['type'] instanceof AlertType ? $data['type']->value : $data['type'];
        }

        $update['updated_at'] = CarbonImmutable::now()->toDateTimeString();

        $this->connection->table($this->rulesTable)->where('id', $id)->update($update);
    }

    public function deleteRule(string $id): void
    {
        $this->connection->table($this->rulesTable)->where('id', $id)->delete();
    }

    /**
     * @return array<int, AlertRule>
     */
    public function getEnabledRules(): array
    {
        return $this->connection->table($this->rulesTable)
            ->where('enabled', true)
            ->get()
            ->map(static fn($row): AlertRule => AlertRule::fromArray((array) $row))
            ->all();
    }

    /**
     * @return array<int, AlertRule>
     */
    public function getAllRules(): array
    {
        return $this->connection->table($this->rulesTable)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(static fn($row): AlertRule => AlertRule::fromArray((array) $row))
            ->all();
    }

    public function markTriggered(string $ruleId): void
    {
        $this->connection->table($this->rulesTable)
            ->where('id', $ruleId)
            ->update(['last_triggered_at' => CarbonImmutable::now()->toDateTimeString()]);
    }

    public function storeRecord(AlertRecord $record): int
    {
        return $this->connection->table($this->historyTable)->insertGetId([
            'rule_id' => $record->rule_id,
            'rule_name' => $record->rule_name,
            'type' => $record->type->value,
            'severity' => $record->severity->value,
            'message' => $record->message,
            'context' => json_encode($record->context),
            'channels_notified' => json_encode($record->channels_notified),
            'resolved' => false,
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    public function findRecord(int $id): ?AlertRecord
    {
        $row = $this->connection->table($this->historyTable)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return AlertRecord::fromArray((array) $row);
    }

    public function resolveRecord(int $id): void
    {
        $this->connection->table($this->historyTable)
            ->where('id', $id)
            ->update([
                'resolved' => true,
                'resolved_at' => CarbonImmutable::now()->toDateTimeString(),
            ]);
    }

    public function paginateHistory(array $filters = [], int $page = 1, int $perPage = 25): PaginatedResult
    {
        $query = $this->connection->table($this->historyTable);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['resolved'])) {
            $query->where('resolved', (bool) $filters['resolved']);
        }

        if (isset($filters['rule_id'])) {
            $query->where('rule_id', $filters['rule_id']);
        }

        $total = (clone $query)->count();
        $offset = ($page - 1) * $perPage;

        $data = (clone $query)
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(static fn($row): AlertRecord => AlertRecord::fromArray((array) $row));

        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $from = $total > 0 ? $offset + 1 : null;
        $to = $total > 0 ? min($page * $perPage, $total) : null;

        return new PaginatedResult(
            data: $data,
            total: $total,
            per_page: $perPage,
            current_page: $page,
            last_page: $lastPage,
            from: $from,
            to: $to,
        );
    }

    public function pruneHistory(int $days): int
    {
        $threshold = CarbonImmutable::now()->subDays($days);

        return $this->connection->table($this->historyTable)
            ->where('created_at', '<', $threshold->toDateTimeString())
            ->delete();
    }

    /**
     * @return array<int, AlertRule>
     */
    public function getRulesByType(AlertType $type): array
    {
        return $this->connection->table($this->rulesTable)
            ->where('type', $type->value)
            ->where('enabled', true)
            ->get()
            ->map(static fn($row): AlertRule => AlertRule::fromArray((array) $row))
            ->all();
    }
}
