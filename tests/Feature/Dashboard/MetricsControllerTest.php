<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;
use Station\Core\DriverInfoCollector;
use Station\Core\MetricsCollector;
use Station\Core\ProcessManager;
use Station\Dashboard\Http\Controllers\MetricsController;
use Station\DTOs\ConnectionStatus;
use Station\DTOs\HealthCheckResult;
use Station\DTOs\JobStats;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\MetricsSnapshot;
use Station\StationServiceProvider;

class MetricsControllerTest extends TestCase
{
    private JobRepositoryInterface&MockInterface $jobRepository;

    private SupervisorRepositoryInterface&MockInterface $supervisorRepository;

    private WorkerRepositoryInterface&MockInterface $workerRepository;

    private HealthCheckerInterface&MockInterface $healthChecker;

    private MetricsRepositoryInterface&MockInterface $metricsRepository;

    private LaravelQueueManager&MockInterface $laravelQueueManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMockDependencies();
        $this->bindController();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- Stats ----

    public function testStatsEndpointReturnsJobStats(): void
    {
        $this->get('/station/api/stats')
            ->assertOk()
            ->assertJsonStructure([
                'totals' => ['pending', 'processing', 'completed', 'failed'],
                'queues',
                'throughput',
                'failureRate',
                'activeSupervisors',
                'activeWorkers',
            ]);
    }

    public function testStatsEndpointAggregatesQueueTotals(): void
    {
        $this->get('/station/api/stats')
            ->assertOk()
            ->assertJsonFragment([
                'totals' => [
                    'pending' => 10,
                    'processing' => 3,
                    'completed' => 200,
                    'failed' => 5,
                ],
            ]);
    }

    // ---- Monitoring ----

    public function testMonitoringEndpointReturnsExpectedStructure(): void
    {
        $this->get('/api/station/monitoring')
            ->assertOk()
            ->assertJsonStructure(['supervisors', 'workers', 'health']);
    }

    // ---- Health ----

    public function testHealthEndpointReturnsHealthStatus(): void
    {
        $this->get('/station/api/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'timestamp']);
    }

    public function testHealthEndpointReturns503WhenUnhealthy(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')->andReturn([
            'status' => 'unhealthy', 'latency_ms' => 0,
        ]);

        $this->get('/station/api/health')
            ->assertStatus(503);
    }

    public function testHealthEndpointReturns200WhenDatabaseHealthy(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')->andReturn([
            'status' => 'healthy', 'latency_ms' => 1,
        ]);
        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([]);

        $this->get('/station/api/health')
            ->assertOk();
    }

    public function testHealthEndpointWhenCheckDatabaseThrows(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')
            ->andThrow(new RuntimeException('Connection refused'));
        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([]);

        $this->get('/station/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy');
    }

    public function testHealthEndpointWhenAllChecksThrow(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')
            ->andThrow(new RuntimeException('Connection refused'));
        $this->healthChecker->shouldReceive('checkConnectivityQuick')
            ->andThrow(new RuntimeException('Cannot connect'));

        $this->get('/station/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy');
    }

    public function testHealthEndpointReturnsTimestamp(): void
    {
        $this->get('/station/api/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'timestamp', 'checks', 'connections']);
    }

    // ---- Metrics ----

    public function testMetricsEndpointReturnsMetrics(): void
    {
        $this->get('/station/api/metrics')
            ->assertOk()
            ->assertJsonStructure([
                'metrics',
                'throughput',
                'avgWaitTime',
                'avgProcessingTime',
                'failureRate',
            ]);
    }

    public function testMetricsEndpointAcceptsPeriod(): void
    {
        $this->get('/station/api/metrics?period=24h')
            ->assertOk()
            ->assertJsonStructure([
                'metrics',
                'throughput',
                'avgWaitTime',
                'avgProcessingTime',
                'failureRate',
            ]);
    }

    public function testMetricsTimeSeriesEndpoint(): void
    {
        $this->get('/station/api/metrics/time-series?period=1h&buckets=10')
            ->assertOk();
    }

    public function testMetricsTimeSeriesClampsBuckets(): void
    {
        $this->get('/station/api/metrics/time-series?period=1h&buckets=200')
            ->assertOk();
    }

    // ---- Driver Info ----

    public function testDriverInfoEndpoint(): void
    {
        $this->get('/station/api/metrics/driver-info')
            ->assertOk();
    }

    // ---- Driver Time Series ----

    public function testDriverTimeSeriesRequiresConnection(): void
    {
        $this->get('/station/api/metrics/driver-time-series')
            ->assertStatus(400)
            ->assertJson(['error' => 'Connection is required']);
    }

    public function testDriverTimeSeriesWithConnectionReturnsErrorOrData(): void
    {
        $response = $this->get('/station/api/metrics/driver-time-series?connection=redis');
        $this->assertContains($response->getStatusCode(), [200, 400]);
    }

    public function testDriverTimeSeriesWithValidConnectionReturnsData(): void
    {
        $this->runStationMigrations();

        DB::table('station_driver_snapshots')->insert([
            'connection' => 'redis',
            'queue_size' => 10,
            'memory_bytes' => 1048576,
            'consumers' => 2,
            'ops_rate' => 5.5,
            'recorded_at' => now()->subMinutes(5),
        ]);

        $this->get('/station/api/metrics/driver-time-series?connection=redis&period=1h')
            ->assertOk()
            ->assertJsonStructure([
                'queue_size',
                'memory_bytes',
                'consumers',
                'ops_rate',
            ]);
    }

    public function testDriverTimeSeriesWithDifferentPeriodsReturnsData(): void
    {
        $this->runStationMigrations();

        DB::table('station_driver_snapshots')->insert([
            'connection' => 'rabbitmq',
            'queue_size' => 5,
            'memory_bytes' => 2097152,
            'consumers' => 1,
            'ops_rate' => 3.0,
            'recorded_at' => now()->subMinutes(2),
        ]);

        foreach (['5m', '15m', '1h', '6h', '24h'] as $period) {
            $this->get("/station/api/metrics/driver-time-series?connection=rabbitmq&period={$period}")
                ->assertOk();
        }
    }

    public function testDriverTimeSeriesWithEmptyResultsReturnsEmptyArrays(): void
    {
        $this->runStationMigrations();

        $this->get('/station/api/metrics/driver-time-series?connection=nonexistent&period=1h')
            ->assertOk()
            ->assertJson([
                'queue_size' => [],
                'memory_bytes' => [],
                'consumers' => [],
                'ops_rate' => [],
            ]);
    }

    public function testDriverTimeSeriesWithMultipleSnapshotsReturnsAllPoints(): void
    {
        $this->runStationMigrations();

        for ($i = 1; $i <= 3; $i++) {
            DB::table('station_driver_snapshots')->insert([
                'connection' => 'redis',
                'queue_size' => $i * 10,
                'memory_bytes' => $i * 1024,
                'consumers' => $i,
                'ops_rate' => $i * 1.5,
                'recorded_at' => now()->subMinutes(30 - $i),
            ]);
        }

        $response = $this->get('/station/api/metrics/driver-time-series?connection=redis&period=1h');
        $response->assertOk();
        $this->assertCount(3, $response->json('queue_size'));
    }

    // ---- Drivers ----

    public function testDriversEndpointReturnsConnectivity(): void
    {
        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'rabbitmq' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'rabbitmq'),
        ]);

        $this->get('/station/api/drivers')
            ->assertOk();
    }

    public function testDriversEndpointMarksDefaultConnection(): void
    {
        $this->app['config']->set('queue.default', 'redis');

        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 1, driver: 'station-redis'),
            'rabbitmq' => new ConnectionStatus(connected: false, latency_ms: 0, driver: 'rabbitmq'),
        ]);

        $this->get('/station/api/drivers')
            ->assertOk()
            ->assertJsonPath('redis.is_default', true)
            ->assertJsonPath('rabbitmq.is_default', false);
    }

    public function testDriversEndpointIncludesWorkerCountFromProcessManager(): void
    {
        $this->healthChecker->shouldReceive('checkConnectivityQuick')->andReturn([
            'redis' => new ConnectionStatus(connected: true, latency_ms: 2, driver: 'station-redis'),
        ]);

        $response = $this->get('/station/api/drivers');
        $response->assertOk();

        $this->assertArrayHasKey('workers', $response->json('redis'));
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
        $this->jobRepository = Mockery::mock(JobRepositoryInterface::class);
        $this->jobRepository->shouldReceive('getStatsByQueue')->byDefault()->andReturn([
            'default' => new JobStats(pending: 10, processing: 3, completed: 200, failed: 5),
        ]);

        $this->supervisorRepository = Mockery::mock(SupervisorRepositoryInterface::class);
        $this->supervisorRepository->shouldReceive('getActive')->byDefault()->andReturn(new Collection([]));

        $this->workerRepository = Mockery::mock(WorkerRepositoryInterface::class);
        $this->workerRepository->shouldReceive('getActive')->byDefault()->andReturn(new Collection([]));

        $this->healthChecker = Mockery::mock(HealthCheckerInterface::class);
        $this->healthChecker->shouldReceive('check')->byDefault()->andReturn(
            new HealthCheckResult(status: 'healthy', timestamp: now()->toIso8601String(), checks: [], connections: []),
        );
        $this->healthChecker->shouldReceive('checkDatabase')->byDefault()->andReturn([
            'status' => 'healthy', 'latency_ms' => 1,
        ]);
        $this->healthChecker->shouldReceive('checkConnectivityQuick')->byDefault()->andReturn([]);

        $this->metricsRepository = Mockery::mock(MetricsRepositoryInterface::class);
        $this->metricsRepository->shouldReceive('getSnapshot')->byDefault()->andReturn(
            new MetricsSnapshot(
                jobs_per_minute: 5.0,
                jobs_processed_last_hour: 300,
                failed_jobs: 10,
                failed_rate_percent: 3.23,
                average_processing_time_ms: 200,
                active_workers: 5,
                pending_jobs: 25,
            ),
        );
        $this->metricsRepository->shouldReceive('getQueueStats')->byDefault()->andReturn([]);
        $this->metricsRepository->shouldReceive('getRecentMetrics')->byDefault()->andReturn(new Collection([]));
        $this->metricsRepository->shouldReceive('getAggregated')->byDefault()->andReturn(
            new MetricsAggregation(
                jobs_processed: 0,
                jobs_failed: 0,
                avg_processing_time: 0.0,
                avg_wait_time: 0.0,
                failure_rate: 0.0,
            ),
        );
        $this->metricsRepository->shouldReceive('getGlobalAggregated')->byDefault()->andReturn(
            new MetricsAggregation(
                jobs_processed: 0,
                jobs_failed: 0,
                avg_processing_time: 0.0,
                avg_wait_time: 0.0,
                failure_rate: 0.0,
            ),
        );
        $this->metricsRepository->shouldReceive('getTimeSeries')->byDefault()->andReturn([]);
        $this->metricsRepository->shouldReceive('store')->byDefault();
        $this->metricsRepository->shouldReceive('getHistoricalMetrics')->byDefault()->andReturn([]);

        $this->laravelQueueManager = Mockery::mock(LaravelQueueManager::class);
    }

    private function bindController(bool $processEnabled = false): void
    {
        $metricsCollector = new MetricsCollector($this->metricsRepository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        $driverInfoCollector = new DriverInfoCollector($this->laravelQueueManager);
        $processManager = new ProcessManager(['enabled' => $processEnabled]);

        $controller = new MetricsController(
            metrics: $metricsCollector,
            healthChecker: $this->healthChecker,
            driverInfoCollector: $driverInfoCollector,
            jobRepository: $this->jobRepository,
            supervisorRepository: $this->supervisorRepository,
            workerRepository: $this->workerRepository,
            processManager: $processManager,
        );

        $this->app->instance(MetricsController::class, $controller);
    }
}
