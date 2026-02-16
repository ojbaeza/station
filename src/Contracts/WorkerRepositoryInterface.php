<?php

declare(strict_types=1);

namespace Station\Contracts;

use Illuminate\Support\Collection;

interface WorkerRepositoryInterface
{
    /**
     * Register a worker.
     */
    public function register(
        string $id,
        string $supervisorId,
        string $hostname,
        int $pid,
        string $queue,
    ): void;

    /**
     * Update worker heartbeat.
     */
    public function heartbeat(string $id, int $memoryUsage, ?string $currentJobId = null): void;

    /**
     * Update worker status.
     */
    public function updateStatus(string $id, string $status): void;

    /**
     * Increment jobs processed count.
     */
    public function incrementJobsProcessed(string $id): void;

    /**
     * Remove a worker.
     */
    public function remove(string $id): void;

    /**
     * Get workers by supervisor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBySupervisor(string $supervisorId): Collection;

    /**
     * Get active workers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getActive(): Collection;

    /**
     * Get workers by queue.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getByQueue(string $queue): Collection;

    /**
     * Get stale workers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getStale(int $timeout): Collection;

    /**
     * Prune stale workers.
     */
    public function pruneStale(int $timeout): int;
}
