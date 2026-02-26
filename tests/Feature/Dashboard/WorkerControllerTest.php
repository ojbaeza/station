<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\HealthCheckerInterface;
use Station\Core\DriverInfoCollector;
use Station\Core\ProcessManager;
use Station\Core\QueueManager;
use Station\Dashboard\Http\Controllers\WorkerController;
use Station\DTOs\ConnectionStatus;
use Station\StationServiceProvider;

class WorkerControllerTest extends TestCase
{
    private HealthCheckerInterface&MockInterface $healthChecker;

    private LaravelQueueManager&MockInterface $laravelQueueManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMockDependencies();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- Worker Status ----

    public function testWorkerStatusEndpointReturnsData(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/workers/status')
            ->assertOk();
    }

    public function testWorkerStatusReturnsDataWhenProcessManagementDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/workers/status')
            ->assertOk();
    }

    public function testWorkerStatusReturnsDataWithEnabledProcessManager(): void
    {
        $this->bindController(processEnabled: true);

        $response = $this->get('/station/api/workers/status');
        $response->assertOk();

        $data = $response->json();
        $this->assertIsArray($data);
    }

    // ---- Worker Dashboard Status ----

    public function testWorkerDashboardStatusReturnsData(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/workers/dashboard-status')
            ->assertOk()
            ->assertJsonStructure(['workers', 'pauseStatus', 'supervisor', 'driverInfo']);
    }

    public function testWorkerDashboardStatusWithStationDriverConnections(): void
    {
        $this->app['config']->set('queue.connections.redis', [
            'driver' => 'station-redis',
            'queue' => 'default',
        ]);

        $this->bindController(processEnabled: true);

        $this->get('/station/api/workers/dashboard-status')
            ->assertOk()
            ->assertJsonStructure(['workers', 'pauseStatus', 'supervisor', 'driverInfo']);
    }

    public function testWorkerDashboardStatusContainsPauseStatusForStationDrivers(): void
    {
        $this->app['config']->set('queue.connections.redis', [
            'driver' => 'station-redis',
            'queue' => 'default',
        ]);
        $this->app['config']->set('queue.connections.sync', [
            'driver' => 'sync',
        ]);

        $this->bindController(processEnabled: true);
        $this->runStationMigrations();

        $response = $this->get('/station/api/workers/dashboard-status');
        $response->assertOk();

        $pauseStatus = $response->json('pauseStatus');

        $this->assertArrayHasKey('redis', $pauseStatus);
        $this->assertArrayNotHasKey('sync', $pauseStatus);
    }

    public function testWorkerDashboardStatusRecordsSnapshotOncePerMinute(): void
    {
        $this->bindController(processEnabled: false);

        Cache::forget('station:driver-snapshot-lock');

        $this->get('/station/api/workers/dashboard-status')
            ->assertOk();

        $this->assertTrue(Cache::has('station:driver-snapshot-lock'));
    }

    public function testWorkerDashboardStatusDoesNotRecordSnapshotWithinCooldown(): void
    {
        $this->bindController(processEnabled: false);

        Cache::put('station:driver-snapshot-lock', true, 60);

        $this->get('/station/api/workers/dashboard-status')
            ->assertOk();

        $this->assertTrue(Cache::has('station:driver-snapshot-lock'));
    }

    // ---- Start Worker ----

    public function testStartWorkerReturnsErrorWhenDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/start', ['connection' => 'redis'])
            ->assertStatus(400);
    }

    public function testStartWorkerValidatesInput(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/start', [])
            ->assertStatus(422);
    }

    public function testStartWorkerWithEnabledProcessManagementReturnsResponse(): void
    {
        $this->bindController(processEnabled: true);
        $this->runStationMigrations();

        $this->postJson('/station/api/workers/start', [
            'connection' => 'redis',
            'queue' => 'default',
            'workers' => 1,
        ])
            ->assertOk()
            ->assertJsonStructure(['success', 'pids', 'command', 'message']);
    }

    public function testStartWorkerWithMultipleWorkersReturnsResponse(): void
    {
        $this->bindController(processEnabled: true);
        $this->runStationMigrations();

        $this->postJson('/station/api/workers/start', [
            'connection' => 'rabbitmq',
            'queue' => 'emails',
            'workers' => 3,
        ])
            ->assertOk()
            ->assertJsonStructure(['success', 'pids', 'command', 'message']);
    }

    public function testStartWorkerDefaultsToQueueDefault(): void
    {
        $this->bindController(processEnabled: true);
        $this->runStationMigrations();

        $response = $this->postJson('/station/api/workers/start', [
            'connection' => 'redis',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('default', $response->json('command'));
    }

    public function testStartWorkerValidatesWorkersMax(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/start', [
            'connection' => 'redis',
            'workers' => 20,
        ])
            ->assertStatus(422);
    }

    public function testStartWorkerValidatesWorkersMin(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/start', [
            'connection' => 'redis',
            'workers' => 0,
        ])
            ->assertStatus(422);
    }

    public function testStartWorkerRejectsInvalidConnection(): void
    {
        $this->bindController(processEnabled: true);
        $this->runStationMigrations();

        $this->postJson('/station/api/workers/start', [
            'connection' => 'invalid-driver',
        ])
            ->assertStatus(400);
    }

    public function testStartWorkerResumesPausedQueuesBeforeStarting(): void
    {
        $this->laravelQueueManager->shouldReceive('connection')
            ->with('redis')
            ->andReturn(Mockery::mock(Queue::class));

        $this->bindController(processEnabled: true);
        $this->runStationMigrations();

        DB::table('station_queue_status')->insert([
            'queue' => 'default',
            'connection' => 'redis',
            'paused' => true,
            'paused_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/station/api/workers/start', [
            'connection' => 'redis',
            'queue' => 'default',
        ])
            ->assertOk();

        $paused = DB::table('station_queue_status')
            ->where('queue', 'default')
            ->where('connection', 'redis')
            ->value('paused');
        $this->assertFalse((bool) $paused);
    }

    // ---- Stop Worker ----

    public function testStopWorkerReturnsErrorWhenDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/stop', ['connection' => 'redis'])
            ->assertStatus(400);
    }

    public function testStopWorkerValidatesConnectionRequired(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/stop', [])
            ->assertStatus(422);
    }

    public function testStopWorkerWithEnabledProcessManagementReturnsResponse(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/workers/stop', [
            'connection' => 'redis',
        ])
            ->assertOk()
            ->assertJsonStructure(['success', 'stopped', 'message']);
    }

    public function testStopWorkerRejectsInvalidConnection(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/workers/stop', [
            'connection' => 'nonexistent',
        ])
            ->assertStatus(400);
    }

    // ---- Stop External Worker ----

    public function testStopExternalWorkerReturnsErrorWhenDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/stop-external', ['pid' => 12345])
            ->assertStatus(400);
    }

    public function testStopExternalWorkerValidatesPidRequired(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/stop-external', [])
            ->assertStatus(422);
    }

    public function testStopExternalWorkerValidatesPidIsInteger(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/workers/stop-external', [
            'pid' => 'not-a-number',
        ])
            ->assertStatus(422);
    }

    public function testStopExternalWorkerWithEnabledReturnsResponse(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/workers/stop-external', [
            'pid' => 99999,
        ])
            ->assertOk()
            ->assertJsonStructure(['success', 'message']);
    }

    // ---- Supervisor ----

    public function testSupervisorStatusEndpointReturnsData(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/supervisor/status')
            ->assertOk();
    }

    public function testSupervisorStatusReturnsDataWhenProcessManagementDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/supervisor/status')
            ->assertOk();
    }

    public function testSupervisorStatusReturnsDataWithEnabledProcessManager(): void
    {
        $this->bindController(processEnabled: true);

        $this->get('/station/api/supervisor/status')
            ->assertOk()
            ->assertJsonStructure(['running', 'pid', 'connection', 'queue', 'workers']);
    }

    public function testStartSupervisorReturnsErrorWhenDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/supervisor/start', ['connection' => 'redis'])
            ->assertStatus(400);
    }

    public function testStartSupervisorValidatesInput(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/supervisor/start', [])
            ->assertStatus(422);
    }

    public function testStartSupervisorValidatesConnectionRequired(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/supervisor/start', [
            'queue' => 'default',
        ])
            ->assertStatus(422);
    }

    public function testStartSupervisorValidatesWorkersRange(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/supervisor/start', [
            'connection' => 'redis',
            'workers' => 11,
        ])
            ->assertStatus(422);
    }

    public function testStartSupervisorWithEnabledReturnsResponse(): void
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

    public function testStartSupervisorRejectsInvalidConnection(): void
    {
        $this->bindController(processEnabled: true);

        $this->postJson('/station/api/supervisor/start', [
            'connection' => 'nonexistent',
        ])
            ->assertStatus(400);
    }

    public function testStopSupervisorReturnsErrorWhenDisabled(): void
    {
        $this->bindController(processEnabled: false);

        $this->post('/station/api/supervisor/stop')
            ->assertStatus(400);
    }

    public function testStopSupervisorWithEnabledReturnsResponse(): void
    {
        $this->bindController(processEnabled: true);

        $this->post('/station/api/supervisor/stop')
            ->assertOk()
            ->assertJsonStructure(['success', 'message']);
    }

    // ---- Queue Connections ----

    public function testQueueConnectionsEndpointReturnsData(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/queues/connections')
            ->assertOk();
    }

    public function testQueueConnectionsReturnsStationDriversOnly(): void
    {
        $this->app['config']->set('queue.connections', [
            'sync' => ['driver' => 'sync'],
            'redis' => ['driver' => 'station-redis', 'queue' => 'default'],
        ]);
        $this->app['config']->set('queue.default', 'redis');

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'station-redis'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $data = $response->json();

        $this->assertArrayNotHasKey('sync', $data);
        $this->assertArrayHasKey('redis', $data);
        $this->assertEquals('redis', $data['redis']['driver']);
        $this->assertTrue($data['redis']['is_default']);
    }

    public function testQueueConnectionsSanitizesRedisConfig(): void
    {
        $this->app['config']->set('queue.connections', [
            'redis' => [
                'driver' => 'station-redis',
                'queue' => 'default',
                'connection' => 'default',
                'retry_after' => 90,
                'block_for' => 5,
                'password' => 'secret-password',
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'station-redis'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('redis.config');

        $this->assertArrayHasKey('queue', $config);
        $this->assertArrayHasKey('connection', $config);
        $this->assertArrayNotHasKey('password', $config);
    }

    public function testQueueConnectionsSanitizesRabbitMQConfig(): void
    {
        $this->app['config']->set('queue.connections', [
            'rabbitmq' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
                'hosts' => [
                    ['host' => 'localhost', 'port' => 5672, 'vhost' => '/', 'user' => 'guest', 'password' => 'secret'],
                ],
                'exchange' => ['name' => 'station', 'type' => 'direct'],
                'options' => ['heartbeat' => 60],
            ],
        ]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'rabbitmq' => new ConnectionStatus(connected: true, latency_ms: 5, driver: 'rabbitmq'),
        ]);

        $this->bindController(processEnabled: false);

        $response = $this->get('/station/api/queues/connections');
        $response->assertOk();

        $config = $response->json('rabbitmq.config');

        $this->assertEquals('localhost', $config['host']);
        $this->assertEquals(5672, $config['port']);
        $this->assertEquals('station', $config['exchange']);
        $this->assertEquals(60, $config['heartbeat']);
        $this->assertArrayNotHasKey('user', $config);
        $this->assertArrayNotHasKey('password', $config);
    }

    // ---- Pause/Resume Queue ----

    public function testPauseQueueReturnsErrorWithoutQueueName(): void
    {
        $this->bindController(processEnabled: false);

        $this->post('/station/api/queues/pause')
            ->assertStatus(400)
            ->assertJson(['error' => 'Queue name is required']);
    }

    public function testResumeQueueReturnsErrorWithoutQueueName(): void
    {
        $this->bindController(processEnabled: false);

        $this->post('/station/api/queues/resume')
            ->assertStatus(400)
            ->assertJson(['error' => 'Queue name is required']);
    }

    public function testPauseQueueWithValidNameReturnsError(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/queues/pause', [
            'queue' => 'default',
            'connection' => 'redis',
        ])
            ->assertStatus(400);
    }

    public function testResumeQueueWithValidNameReturnsError(): void
    {
        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/queues/resume', [
            'queue' => 'default',
            'connection' => 'redis',
        ])
            ->assertStatus(400);
    }

    public function testPauseQueueWithValidConnectionSucceeds(): void
    {
        $this->laravelQueueManager->shouldReceive('connection')
            ->with('redis')
            ->andReturn(Mockery::mock(Queue::class));

        $this->bindController(processEnabled: false);
        $this->runStationMigrations();

        $this->postJson('/station/api/queues/pause', [
            'queue' => 'emails',
            'connection' => 'redis',
        ])
            ->assertOk()
            ->assertJson(['message' => 'Queue emails paused']);

        $this->assertDatabaseHas('station_queue_status', [
            'queue' => 'emails',
            'connection' => 'redis',
            'paused' => true,
        ]);
    }

    public function testResumeQueueWithValidConnectionSucceeds(): void
    {
        $this->laravelQueueManager->shouldReceive('connection')
            ->with('redis')
            ->andReturn(Mockery::mock(Queue::class));

        $this->bindController(processEnabled: false);
        $this->runStationMigrations();

        DB::table('station_queue_status')->insert([
            'queue' => 'emails',
            'connection' => 'redis',
            'paused' => true,
            'paused_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/station/api/queues/resume', [
            'queue' => 'emails',
            'connection' => 'redis',
        ])
            ->assertOk()
            ->assertJson(['message' => 'Queue emails resumed']);

        $this->assertDatabaseHas('station_queue_status', [
            'queue' => 'emails',
            'connection' => 'redis',
            'paused' => false,
        ]);
    }

    public function testPauseQueueHandlesExceptionGracefully(): void
    {
        $this->laravelQueueManager->shouldReceive('connection')
            ->andThrow(new RuntimeException('Connection unavailable'));

        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/queues/pause', [
            'queue' => 'default',
            'connection' => 'broken',
        ])
            ->assertStatus(400);
    }

    public function testResumeQueueHandlesExceptionGracefully(): void
    {
        $this->laravelQueueManager->shouldReceive('connection')
            ->andThrow(new RuntimeException('Connection unavailable'));

        $this->bindController(processEnabled: false);

        $this->postJson('/station/api/queues/resume', [
            'queue' => 'default',
            'connection' => 'broken',
        ])
            ->assertStatus(400);
    }

    public function testPauseQueueUsesDefaultConnection(): void
    {
        $this->app['config']->set('station.default', 'redis');

        $this->laravelQueueManager->shouldReceive('connection')
            ->andReturn(Mockery::mock(Queue::class));

        $this->bindController(processEnabled: false);
        $this->runStationMigrations();

        $this->postJson('/station/api/queues/pause', [
            'queue' => 'default',
        ])
            ->assertOk()
            ->assertJson(['message' => 'Queue default paused']);
    }

    // ---- Queue Pause Status ----

    public function testQueuePauseStatusReturnsStatuses(): void
    {
        $this->bindController(processEnabled: false);

        $this->get('/station/api/queues/pause-status')
            ->assertOk();
    }

    public function testQueuePauseStatusReturnsOnlyStationDrivers(): void
    {
        $this->app['config']->set('queue.connections', [
            'sync' => ['driver' => 'sync'],
            'redis' => ['driver' => 'station-redis', 'queue' => 'default'],
        ]);

        $this->bindController(processEnabled: false);
        $this->runStationMigrations();

        $response = $this->get('/station/api/queues/pause-status');
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayNotHasKey('sync', $data);
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

    private function createMockDependencies(): void
    {
        $this->healthChecker = Mockery::mock(HealthCheckerInterface::class);
        $this->healthChecker->shouldReceive('check')->byDefault()->andReturn(null);
        $this->healthChecker->shouldReceive('checkConnectivityQuick')->byDefault()->andReturn([]);

        $this->laravelQueueManager = Mockery::mock(LaravelQueueManager::class);
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
