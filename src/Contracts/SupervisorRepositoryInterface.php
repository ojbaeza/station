<?php

declare(strict_types=1);

namespace Station\Contracts;

use Illuminate\Support\Collection;

interface SupervisorRepositoryInterface
{
    /**
     * Register a supervisor.
     *
     * @param array<string, mixed> $options
     * @param array<int, string> $queues
     */
    public function register(
        string $id,
        string $name,
        string $hostname,
        int $pid,
        array $queues,
        array $options,
    ): void;

    /**
     * Update supervisor heartbeat.
     */
    public function heartbeat(string $id, int $jobsProcessed): void;

    /**
     * Update supervisor status.
     */
    public function updateStatus(string $id, string $status): void;

    /**
     * Remove a supervisor.
     */
    public function remove(string $id): void;

    /**
     * Find a supervisor by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array;

    /**
     * Mark a supervisor as terminated.
     */
    public function markTerminated(string $id): void;

    /**
     * Get all active supervisors.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getActive(): Collection;

    /**
     * Get stale supervisors (no heartbeat within timeout).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getStale(int $timeout): Collection;

    /**
     * Prune stale supervisors.
     */
    public function pruneStale(int $timeout): int;
}
