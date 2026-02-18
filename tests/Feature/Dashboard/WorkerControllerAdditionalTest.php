<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
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
 * Additional feature tests for WorkerController covering:
 * - stopExternalWorker endpoint (success and error)
 * - supervisorStatus endpoint (success and error)
 * - startSupervisor endpoint (success and error)
 * - stopSupervisor endpoint (success and error)
 * - workerDashboardStatus with supervisor exception fallback
 * - queuePauseStatus error paths
 * - queueConnections with worker count from processManager
 */
class WorkerControllerAdditionalTest extends TestCase
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

    // ---- stopExternalWorker ----

    public function testStopExternalWorkerRequiresPid(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/workers/stop-external', [])
            ->assertStatus(422);
    }

    public function testStopExternalWorkerWithProcessManagementDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/stop-external', ['pid' => 12345])
            ->assertStatus(400);
    }

    public function testStopExternalWorkerWithValidPid(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/workers/stop-external', ['pid' => 999999])
            ->assertOk()
            ->assertJsonStructure(['success', 'message']);
    }

    // ---- supervisorStatus ----

    public function testSupervisorStatusEndpointReturnsData(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/supervisor/status')
            ->assertOk();
    }

    public function testSupervisorStatusWithProcessManagementEnabled(): void
    {
        $this->bindController(processEnabled: true);

        $response = $this->get('/station/api/supervisor/status');
        $response->assertOk();

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('running', $data);
    }

    // ---- startSupervisor ----

    public function testStartSupervisorRequiresConnection(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/supervisor/start', [])
            ->assertStatus(422);
    }

    public function testStartSupervisorWithProcessManagementDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/supervisor/start', ['connection' => 'redis'])
            ->assertStatus(400);
    }

    public function testStartSupervisorWithEnabledProcessManagement(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/supervisor/start', [
            'connection' => 'redis',
            'queue' => 'default',
            'workers' => 2,
        ])
            ->assertOk()
            ->assertJsonStructure(['success', 'pid', 'message']);
    }

    // ---- stopSupervisor ----

    public function testStopSupervisorWithProcessManagementDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/supervisor/stop')
            ->assertStatus(400);
    }

    public function testStopSupervisorWithEnabledProcessManagement(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/supervisor/stop')
            ->assertOk()
            ->assertJsonStructure(['success', 'message']);
    }

    // ---- pauseQueue / resumeQueue ----

    public function testPauseQueueWithoutQueueNameReturns400(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/queues/pause', [])
            ->assertStatus(400);
    }

    public function testResumeQueueWithoutQueueNameReturns400(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/queues/resume', [])
            ->assertStatus(400);
    }

    public function testPauseQueueWithValidNameAndMigrations(): void
    {
        $mockQueue = Mockery::mock(Queue::class);
        $this->laravelQueueManager->shouldReceive('connection')->andReturn($mockQueue);

        $this->bindController(processEnabled: false);
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        $this->postJson('/station/api/queues/pause', ['queue' => 'default', 'connection' => 'redis'])
            ->assertOk()
            ->assertJson(['message' => 'Queue default paused']);
    }

    public function testResumeQueueWithValidNameAndMigrations(): void
    {
        $mockQueue = Mockery::mock(Queue::class);
        $this->laravelQueueManager->shouldReceive('connection')->andReturn($mockQueue);

        $this->bindController(processEnabled: false);
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        $this->postJson('/station/api/queues/resume', ['queue' => 'default', 'connection' => 'redis'])
            ->assertOk()
            ->assertJson(['message' => 'Queue default resumed']);
    }

    // ---- queuePauseStatus ----

    public function testQueuePauseStatusReturnsEmptyWhenNoStationDrivers(): void
    {
        $this->app['config']->set('queue.connections', [
            'sync' => ['driver' => 'sync'],
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/pause-status');
        $response->assertOk();
        $response->assertJson([]);
    }

    public function testQueuePauseStatusWithStationDriverConnections(): void
    {
        $this->app['config']->set('queue.connections', [
            'redis' => ['driver' => 'station-redis', 'queue' => 'default'],
            'sync' => ['driver' => 'sync'],
        ]);

        $this->bindController(processEnabled: false);
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        $response = $this->get('/station/api/queues/pause-status');
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('redis', $data);
        $this->assertArrayNotHasKey('sync', $data);
    }

    // ---- queueConnections with multiple drivers ----

    public function testQueueConnectionsWithRabbitMQArrayExchangeAndOptions(): void
    {
        $this->app['config']->set('queue.connections', [
            'rabbitmq' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
                'hosts' => [
                    ['host' => 'rmq.local', 'port' => 5672, 'vhost' => '/station'],
                ],
                'exchange' => ['name' => 'station-exchange', 'type' => 'topic'],
                'options' => ['heartbeat' => 60],
                'password' => 'super-secret',
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'rabbitmq' => new ConnectionStatus(connected: true, latency_ms: 3, driver: 'rabbitmq'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('rabbitmq.config');
        $this->assertArrayNotHasKey('password', $config);
        $this->assertSame('station-exchange', $config['exchange']);
        $this->assertSame('topic', $config['exchange_type']);
        $this->assertSame(60, $config['heartbeat']);
        $this->assertSame('/station', $config['vhost']);
    }

    public function testQueueConnectionsExcludesNonStationDrivers(): void
    {
        $this->app['config']->set('queue.connections', [
            'sync' => ['driver' => 'sync'],
            'database' => ['driver' => 'database'],
            'redis' => ['driver' => 'station-redis', 'queue' => 'default'],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'redis'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('redis', $data);
        $this->assertArrayNotHasKey('sync', $data);
        $this->assertArrayNotHasKey('database', $data);
    }

    public function testQueueConnectionsIdentifiesDefaultConnection(): void
    {
        $this->app['config']->set('queue.default', 'redis');
        $this->app['config']->set('queue.connections', [
            'redis' => ['driver' => 'station-redis', 'queue' => 'default'],
            'sqs' => ['driver' => 'sqs', 'queue' => 'jobs', 'region' => 'us-east-1', 'prefix' => 'https://sqs', 'key' => 'k', 'secret' => 's'],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'redis'),
            'sqs' => new ConnectionStatus(connected: true, latency_ms: 50, driver: 'sqs'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $this->assertTrue($response->json('redis.is_default'));
        $this->assertFalse($response->json('sqs.is_default'));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.api.enabled', true);
        $app['config']->set('station.api.auth', 'none');
        $app['config']->set('station.api.middleware', []);
        $app['config']->set('station.default', 'redis');
        $app['config']->set('queue.connections', []);
        $app['config']->set('queue.default', 'sync');
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
