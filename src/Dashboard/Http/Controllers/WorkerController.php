<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Station\Contracts\HealthCheckerInterface;
use Station\Core\DriverInfoCollector;
use Station\Core\ProcessManager;
use Station\Core\QueueManager;
use Station\Dashboard\Http\Controllers\Traits\ApiHelpers;
use Station\Enums\Driver;
use Throwable;

final class WorkerController extends Controller
{
    use ApiHelpers;

    public function __construct(
        private readonly ProcessManager $processManager,
        private readonly QueueManager $queueManager,
        private readonly HealthCheckerInterface $healthChecker,
        private readonly DriverInfoCollector $driverInfoCollector,
    ) {}

    /**
     * Get worker status per connection.
     */
    public function workerStatus(): JsonResponse
    {
        try {
            return response()->json($this->processManager->getWorkerStatus());
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to get worker status');
        }
    }

    /**
     * Combined worker dashboard status: workers + pause status + supervisor in one call.
     */
    public function workerDashboardStatus(): JsonResponse
    {
        try {
            $workers = $this->processManager->getWorkerStatus();
        } catch (Throwable) {
            $workers = [];
        }

        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);
        $pauseStatus = [];

        foreach ($queueConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            try {
                $pauseStatus[$name] = $this->queueManager->status($name);
            } catch (Throwable) {
                $pauseStatus[$name] = ['paused' => false];
            }
        }

        try {
            $supervisor = $this->processManager->getSupervisorStatus();
        } catch (Throwable) {
            $supervisor = ['running' => false, 'pid' => null, 'connection' => null, 'queue' => null, 'workers' => 0];
        }

        $driverInfo = $this->driverInfoCollector->getAll();

        // Throttle snapshot recording to once per minute
        if (Cache::add('station:driver-snapshot-lock', true, 60)) {
            try {
                $this->driverInfoCollector->recordSnapshots();
            } catch (Throwable) {
                // Table may not exist yet
            }
        }

        return response()->json([
            'workers' => $workers,
            'pauseStatus' => $pauseStatus,
            'supervisor' => $supervisor,
            'driverInfo' => $driverInfo,
        ]);
    }

    /**
     * Start worker(s) for a connection.
     */
    public function startWorker(Request $request): JsonResponse
    {
        $request->validate([
            'connection' => 'required|string',
            'queue' => 'string',
            'workers' => 'integer|min:1|max:10',
        ]);

        try {
            $connection = $request->input('connection');
            $queue = $request->input('queue', 'default');

            // Resume any paused queues so new workers don't start in paused state
            foreach ($this->queueManager->getAll($connection) as $pausedQueue) {
                if ($this->queueManager->isPaused($pausedQueue, $connection)) {
                    $this->queueManager->resume($pausedQueue, $connection);
                }
            }

            $result = $this->processManager->startWorker(
                $connection,
                $queue,
                (int) $request->input('workers', 1),
            );

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to start worker');
        }
    }

    /**
     * Stop workers for a connection.
     */
    public function stopWorker(Request $request): JsonResponse
    {
        $request->validate([
            'connection' => 'required|string',
        ]);

        try {
            $result = $this->processManager->stopWorker($request->input('connection'));

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to stop worker');
        }
    }

    /**
     * Stop an external worker by PID.
     */
    public function stopExternalWorker(Request $request): JsonResponse
    {
        $request->validate([
            'pid' => 'required|integer',
        ]);

        try {
            $result = $this->processManager->stopExternalWorker((int) $request->input('pid'));

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to stop external worker');
        }
    }

    /**
     * Get supervisor status.
     */
    public function supervisorStatus(): JsonResponse
    {
        try {
            return response()->json($this->processManager->getSupervisorStatus());
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to get supervisor status');
        }
    }

    /**
     * Start supervisor.
     */
    public function startSupervisor(Request $request): JsonResponse
    {
        $request->validate([
            'connection' => 'required|string',
            'queue' => 'string',
            'workers' => 'integer|min:1|max:10',
        ]);

        try {
            $result = $this->processManager->startSupervisor(
                $request->input('connection'),
                $request->input('queue', 'default'),
                (int) $request->input('workers', 1),
            );

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to start supervisor');
        }
    }

    /**
     * Stop supervisor.
     */
    public function stopSupervisor(): JsonResponse
    {
        try {
            $result = $this->processManager->stopSupervisor();

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to stop supervisor');
        }
    }

    /**
     * Get queue connection details for the Queues page.
     */
    public function queueConnections(): JsonResponse
    {
        return response()->json($this->getQueueConnectionDetails());
    }

    /**
     * Pause a queue.
     */
    public function pauseQueue(Request $request): JsonResponse
    {
        $queue = $request->input('queue');
        $connection = $request->input('connection', config('station.default'));

        if (!$queue) {
            return response()->json(['error' => 'Queue name is required'], 400);
        }

        try {
            $this->queueManager->pause($queue, $connection);

            return response()->json(['message' => "Queue {$queue} paused"]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to pause queue');
        }
    }

    /**
     * Resume a queue.
     */
    public function resumeQueue(Request $request): JsonResponse
    {
        $queue = $request->input('queue');
        $connection = $request->input('connection', config('station.default'));

        if (!$queue) {
            return response()->json(['error' => 'Queue name is required'], 400);
        }

        try {
            $this->queueManager->resume($queue, $connection);

            return response()->json(['message' => "Queue {$queue} resumed"]);
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to resume queue');
        }
    }

    /**
     * Get queue pause status for all connections.
     */
    public function queuePauseStatus(): JsonResponse
    {
        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);
        $statuses = [];

        foreach ($queueConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            try {
                $status = $this->queueManager->status($name);
                $statuses[$name] = $status;
            } catch (Throwable) {
                $statuses[$name] = ['paused' => false];
            }
        }

        return response()->json($statuses);
    }

    /**
     * Build queue connection details for the Queues page.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getQueueConnectionDetails(): array
    {
        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);
        $connectivity = $this->healthChecker->checkConnectivityQuick();

        try {
            $workerStatus = $this->processManager->getWorkerStatus();
        } catch (Throwable) {
            $workerStatus = [];
        }

        $connections = [];
        $defaultConnection = config('queue.default');

        foreach ($queueConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            $canonicalDriver = str_replace('station-', '', $driver);

            try {
                $pauseStatus = $this->queueManager->status($name);
            } catch (Throwable) {
                $pauseStatus = [];
            }

            // status() returns array keyed by queue name, check if any queue is paused
            $isPaused = false;
            foreach ($pauseStatus as $queueStatus) {
                if ($queueStatus['paused'] ?? false) {
                    $isPaused = true;

                    break;
                }
            }

            $connStatus = $connectivity[$name] ?? null;
            $connections[$name] = [
                'name' => $name,
                'driver' => $canonicalDriver,
                'is_default' => $name === $defaultConnection,
                'connected' => $connStatus->connected ?? false,
                'latency_ms' => $connStatus->latency_ms ?? 0,
                'dashboard_url' => $connStatus?->dashboard_url,
                'workers' => \count(array_filter($workerStatus[$name]['workers'] ?? [], static fn($w) => $w['role'] !== 'supervisor')),
                'paused' => $isPaused,
                'config' => $this->sanitizeConnectionConfig($connectionConfig, $canonicalDriver),
            ];
        }

        return $connections;
    }

    /**
     * Sanitize connection config to remove secrets.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function sanitizeConnectionConfig(array $config, string $driver): array
    {
        if ($driver === 'rabbitmq') {
            return $this->sanitizeRabbitMQConfig($config);
        }

        $allowedKeys = match ($driver) {
            'redis' => ['connection', 'queue', 'retry_after', 'block_for'],
            'sqs' => ['region', 'prefix', 'queue', 'suffix', 'visibility_timeout', 'wait_time'],
            'beanstalkd' => ['host', 'port', 'queue', 'ttr', 'reserve_timeout'],
            'kafka' => ['brokers', 'topic', 'group_id', 'consumer_timeout'],
            default => ['queue'],
        };

        $sanitized = [];
        foreach ($allowedKeys as $key) {
            if (\array_key_exists($key, $config)) {
                $sanitized[$key] = $config[$key];
            }
        }

        return $sanitized;
    }

    /**
     * Flatten RabbitMQ config from nested structures to scalar values.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function sanitizeRabbitMQConfig(array $config): array
    {
        $firstHost = $config['hosts'][0] ?? [];
        $exchange = $config['exchange'] ?? [];
        $options = $config['options'] ?? [];

        $sanitized = [
            'queue' => $config['queue'] ?? null,
            'host' => $firstHost['host'] ?? $config['host'] ?? null,
            'port' => $firstHost['port'] ?? $config['port'] ?? null,
            'vhost' => $firstHost['vhost'] ?? $config['vhost'] ?? null,
        ];

        if (\is_array($exchange)) {
            $sanitized['exchange'] = $exchange['name'] ?? null;
            $sanitized['exchange_type'] = $exchange['type'] ?? null;
        } else {
            $sanitized['exchange'] = $exchange;
        }

        if (\is_array($options) && isset($options['heartbeat'])) {
            $sanitized['heartbeat'] = $options['heartbeat'];
        } elseif (isset($config['heartbeat'])) {
            $sanitized['heartbeat'] = $config['heartbeat'];
        }

        return array_filter($sanitized, static fn($v) => $v !== null);
    }
}
