<?php

declare(strict_types=1);

namespace Station\Repositories;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Station\Contracts\WorkerRepositoryInterface;

final class DatabaseWorkerRepository implements WorkerRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $tablePrefix = 'station_',
    ) {}

    /**
     * Register a worker.
     */
    public function register(
        string $id,
        string $supervisorId,
        string $hostname,
        int $pid,
        string $queue,
    ): void {
        $this->connection->table($this->tablePrefix . 'workers')->insert([
            'id' => $id,
            'supervisor_id' => $supervisorId,
            'hostname' => $hostname,
            'pid' => $pid,
            'queue' => $queue,
            'status' => 'running',
            'jobs_processed' => 0,
            'memory_usage' => memory_get_usage(true),
            'current_job_id' => null,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Update worker heartbeat.
     */
    public function heartbeat(string $id, int $memoryUsage, ?string $currentJobId = null): void
    {
        $this->connection->table($this->tablePrefix . 'workers')
            ->where('id', $id)
            ->update([
                'memory_usage' => $memoryUsage,
                'current_job_id' => $currentJobId,
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Update worker status.
     */
    public function updateStatus(string $id, string $status): void
    {
        $this->connection->table($this->tablePrefix . 'workers')
            ->where('id', $id)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
    }

    /**
     * Increment jobs processed count.
     */
    public function incrementJobsProcessed(string $id): void
    {
        $this->connection->table($this->tablePrefix . 'workers')
            ->where('id', $id)
            ->increment('jobs_processed');
    }

    /**
     * Remove a worker.
     */
    public function remove(string $id): void
    {
        $this->connection->table($this->tablePrefix . 'workers')
            ->where('id', $id)
            ->delete();
    }

    /**
     * Get workers by supervisor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBySupervisor(string $supervisorId): Collection
    {
        return $this->connection->table($this->tablePrefix . 'workers')
            ->where('supervisor_id', $supervisorId)
            ->orderBy('started_at', 'desc')
            ->get()
            ->map(static fn($w) => (array) $w);
    }

    /**
     * Get active workers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getActive(): Collection
    {
        return $this->connection->table($this->tablePrefix . 'workers')
            ->whereIn('status', ['running', 'idle', 'processing'])
            ->orderBy('started_at', 'desc')
            ->get()
            ->map(static fn($w) => (array) $w);
    }

    /**
     * Get workers by queue.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getByQueue(string $queue): Collection
    {
        return $this->connection->table($this->tablePrefix . 'workers')
            ->where('queue', $queue)
            ->whereIn('status', ['running', 'idle', 'processing'])
            ->orderBy('started_at', 'desc')
            ->get()
            ->map(static fn($w) => (array) $w);
    }

    /**
     * Find a worker by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $worker = $this->connection->table($this->tablePrefix . 'workers')
            ->where('id', $id)
            ->first();

        return $worker ? (array) $worker : null;
    }

    /**
     * Get stale workers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getStale(int $timeout): Collection
    {
        return $this->connection->table($this->tablePrefix . 'workers')
            ->whereIn('status', ['running', 'idle', 'processing'])
            ->where('last_heartbeat_at', '<', now()->subSeconds($timeout))
            ->get()
            ->map(static fn($w) => (array) $w);
    }

    /**
     * Prune stale workers.
     */
    public function pruneStale(int $timeout): int
    {
        return $this->connection->table($this->tablePrefix . 'workers')
            ->whereIn('status', ['running', 'idle', 'processing'])
            ->where('last_heartbeat_at', '<', now()->subSeconds($timeout))
            ->delete();
    }

    /**
     * Mark worker as stopped.
     */
    public function markStopped(string $id): void
    {
        $this->updateStatus($id, 'stopped');
    }
}
