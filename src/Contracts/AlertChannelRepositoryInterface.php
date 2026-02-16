<?php

declare(strict_types=1);

namespace Station\Contracts;

use Station\DTOs\AlertChannel;

interface AlertChannelRepositoryInterface
{
    public function store(AlertChannel $channel): void;

    public function find(string $id): ?AlertChannel;

    /**
     * @param array<int, string> $ids
     * @return array<int, AlertChannel>
     */
    public function findMany(array $ids): array;

    /**
     * @return array<int, AlertChannel>
     */
    public function getAll(): array;

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): void;

    public function delete(string $id): void;

    public function existsById(string $id): bool;

    public function findByName(string $name): ?AlertChannel;
}
