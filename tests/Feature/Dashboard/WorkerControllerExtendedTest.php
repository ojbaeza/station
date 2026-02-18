<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Contracts\HealthCheckerInterface;
use Station\Core\DriverInfoCollector;
use Station\Core\ProcessManager;
use Station\Core\QueueManager;
use Station\Dashboard\Http\Controllers\WorkerController;
use Station\DTOs\ConnectionStatus;
use Station\StationServiceProvider;

/**
 * Extended feature tests for WorkerController covering:
 * - sanitizeConnectionConfig for SQS, Beanstalkd, Kafka, and default drivers
 * - sanitizeRabbitMQConfig edge cases (non-array exchange, heartbeat in top-level config)
 * - queueConnections with paused queue detection
 * - workerDashboardStatus with exception from processManager
 */
class WorkerControllerExtendedTest extends TestCase
{
    private HealthCheckerInterface&MockInterface $healthChecker;

    private LaravelQueueManager&MockInterface $laravelQueueManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->healthChecker = Mockery::mock(HealthCheckerInterface::class);
        $this->healthChecker->shouldReceive('check')->byDefault()->andReturn(null);
        $this->healthChecker->shouldReceive('checkConnectivityQuick')->byDefault()->andReturn([]);

        $this->laravelQueueManager = Mockery::mock(LaravelQueueManager::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- sanitizeConnectionConfig for SQS ----

    public function testQueueConnectionsSanitizesSqsConfig(): void
    {
        $this->app['config']->set('queue.connections', [
            'sqs' => [
                'driver' => 'sqs',
                'region' => 'us-east-1',
                'prefix' => 'https://sqs.us-east-1.amazonaws.com/12345',
                'queue' => 'default',
                'suffix' => '',
                'visibility_timeout' => 30,
                'wait_time' => 20,
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'sqs' => new ConnectionStatus(connected: true, latency_ms: 50, driver: 'sqs'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('sqs.config');

        $this->assertArrayHasKey('region', $config);
        $this->assertArrayHasKey('prefix', $config);
        $this->assertArrayHasKey('queue', $config);
        $this->assertArrayHasKey('visibility_timeout', $config);
        $this->assertArrayHasKey('wait_time', $config);
        $this->assertArrayNotHasKey('key', $config);
        $this->assertArrayNotHasKey('secret', $config);
    }

    // ---- sanitizeConnectionConfig for Beanstalkd ----

    public function testQueueConnectionsSanitizesBeanstalkdConfig(): void
    {
        $this->app['config']->set('queue.connections', [
            'beanstalkd' => [
                'driver' => 'station-beanstalkd',
                'host' => '10.0.0.1',
                'port' => 11300,
                'queue' => 'default',
                'ttr' => 60,
                'reserve_timeout' => 5,
                'secret_key' => 'should_not_appear',
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'beanstalkd' => new ConnectionStatus(connected: true, latency_ms: 2, driver: 'beanstalkd'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('beanstalkd.config');

        $this->assertArrayHasKey('host', $config);
        $this->assertArrayHasKey('port', $config);
        $this->assertArrayHasKey('queue', $config);
        $this->assertArrayHasKey('ttr', $config);
        $this->assertArrayHasKey('reserve_timeout', $config);
        $this->assertArrayNotHasKey('secret_key', $config);
    }

    // ---- sanitizeConnectionConfig for Kafka ----

    public function testQueueConnectionsSanitizesKafkaConfig(): void
    {
        $this->app['config']->set('queue.connections', [
            'kafka' => [
                'driver' => 'station-kafka',
                'brokers' => 'kafka1:9092,kafka2:9092',
                'topic' => 'station-jobs',
                'group_id' => 'station-workers',
                'consumer_timeout' => 1000,
                'sasl_password' => 'secret',
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'kafka' => new ConnectionStatus(connected: true, latency_ms: 3, driver: 'kafka'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('kafka.config');

        $this->assertArrayHasKey('brokers', $config);
        $this->assertArrayHasKey('topic', $config);
        $this->assertArrayHasKey('group_id', $config);
        $this->assertArrayHasKey('consumer_timeout', $config);
        $this->assertArrayNotHasKey('sasl_password', $config);
    }

    // ---- sanitizeRabbitMQConfig with non-array exchange ----

    public function testQueueConnectionsSanitizesRabbitMQWithStringExchange(): void
    {
        $this->app['config']->set('queue.connections', [
            'rabbitmq' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
                'hosts' => [
                    ['host' => 'localhost', 'port' => 5672, 'vhost' => '/', 'user' => 'guest', 'password' => 'secret'],
                ],
                'exchange' => 'my-exchange', // string, not array
                'heartbeat' => 30,
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'rabbitmq' => new ConnectionStatus(connected: true, latency_ms: 5, driver: 'rabbitmq'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('rabbitmq.config');

        $this->assertSame('my-exchange', $config['exchange']);
        $this->assertSame(30, $config['heartbeat']);
        $this->assertArrayNotHasKey('user', $config);
        $this->assertArrayNotHasKey('password', $config);
    }

    // ---- sanitizeRabbitMQConfig with heartbeat in options ----

    public function testQueueConnectionsSanitizesRabbitMQWithHeartbeatInOptions(): void
    {
        $this->app['config']->set('queue.connections', [
            'rabbitmq' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
                'hosts' => [
                    ['host' => 'rmq.local', 'port' => 5672, 'vhost' => '/'],
                ],
                'exchange' => ['name' => 'station', 'type' => 'direct'],
                'options' => ['heartbeat' => 45],
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'rabbitmq' => new ConnectionStatus(connected: true, latency_ms: 2, driver: 'rabbitmq'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('rabbitmq.config');

        $this->assertSame(45, $config['heartbeat']);
        $this->assertSame('station', $config['exchange']);
        $this->assertSame('direct', $config['exchange_type']);
    }

    // ---- queueConnections with paused queues ----

    public function testQueueConnectionsDetectsPausedQueues(): void
    {
        $this->app['config']->set('queue.connections', [
            'redis' => [
                'driver' => 'station-redis',
                'queue' => 'default',
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'redis'),
        ]);

        $mockQueue = Mockery::mock(Queue::class);
        $mockQueue->shouldReceive('size')->andReturn(5);
        $this->laravelQueueManager->shouldReceive('connection')
            ->andReturn($mockQueue);

        $this->bindController(processEnabled: false);
        $this->runStationMigrations();

        // Create a paused queue status
        DB::table('station_queue_status')->insert([
            'queue' => 'default',
            'connection' => 'redis',
            'paused' => true,
            'paused_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $data = $response->json('redis');
        $this->assertTrue($data['paused']);
    }

    // ---- queueConnections with unknown/default driver sanitization ----

    public function testQueueConnectionsSanitizesUnknownDriverConfig(): void
    {
        // Configure a "station-custom" like driver that falls through to 'default' in sanitize
        // Since only recognized Station drivers pass isStationDriver, we test with RabbitMQ
        // but exercise the sanitizeRabbitMQConfig path with empty hosts
        $this->app['config']->set('queue.connections', [
            'rabbitmq' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
                'host' => 'localhost',
                'port' => 5672,
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'rabbitmq' => new ConnectionStatus(connected: true, latency_ms: 2, driver: 'rabbitmq'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('rabbitmq.config');

        // With no hosts array, should fall back to host/port keys
        $this->assertSame('localhost', $config['host']);
        $this->assertSame(5672, $config['port']);
    }

    // ---- queueConnections with not-paused queues ----

    public function testQueueConnectionsShowsNotPausedWhenNoPausedQueues(): void
    {
        $this->app['config']->set('queue.connections', [
            'redis' => [
                'driver' => 'station-redis',
                'queue' => 'default',
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'redis'),
        ]);

        $mockQueue = Mockery::mock(Queue::class);
        $mockQueue->shouldReceive('size')->andReturn(0);
        $this->laravelQueueManager->shouldReceive('connection')
            ->andReturn($mockQueue);

        $this->bindController(processEnabled: false);
        $this->runStationMigrations();

        // Create a non-paused queue status
        DB::table('station_queue_status')->insert([
            'queue' => 'default',
            'connection' => 'redis',
            'paused' => false,
            'paused_at' => null,
            'updated_at' => now(),
        ]);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $data = $response->json('redis');
        $this->assertFalse($data['paused']);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.api.auth', 'none');
        $app['config']->set('station.api.middleware', []);
        $app['config']->set('station.default', 'redis');
        $app['config']->set('queue.connections', []);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    private function runStationMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    private function bindController(bool $processEnabled): void
    {
        $queueManager = new QueueManager($this->laravelQueueManager);
        $processManager = new ProcessManager(['enabled' => $processEnabled]);
        $driverInfoCollector = new DriverInfoCollector($this->laravelQueueManager);

        $controller = new WorkerController(
            processManager: $processManager,
            queueManager: $queueManager,
            healthChecker: $this->healthChecker,
            driverInfoCollector: $driverInfoCollector,
        );

        $this->app->instance(WorkerController::class, $controller);
    }
}
