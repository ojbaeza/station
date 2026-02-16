<?php

declare(strict_types=1);

namespace Station\Repositories;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Station\Contracts\SupervisorRepositoryInterface;

final class DatabaseSupervisorRepository implements SupervisorRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $tablePrefix = 'station_',
    ) {}

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
    ): void {
        $this->connection->table($this->tablePrefix . 'supervisors')->insert([
            'id' => $id,
            'name' => $name,
            'hostname' => $hostname,
            'pid' => $pid,
            'queues' => json_encode($queues),
            'options' => json_encode($options),
            'status' => 'running',
            'jobs_processed' => 0,
            'last_heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Update supervisor heartbeat.
     */
    public function heartbeat(string $id, int $jobsProcessed): void
    {
        $this->connection->table($this->tablePrefix . 'supervisors')
            ->where('id', $id)
            ->update([
                'jobs_processed' => $jobsProcessed,
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Update supervisor status.
     */
    public function updateStatus(string $id, string $status): void
    {
        $this->connection->table($this->tablePrefix . 'supervisors')
            ->where('id', $id)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
    }

    /**
     * Remove a supervisor.
     */
    public function remove(string $id): void
    {
        $this->connection->table($this->tablePrefix . 'supervisors')
            ->where('id', $id)
            ->delete();
    }

    /**
     * Get all active supervisors.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getActive(): Collection
    {
        return $this->connection->table($this->tablePrefix . 'supervisors')
            ->whereIn('status', ['running', 'paused'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(static fn($s) => (array) $s);
    }

    /**
     * Find a supervisor by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $supervisor = $this->connection->table($this->tablePrefix . 'supervisors')
            ->where('id', $id)
            ->first();

        return $supervisor ? (array) $supervisor : null;
    }

    /**
     * Get stale supervisors (no heartbeat within timeout).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getStale(int $timeout): Collection
    {
        return $this->connection->table($this->tablePrefix . 'supervisors')
            ->whereIn('status', ['running', 'paused'])
            ->where('last_heartbeat_at', '<', now()->subSeconds($timeout))
            ->get()
            ->map(static fn($s) => (array) $s);
    }

    /**
     * Prune stale supervisors.
     */
    public function pruneStale(int $timeout): int
    {
        return $this->connection->table($this->tablePrefix . 'supervisors')
            ->whereIn('status', ['running', 'paused'])
            ->where('last_heartbeat_at', '<', now()->subSeconds($timeout))
            ->delete();
    }

    /**
     * Mark supervisor as terminated.
     */
    public function markTerminated(string $id): void
    {
        $this->updateStatus($id, 'terminated');
    }
}
