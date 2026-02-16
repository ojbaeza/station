<?php

declare(strict_types=1);

namespace Station\Contracts;

use Station\DTOs\AlertRecord;
use Station\DTOs\AlertRule;
use Station\DTOs\PaginatedResult;
use Station\Enums\AlertType;

interface AlertRepositoryInterface
{
    public function storeRule(AlertRule $rule): void;

    public function findRule(string $id): ?AlertRule;

    public function updateRule(string $id, array $data): void;

    public function deleteRule(string $id): void;

    /**
     * @return array<int, AlertRule>
     */
    public function getEnabledRules(): array;

    /**
     * @return array<int, AlertRule>
     */
    public function getAllRules(): array;

    public function markTriggered(string $ruleId): void;

    public function storeRecord(AlertRecord $record): int;

    public function findRecord(int $id): ?AlertRecord;

    public function resolveRecord(int $id): void;

    /**
     * @param array<string, mixed> $filters
     */
    public function paginateHistory(array $filters = [], int $page = 1, int $perPage = 25): PaginatedResult;

    public function pruneHistory(int $days): int;

    /**
     * @return array<int, AlertRule>
     */
    public function getRulesByType(AlertType $type): array;
}
