<?php

declare(strict_types=1);

namespace Station\Recovery;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Station\Contracts\HealthCheckerInterface;
use Station\DTOs\ConnectionStatus;
use Station\DTOs\HealthCheckResult;
use Station\Enums\Driver;
use Station\Enums\HealthStatus;
use Throwable;

class HealthChecker implements HealthCheckerInterface
{
    /** @var array<string, array{name: string, dashboard_url: string|null}> Driver dashboard URLs */
    private const DRIVER_DASHBOARDS = [
        'rabbitmq' => [
            'name' => 'RabbitMQ',
            'dashboard_url' => 'http://localhost:15672',
        ],
        'redis' => [
            'name' => 'Redis',
            'dashboard_url' => null,
        ],
        'sqs' => [
            'name' => 'Amazon SQS',
            'dashboard_url' => null,
        ],
        'beanstalkd' => [
            'name' => 'Beanstalkd',
            'dashboard_url' => 'http://localhost:2080',
        ],
        'kafka' => [
            'name' => 'Kafka',
            'dashboard_url' => 'http://localhost:8080',
        ],
        'database' => [
            'name' => 'Database',
            'dashboard_url' => null,
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly QueueFactory $queueManager,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Check if health checking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    /**
     * Run all health checks.
     */
    public function check(): HealthCheckResult
    {
        $checks = [];
        $overallStatus = HealthStatus::Healthy;

        // Database check
        if ($this->config['checks']['database']['enabled'] ?? true) {
            $checks['database'] = $this->checkDatabase();
            if ($checks['database']['status'] !== HealthStatus::Healthy->value) {
                $overallStatus = HealthStatus::Unhealthy;
            }
        }

        // RabbitMQ check would need to be implemented with the driver
        // For now, we'll mark it as healthy if not checked
        if ($this->config['checks']['rabbitmq']['enabled'] ?? true) {
            $checks['rabbitmq'] = [
                'status' => HealthStatus::Healthy->value,
                'latency_ms' => 0,
                'last_check' => now()->toIso8601String(),
                'message' => 'Check not implemented - requires driver',
            ];
        }

        // Disk check
        if ($this->config['checks']['disk']['enabled'] ?? true) {
            $checks['disk'] = $this->checkDisk();
            if ($checks['disk']['status'] === HealthStatus::Unhealthy->value) {
                $overallStatus = HealthStatus::Unhealthy;
            } elseif ($checks['disk']['status'] === HealthStatus::Warning->value && $overallStatus === HealthStatus::Healthy) {
                $overallStatus = HealthStatus::Degraded;
            }
        }

        // Add connection health checks
        $connections = $this->checkConnections();
        $connectionsArray = array_map(static fn(ConnectionStatus $cs): array => $cs->toArray(), $connections);

        return new HealthCheckResult(
            status: $overallStatus->value,
            timestamp: now()->toIso8601String(),
            checks: $checks,
            connections: $connectionsArray,
        );
    }

    /**
     * Check connectivity to all configured queue drivers (deep check via driver ->size()).
     *
     * @return array<string, ConnectionStatus>
     */
    public function checkConnections(): array
    {
        $connections = [];

        // Get queue connections from config
        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);

        foreach ($queueConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            $connections[$name] = $this->checkConnection($name, $driver);
        }

        return $connections;
    }

    /**
     * Lightweight TCP connectivity check for dashboard polling.
     *
     * Uses fsockopen with short timeout (~2s) per driver. Indicates network
     * reachability only (port open), NOT that the queue service is healthy.
     * SQS is skipped (cloud service, no TCP check).
     *
     * @return array<string, ConnectionStatus>
     */
    public function checkConnectivityQuick(): array
    {
        $connections = [];

        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);

        /** @var array<string, string> $configuredUrls */
        $configuredUrls = config('station.dashboard.driver_urls', []);

        foreach ($queueConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            // Resolve canonical driver name for Station drivers (station-redis -> redis)
            $canonicalDriver = str_replace('station-', '', $driver);

            // Get dashboard URL from config or fallback constant
            $dashboardUrl = $configuredUrls[$canonicalDriver]
                ?? self::DRIVER_DASHBOARDS[$canonicalDriver]['dashboard_url']
                ?? self::DRIVER_DASHBOARDS[$driver]['dashboard_url']
                ?? null;

            // Skip SQS (cloud service, no TCP check)
            if (\in_array($canonicalDriver, ['sqs'], true)) {
                $connections[$name] = new ConnectionStatus(
                    connected: true,
                    latency_ms: 0,
                    driver: $canonicalDriver,
                    dashboard_url: $dashboardUrl,
                );

                continue;
            }

            // Extract host/port from connection config
            [$host, $port] = $this->extractHostPort($connectionConfig, $canonicalDriver);

            if ($host === null || $port === null) {
                $connections[$name] = new ConnectionStatus(
                    connected: false,
                    latency_ms: 0,
                    driver: $canonicalDriver,
                    dashboard_url: $dashboardUrl,
                );

                continue;
            }

            $start = microtime(true);
            $connected = $this->probeConnection($host, $port, $canonicalDriver);
            $latency = (int) ((microtime(true) - $start) * 1000);

            $connections[$name] = new ConnectionStatus(
                connected: $connected,
                latency_ms: $connected ? $latency : 0,
                driver: $canonicalDriver,
                dashboard_url: $dashboardUrl,
            );
        }

        return $connections;
    }

    /**
     * Check database connectivity.
     *
     * @return array{status: string, latency_ms: int, last_check: string, message?: string}
     */
    public function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            $this->database->connection()->getPdo();
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => HealthStatus::Healthy->value,
                'latency_ms' => $latency,
                'last_check' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            return [
                'status' => HealthStatus::Unhealthy->value,
                'latency_ms' => 0,
                'last_check' => now()->toIso8601String(),
                'message' => $this->sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Check disk space.
     *
     * @return array{status: string, used_percent: float, last_check: string}
     */
    public function checkDisk(): array
    {
        $path = $this->config['checks']['disk']['path'] ?? storage_path();
        $warningThreshold = $this->config['checks']['disk']['warning_threshold'] ?? 90;
        $criticalThreshold = $this->config['checks']['disk']['critical_threshold'] ?? 95;

        $totalSpace = disk_total_space($path);
        $freeSpace = disk_free_space($path);

        if ($totalSpace === false || $freeSpace === false) {
            return [
                'status' => HealthStatus::Unhealthy->value,
                'used_percent' => 0,
                'last_check' => now()->toIso8601String(),
            ];
        }

        $usedPercent = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2);

        $status = HealthStatus::Healthy;
        if ($usedPercent >= $criticalThreshold) {
            $status = HealthStatus::Unhealthy;
        } elseif ($usedPercent >= $warningThreshold) {
            $status = HealthStatus::Warning;
        }

        return [
            'status' => $status->value,
            'used_percent' => $usedPercent,
            'last_check' => now()->toIso8601String(),
        ];
    }

    /**
     * Get the health check endpoint response.
     */
    public function getResponse(): HealthCheckResult
    {
        return $this->check();
    }

    /**
     * Check if a queue driver is alive via TCP connect + application-level probe.
     *
     * A bare TCP check is insufficient: the port can be open (kernel accepts
     * connections) while the service process is frozen or unresponsive. This
     * method sends a lightweight protocol command after connecting and verifies
     * the service actually responds.
     *
     * Protected so tests can override without making real connections.
     */
    protected function probeConnection(string $host, int $port, string $driver, int $timeout = 2): bool
    {
        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

            if ($socket === false) {
                return false;
            }

            stream_set_timeout($socket, $timeout);

            $alive = match ($driver) {
                'redis' => $this->probeRedis($socket),
                'rabbitmq' => $this->probeRabbitMQ($socket),
                'beanstalkd' => $this->probeBeanstalkd($socket),
                'kafka' => $this->probeKafka($socket),
                default => true,
            };

            fclose($socket);

            return $alive;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check connectivity to a specific queue connection.
     */
    private function checkConnection(string $name, string $driver): ConnectionStatus
    {
        $start = microtime(true);

        // Get dashboard URL for known drivers
        $dashboardUrl = self::DRIVER_DASHBOARDS[$driver]['dashboard_url'] ?? null;

        // Allow config override for dashboard URL
        $configDashboardUrl = config("queue.connections.{$name}.dashboard_url");
        if ($configDashboardUrl !== null) {
            $dashboardUrl = $configDashboardUrl;
        }

        try {
            // Try to get the connection to verify it works
            $connection = $this->queueManager->connection($name);

            // For database driver, we already check database separately
            if ($driver === 'database') {
                return new ConnectionStatus(
                    connected: true,
                    latency_ms: (int) ((microtime(true) - $start) * 1000),
                    driver: $driver,
                    dashboard_url: $dashboardUrl,
                );
            }

            // Try to get queue size to verify connection works
            $connection->size();

            $latency = (int) ((microtime(true) - $start) * 1000);

            return new ConnectionStatus(
                connected: true,
                latency_ms: $latency,
                driver: $driver,
                dashboard_url: $dashboardUrl,
            );
        } catch (Throwable $e) {
            return new ConnectionStatus(
                connected: false,
                latency_ms: 0,
                driver: $driver,
                dashboard_url: $dashboardUrl,
                message: $this->sanitizeErrorMessage($e->getMessage()),
            );
        }
    }

    /**
     * Probe Redis: send PING, expect any response (+PONG or -NOAUTH).
     *
     * @param resource $socket
     */
    private function probeRedis($socket): bool
    {
        @fwrite($socket, "PING\r\n");
        $response = @fgets($socket, 64);

        return $response !== false && \strlen(trim($response)) > 0;
    }

    /**
     * Probe RabbitMQ: send AMQP 0-9-1 protocol header, expect a response.
     *
     * @param resource $socket
     */
    private function probeRabbitMQ($socket): bool
    {
        @fwrite($socket, "AMQP\x00\x00\x09\x01");
        $response = @fread($socket, 8);

        return $response !== false && \strlen($response) > 0;
    }

    /**
     * Probe Beanstalkd: send stats command, expect OK response.
     *
     * @param resource $socket
     */
    private function probeBeanstalkd($socket): bool
    {
        @fwrite($socket, "stats\r\n");
        $response = @fgets($socket, 64);

        return $response !== false && str_starts_with($response, 'OK');
    }

    /**
     * Probe Kafka: send ApiVersions v0 request, expect a response.
     *
     * @param resource $socket
     */
    private function probeKafka($socket): bool
    {
        // ApiVersions (key=18) v0 with correlation_id=1, empty client_id
        $header = pack('nnNn', 18, 0, 1, 0);
        $message = pack('N', \strlen($header)) . $header;

        @fwrite($socket, $message);
        $response = @fread($socket, 4);

        return $response !== false && \strlen($response) === 4;
    }

    /**
     * Extract host and port from a queue connection config.
     *
     * @param array<string, mixed> $config
     * @return array{0: string|null, 1: int|null}
     */
    private function extractHostPort(array $config, string $driver): array
    {
        return match ($driver) {
            'rabbitmq' => $this->extractRabbitMQHostPort($config),
            'redis' => $this->extractRedisHostPort($config),
            'beanstalkd' => [
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 11300),
            ],
            'kafka' => $this->extractKafkaHostPort($config),
            default => [null, null],
        };
    }

    /**
     * Extract host/port from RabbitMQ config (supports hosts array).
     *
     * @param array<string, mixed> $config
     * @return array{0: string|null, 1: int|null}
     */
    private function extractRabbitMQHostPort(array $config): array
    {
        $hosts = $config['hosts'] ?? [];

        if (\is_array($hosts) && $hosts !== []) {
            $firstHost = reset($hosts);

            return [
                $firstHost['host'] ?? '127.0.0.1',
                (int) ($firstHost['port'] ?? 5672),
            ];
        }

        return [
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 5672),
        ];
    }

    /**
     * Extract host/port from Redis config (resolves connection name).
     *
     * @param array<string, mixed> $config
     * @return array{0: string|null, 1: int|null}
     */
    private function extractRedisHostPort(array $config): array
    {
        $redisConnection = $config['connection'] ?? 'default';

        /** @var array<string, mixed> $redisConfig */
        $redisConfig = config("database.redis.{$redisConnection}", []);

        return [
            $redisConfig['host'] ?? '127.0.0.1',
            (int) ($redisConfig['port'] ?? 6379),
        ];
    }

    /**
     * Extract host/port from Kafka brokers string (uses first broker).
     *
     * @param array<string, mixed> $config
     * @return array{0: string|null, 1: int|null}
     */
    private function extractKafkaHostPort(array $config): array
    {
        $brokers = $config['brokers'] ?? '127.0.0.1:9092';
        $firstBroker = explode(',', $brokers)[0];
        $parts = explode(':', trim($firstBroker));

        return [
            $parts[0],
            (int) ($parts[1] ?? 9092),
        ];
    }

    /**
     * Sanitize error messages to avoid exposing sensitive info.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Remove potential credentials from error messages
        $message = preg_replace('/:[^@]+@/', ':***@', $message) ?? $message;

        // Truncate long messages
        if (\strlen($message) > 200) {
            $message = substr($message, 0, 197) . '...';
        }

        return $message;
    }
}
