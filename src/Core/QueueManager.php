<?php

declare(strict_types=1);

namespace Station\Core;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Illuminate\Support\Facades\DB;
use Station\Contracts\DriverInterface;

final class QueueManager
{
    public function __construct(
        private readonly LaravelQueueManager $manager,
    ) {}

    /**
     * Pause a queue.
     */
    public function pause(string $queue, ?string $connection = null): void
    {
        $driver = $this->getDriver($connection);

        if ($driver instanceof DriverInterface) {
            $driver->pause($queue);
        }

        $this->updateQueuePaused($queue, $connection, true);
    }

    /**
     * Resume a paused queue.
     */
    public function resume(string $queue, ?string $connection = null): void
    {
        $driver = $this->getDriver($connection);

        if ($driver instanceof DriverInterface) {
            $driver->resume($queue);
        }

        $this->updateQueuePaused($queue, $connection, false);
    }

    /**
     * Check if a queue is paused.
     */
    public function isPaused(string $queue, ?string $connection = null): bool
    {
        $driver = $this->getDriver($connection);

        if ($driver instanceof DriverInterface) {
            return $driver->isPaused($queue);
        }

        return (bool) DB::table('station_queue_status')
            ->where('queue', $queue)
            ->where('connection', $connection ?? config('station.default'))
            ->value('paused');
    }

    /**
     * Get the size of a queue.
     */
    public function size(string $queue, ?string $connection = null): int
    {
        return $this->getDriver($connection)->size($queue);
    }

    /**
     * Clear all jobs from a queue.
     */
    public function clear(string $queue, ?string $connection = null): int
    {
        $driver = $this->getDriver($connection);

        if ($driver instanceof DriverInterface) {
            return $driver->clear($queue);
        }

        // Fallback: count and clear via Laravel's queue
        $count = $driver->size($queue);

        // Pop all jobs from the queue
        while ($driver->pop($queue) !== null) {
            // Jobs are discarded
        }

        return $count;
    }

    /**
     * Get status of all queues.
     *
     * @return array<string, array<string, mixed>>
     */
    public function status(?string $connection = null): array
    {
        $connection = $connection ?? config('station.default');

        $statuses = DB::table('station_queue_status')
            ->where('connection', $connection)
            ->get();

        $result = [];

        foreach ($statuses as $status) {
            $result[$status->queue] = [
                'size' => $this->size($status->queue, $connection),
                'paused' => (bool) $status->paused,
                'updated_at' => $status->updated_at,
            ];
        }

        return $result;
    }

    /**
     * Get all known queues.
     *
     * @return array<string>
     */
    public function getAll(?string $connection = null): array
    {
        $connection = $connection ?? config('station.default');

        return DB::table('station_queue_status')
            ->where('connection', $connection)
            ->pluck('queue')
            ->toArray();
    }

    /**
     * Get the queue driver.
     */
    private function getDriver(?string $connection): Queue
    {
        return $this->manager->connection($connection ?? config('station.default'));
    }

    /**
     * Update queue paused state in the database.
     */
    private function updateQueuePaused(string $queue, ?string $connection, bool $paused): void
    {
        $connection = $connection ?? config('station.default');

        DB::table('station_queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $connection],
            [
                'paused' => $paused,
                'paused_at' => $paused ? now() : null,
                'updated_at' => now(),
            ],
        );
    }
}
