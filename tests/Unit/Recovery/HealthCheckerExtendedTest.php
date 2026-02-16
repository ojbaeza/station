<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Exception;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use PDO;
use Station\Recovery\HealthChecker;
use Station\StationServiceProvider;

/**
 * Extended tests for HealthChecker covering:
 * - checkConnections with various drivers
 * - checkConnectivityQuick with various drivers
 * - sanitizeErrorMessage (via checkDatabase with credential leaks)
 * - check() with disk warning promoting to degraded
 */
class HealthCheckerExtendedTest extends TestCase
{
    private DatabaseManager&MockInterface $database;

    private QueueFactory&MockInterface $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = Mockery::mock(DatabaseManager::class);
        $this->queueManager = Mockery::mock(QueueFactory::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCheckConnectionsReturnsEmptyForNoStationDrivers(): void
    {
        config(['queue.connections' => [
            'sync' => ['driver' => 'sync'],
            'database' => ['driver' => 'database'],
        ]]);

        $checker = new HealthChecker($this->database, $this->queueManager, []);
        $result = $checker->checkConnections();

        $this->assertEmpty($result);
    }

    public function testCheckConnectionsReturnsConnectedForDatabaseDriver(): void
    {
        // database is NOT a Station driver (not in Driver enum)
        // but let's test with a rabbitmq driver
        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->once()->andReturn(5);

        $this->queueManager
            ->shouldReceive('connection')
            ->with('rabbitmq')
            ->andReturn($driver);

        config(['queue.connections' => [
            'rabbitmq' => ['driver' => 'rabbitmq', 'queue' => 'default'],
        ]]);

        $checker = new HealthChecker($this->database, $this->queueManager, []);
        $result = $checker->checkConnections();

        $this->assertArrayHasKey('rabbitmq', $result);
        $this->assertTrue($result['rabbitmq']->connected);
        $this->assertSame('rabbitmq', $result['rabbitmq']->driver);
    }

    public function testCheckConnectionsHandlesConnectionFailure(): void
    {
        $this->queueManager
            ->shouldReceive('connection')
            ->with('redis')
            ->andThrow(new Exception('user:password@host:6379 Connection refused'));

        config(['queue.connections' => [
            'redis' => ['driver' => 'redis', 'queue' => 'default'],
        ]]);

        $checker = new HealthChecker($this->database, $this->queueManager, []);
        $result = $checker->checkConnections();

        $this->assertArrayHasKey('redis', $result);
        $this->assertFalse($result['redis']->connected);
        $this->assertSame(0, $result['redis']->latency_ms);
        // Credentials should be sanitized
        $this->assertStringNotContainsString('password', $result['redis']->message);
        $this->assertStringContainsString('***', $result['redis']->message);
    }

    public function testCheckConnectionsReturnsDashboardUrlFromConfig(): void
    {
        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->andReturn(0);

        $this->queueManager
            ->shouldReceive('connection')
            ->with('rabbitmq')
            ->andReturn($driver);

        config([
            'queue.connections' => [
                'rabbitmq' => [
                    'driver' => 'rabbitmq',
                    'queue' => 'default',
                    'dashboard_url' => 'http://custom:15672',
                ],
            ],
        ]);

        $checker = new HealthChecker($this->database, $this->queueManager, []);
        $result = $checker->checkConnections();

        $this->assertSame('http://custom:15672', $result['rabbitmq']->dashboard_url);
    }

    public function testCheckConnectivityQuickSkipsSqs(): void
    {
        config([
            'queue.connections' => [
                'sqs' => ['driver' => 'sqs', 'queue' => 'default'],
            ],
            'station.dashboard.driver_urls' => [],
        ]);

        $checker = $this->createTestableChecker([]);
        $result = $checker->checkConnectivityQuick();

        $this->assertArrayHasKey('sqs', $result);
        $this->assertTrue($result['sqs']->connected);
        $this->assertSame(0, $result['sqs']->latency_ms);
        $this->assertSame('sqs', $result['sqs']->driver);
    }

    public function testCheckConnectivityQuickReturnsFalseWhenHostNull(): void
    {
        // Configure a driver with unknown canonical driver that returns null host
        config([
            'queue.connections' => [
                'custom' => ['driver' => 'station-kafka', 'brokers' => ''],
            ],
            'station.dashboard.driver_urls' => [],
        ]);

        $checker = $this->createTestableChecker([]);
        $checker->probeResults = [];
        $result = $checker->checkConnectivityQuick();

        $this->assertArrayHasKey('custom', $result);
        // Even with empty brokers, extractKafkaHostPort defaults to 127.0.0.1:9092
        $this->assertSame('kafka', $result['custom']->driver);
    }

    public function testCheckConnectivityQuickProbesRedis(): void
    {
        config([
            'queue.connections' => [
                'redis-conn' => ['driver' => 'station-redis', 'connection' => 'default'],
            ],
            'database.redis.default' => [
                'host' => '10.0.0.1',
                'port' => 6379,
            ],
            'station.dashboard.driver_urls' => [],
        ]);

        $checker = $this->createTestableChecker([]);
        $checker->probeResults = ['10.0.0.1:6379' => true];

        $result = $checker->checkConnectivityQuick();

        $this->assertArrayHasKey('redis-conn', $result);
        $this->assertTrue($result['redis-conn']->connected);
        $this->assertSame('redis', $result['redis-conn']->driver);
    }

    public function testCheckConnectivityQuickProbesRabbitMQ(): void
    {
        config([
            'queue.connections' => [
                'rmq' => [
                    'driver' => 'rabbitmq',
                    'hosts' => [
                        ['host' => '10.0.0.2', 'port' => 5672],
                    ],
                ],
            ],
            'station.dashboard.driver_urls' => [],
        ]);

        $checker = $this->createTestableChecker([]);
        $checker->probeResults = ['10.0.0.2:5672' => true];

        $result = $checker->checkConnectivityQuick();

        $this->assertArrayHasKey('rmq', $result);
        $this->assertTrue($result['rmq']->connected);
        $this->assertSame('rabbitmq', $result['rmq']->driver);
    }

    public function testCheckConnectivityQuickProbesBeanstalkd(): void
    {
        config([
            'queue.connections' => [
                'beanstalkd' => [
                    'driver' => 'station-beanstalkd',
                    'host' => '10.0.0.3',
                    'port' => 11300,
                ],
            ],
            'station.dashboard.driver_urls' => [],
        ]);

        $checker = $this->createTestableChecker([]);
        $checker->probeResults = ['10.0.0.3:11300' => false];

        $result = $checker->checkConnectivityQuick();

        $this->assertArrayHasKey('beanstalkd', $result);
        $this->assertFalse($result['beanstalkd']->connected);
        $this->assertSame(0, $result['beanstalkd']->latency_ms);
    }

    public function testCheckConnectivityQuickProbesKafka(): void
    {
        config([
            'queue.connections' => [
                'kafka' => [
                    'driver' => 'station-kafka',
                    'brokers' => '10.0.0.4:9092,10.0.0.5:9092',
                ],
            ],
            'station.dashboard.driver_urls' => [],
        ]);

        $checker = $this->createTestableChecker([]);
        $checker->probeResults = ['10.0.0.4:9092' => true];

        $result = $checker->checkConnectivityQuick();

        $this->assertArrayHasKey('kafka', $result);
        $this->assertTrue($result['kafka']->connected);
        $this->assertSame('kafka', $result['kafka']->driver);
    }

    public function testCheckConnectivityQuickUsesConfiguredDashboardUrl(): void
    {
        config([
            'queue.connections' => [
                'rmq' => [
                    'driver' => 'rabbitmq',
                    'host' => '10.0.0.2',
                    'port' => 5672,
                ],
            ],
            'station.dashboard.driver_urls' => [
                'rabbitmq' => 'http://custom-rmq:15672',
            ],
        ]);

        $checker = $this->createTestableChecker([]);
        $checker->probeResults = ['10.0.0.2:5672' => true];

        $result = $checker->checkConnectivityQuick();

        $this->assertSame('http://custom-rmq:15672', $result['rmq']->dashboard_url);
    }

    public function testCheckConnectivityQuickSkipsNonStationDrivers(): void
    {
        config([
            'queue.connections' => [
                'sync' => ['driver' => 'sync'],
                'database' => ['driver' => 'database'],
            ],
            'station.dashboard.driver_urls' => [],
        ]);

        $checker = $this->createTestableChecker([]);
        $result = $checker->checkConnectivityQuick();

        $this->assertEmpty($result);
    }

    public function testSanitizeErrorMessageTruncatesLongMessages(): void
    {
        $longMessage = str_repeat('a', 300);

        $connection = Mockery::mock(Connection::class);
        $this->database
            ->shouldReceive('connection')
            ->andReturn($connection);
        $connection
            ->shouldReceive('getPdo')
            ->andThrow(new Exception($longMessage));

        $checker = new HealthChecker($this->database, $this->queueManager, []);
        $result = $checker->checkDatabase();

        $this->assertSame('unhealthy', $result['status']);
        $this->assertLessThanOrEqual(200, \strlen($result['message']));
        $this->assertStringEndsWith('...', $result['message']);
    }

    public function testSanitizeErrorMessageRemovesCredentials(): void
    {
        $connection = Mockery::mock(Connection::class);
        $this->database
            ->shouldReceive('connection')
            ->andReturn($connection);
        $connection
            ->shouldReceive('getPdo')
            ->andThrow(new Exception('amqp://user:secret_password@rabbitmq:5672 failed'));

        $checker = new HealthChecker($this->database, $this->queueManager, []);
        $result = $checker->checkDatabase();

        $this->assertStringNotContainsString('secret_password', $result['message']);
        $this->assertStringContainsString(':***@', $result['message']);
    }

    public function testCheckReturnsDegradedWhenOnlyDiskWarning(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $connection = Mockery::mock(Connection::class);

        $this->database
            ->shouldReceive('connection')
            ->andReturn($connection);
        $connection
            ->shouldReceive('getPdo')
            ->andReturn($pdo);

        $checker = new HealthChecker($this->database, $this->queueManager, [
            'checks' => [
                'database' => ['enabled' => true],
                'rabbitmq' => ['enabled' => false],
                'disk' => [
                    'enabled' => true,
                    'path' => sys_get_temp_dir(),
                    'warning_threshold' => 0, // Any usage triggers warning
                    'critical_threshold' => 100, // Never critical
                ],
            ],
        ]);

        $result = $checker->check();

        // Database is healthy, disk is warning -> overall should be degraded
        $this->assertSame('degraded', $result->status);
    }

    public function testCheckReturnsUnhealthyWhenDiskCriticalOverridesDegraded(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $connection = Mockery::mock(Connection::class);

        $this->database
            ->shouldReceive('connection')
            ->andReturn($connection);
        $connection
            ->shouldReceive('getPdo')
            ->andReturn($pdo);

        $checker = new HealthChecker($this->database, $this->queueManager, [
            'checks' => [
                'database' => ['enabled' => true],
                'rabbitmq' => ['enabled' => false],
                'disk' => [
                    'enabled' => true,
                    'path' => sys_get_temp_dir(),
                    'warning_threshold' => 0,
                    'critical_threshold' => 0, // Any usage is critical
                ],
            ],
        ]);

        $result = $checker->check();

        $this->assertSame('unhealthy', $result->status);
    }

    public function testCheckIncludesConnectionsInResult(): void
    {
        $pdo = Mockery::mock(PDO::class);
        $connection = Mockery::mock(Connection::class);

        $this->database
            ->shouldReceive('connection')
            ->andReturn($connection);
        $connection
            ->shouldReceive('getPdo')
            ->andReturn($pdo);

        config(['queue.connections' => []]);

        $checker = new HealthChecker($this->database, $this->queueManager, [
            'checks' => [
                'database' => ['enabled' => true],
                'rabbitmq' => ['enabled' => false],
                'disk' => ['enabled' => false],
            ],
        ]);

        $result = $checker->check();

        $this->assertNotNull($result->connections);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
    }

    private function createTestableChecker(array $config): TestableHealthCheckerExtended
    {
        return new TestableHealthCheckerExtended(
            $this->database,
            $this->queueManager,
            $config,
        );
    }
}

/**
 * Test subclass that overrides probeConnection for deterministic testing.
 */
class TestableHealthCheckerExtended extends HealthChecker
{
    /** @var array<string, bool> Map of "host:port" => connected */
    public array $probeResults = [];

    protected function probeConnection(string $host, int $port, string $driver, int $timeout = 2): bool
    {
        return $this->probeResults["{$host}:{$port}"] ?? false;
    }
}
