<?php

declare(strict_types=1);

namespace Station\Core;

use DateTimeInterface;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Illuminate\Support\Facades\DB;
use Station\Contracts\AggregateDriverInfoInterface;
use Station\Contracts\DriverInterface;
use Station\Enums\Driver;
use Throwable;

final class DriverInfoCollector
{
    public function __construct(
        private readonly LaravelQueueManager $queueManager,
    ) {}

    /**
     * Get detailed info for all configured connections.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAll(): array
    {
        /** @var array<string, array<string, mixed>> $connections */
        $connections = config('queue.connections', []);
        $info = [];

        foreach ($connections as $name => $config) {
            $driver = $config['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            try {
                $queue = $this->queueManager->connection($name);

                if ($queue instanceof DriverInterface) {
                    if ($queue instanceof AggregateDriverInfoInterface) {
                        $driverInfo = $queue->getAllDriverInfo();
                    } else {
                        $defaultQueue = $config['queue'] ?? 'default';
                        $driverInfo = $queue->getDriverInfo($defaultQueue);
                    }
                    $driverInfo['connection'] = $name;
                    $info[$name] = $driverInfo;
                }
            } catch (Throwable) {
                $info[$name] = [
                    'connection' => $name,
                    'driver' => $driver,
                    'error' => 'Unable to connect',
                ];
            }
        }

        return $info;
    }

    /**
     * Record a snapshot of all driver stats for time-series tracking.
     */
    public function recordSnapshots(): void
    {
        $now = now();

        foreach ($this->getAll() as $name => $info) {
            if (isset($info['error'])) {
                continue;
            }

            DB::table('station_driver_snapshots')->insert([
                'connection' => $name,
                'queue_size' => $info['size'] ?? 0,
                'memory_bytes' => $info['memory_used'] ?? $info['memory'] ?? 0,
                'consumers' => $info['consumers'] ?? $info['connected_clients'] ?? $info['watchers'] ?? 0,
                'ops_rate' => $info['publish_rate'] ?? $info['ops_per_sec'] ?? 0,
                'recorded_at' => $now,
            ]);
        }
    }

    /**
     * Get time-series data for a connection.
     *
     * @return array<string, list<array{time: string, value: float|int}>>
     */
    public function getTimeSeries(string $connection, string $period = '1h'): array
    {
        $since = match ($period) {
            '5m' => now()->subMinutes(5),
            '15m' => now()->subMinutes(15),
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            default => now()->subHour(),
        };

        $rows = DB::table('station_driver_snapshots')
            ->where('connection', $connection)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get(['queue_size', 'memory_bytes', 'consumers', 'ops_rate', 'recorded_at']);

        $series = [
            'queue_size' => [],
            'memory_bytes' => [],
            'consumers' => [],
            'ops_rate' => [],
        ];

        foreach ($rows as $row) {
            $time = date('H:i', strtotime($row->recorded_at));
            $series['queue_size'][] = ['time' => $time, 'value' => (int) $row->queue_size];
            $series['memory_bytes'][] = ['time' => $time, 'value' => (int) $row->memory_bytes];
            $series['consumers'][] = ['time' => $time, 'value' => (int) $row->consumers];
            $series['ops_rate'][] = ['time' => $time, 'value' => (float) $row->ops_rate];
        }

        return $series;
    }

    /**
     * Prune old driver snapshots.
     */
    public function pruneSnapshots(DateTimeInterface $before): int
    {
        return DB::table('station_driver_snapshots')
            ->where('recorded_at', '<', $before)
            ->delete();
    }
}
