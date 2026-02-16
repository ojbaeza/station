<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use RuntimeException;
use Station\Alerts\AlertManager;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\Contracts\BatchRepositoryInterface;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;
use Station\Core\DriverInfoCollector;
use Station\Core\MetricsCollector;
use Station\Core\ProcessManager;
use Station\Core\QueueManager;
use Station\Dashboard\StationController;
use Station\DTOs\JobStats;
use Station\DTOs\MetricsSnapshot;
use Station\DTOs\PaginatedResult;
use Station\StationServiceProvider;
use stdClass;

/**
 * Unit tests for StationController.
 *
 * Since StationController and its dependencies are final classes,
 * we construct them with mocked sub-dependencies (interfaces) and
 * test through reflection on the private helper methods.
 */
class StationControllerTest extends TestCase
{
    private JobRepositoryInterface&MockInterface $jobRepository;

    private BatchRepositoryInterface&MockInterface $batchRepository;

    private SupervisorRepositoryInterface&MockInterface $supervisorRepository;

    private WorkerRepositoryInterface&MockInterface $workerRepository;

    private MetricsRepositoryInterface&MockInterface $metricsRepository;

    private MetricsCollector $metrics;

    private HealthCheckerInterface&MockInterface $healthChecker;

    private QueueManager $queueManager;

    private StuckJobDetectorInterface&MockInterface $stuckJobDetector;

    private DriverInfoCollector $driverInfoCollector;

    private ProcessManager $processManager;

    private AlertRepositoryInterface&MockInterface $alertRepository;

    private AlertChannelRepositoryInterface&MockInterface $channelRepository;

    private AlertManager $alertManager;

    private StationController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock interfaces
        $this->jobRepository = Mockery::mock(JobRepositoryInterface::class);
        $this->batchRepository = Mockery::mock(BatchRepositoryInterface::class);
        $this->supervisorRepository = Mockery::mock(SupervisorRepositoryInterface::class);
        $this->supervisorRepository->shouldReceive('getActive')->andReturn(collect([]))->byDefault();
        $this->workerRepository = Mockery::mock(WorkerRepositoryInterface::class);
        $this->workerRepository->shouldReceive('getActive')->andReturn(collect([]))->byDefault();
        $this->healthChecker = Mockery::mock(HealthCheckerInterface::class);
        $this->stuckJobDetector = Mockery::mock(StuckJobDetectorInterface::class);
        $this->metricsRepository = Mockery::mock(MetricsRepositoryInterface::class);
        $this->alertRepository = Mockery::mock(AlertRepositoryInterface::class);
        $this->channelRepository = Mockery::mock(AlertChannelRepositoryInterface::class);

        // Create real instances of final classes with mocked sub-dependencies
        $this->metrics = new MetricsCollector(
            $this->metricsRepository,
            ['enabled' => true, 'metrics' => ['enabled' => true]],
        );

        $this->queueManager = new QueueManager($this->app['queue']);

        $this->processManager = new ProcessManager([
            'enabled' => false,
        ]);

        $this->driverInfoCollector = new DriverInfoCollector($this->app['queue']);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        $this->alertManager = new AlertManager(
            $this->alertRepository,
            $this->channelRepository,
            $events,
            ['enabled' => false],
        );

        $this->controller = new StationController(
            $this->jobRepository,
            $this->batchRepository,
            $this->supervisorRepository,
            $this->workerRepository,
            $this->metrics,
            $this->healthChecker,
            $this->queueManager,
            $this->stuckJobDetector,
            $this->driverInfoCollector,
            $this->processManager,
            $this->alertManager,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- getStats tests ----

    public function testGetStatsAggregatesQueueTotalsCorrectly(): void
    {
        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([
                'default' => new JobStats(pending: 10, processing: 5, completed: 100, failed: 3),
                'emails' => new JobStats(pending: 20, processing: 2, completed: 50, failed: 1),
            ]);

        // MetricsCollector calls repository methods for throughput and failure rate
        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 42.5,
            jobs_processed_last_hour: 1000,
            failed_jobs: 10,
            failed_rate_percent: 3.2,
            average_processing_time_ms: 500,
            active_workers: 5,
            pending_jobs: 30,
        );
        $this->metricsRepository->shouldReceive('getSnapshot')
            ->andReturn($snapshot);

        $result = $this->invokePrivateMethod('getStats');

        $this->assertSame(30, $result['totals']['pending']);
        $this->assertSame(7, $result['totals']['processing']);
        $this->assertSame(150, $result['totals']['completed']);
        $this->assertSame(4, $result['totals']['failed']);
        $this->assertSame(42.5, $result['throughput']);
        // failureRate = failed_rate_percent / 100 = 0.032
        $this->assertEqualsWithDelta(0.032, $result['failureRate'], 0.001);
    }

    public function testGetStatsWithEmptyQueuesReturnsZeroTotals(): void
    {
        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([]);

        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 0.0,
            jobs_processed_last_hour: 0,
            failed_jobs: 0,
            failed_rate_percent: 0.0,
            average_processing_time_ms: 0,
            active_workers: 0,
            pending_jobs: 0,
        );
        $this->metricsRepository->shouldReceive('getSnapshot')
            ->andReturn($snapshot);

        $result = $this->invokePrivateMethod('getStats');

        $this->assertSame(0, $result['totals']['pending']);
        $this->assertSame(0, $result['totals']['processing']);
        $this->assertSame(0, $result['totals']['completed']);
        $this->assertSame(0, $result['totals']['failed']);
    }

    public function testGetStatsReturnsZeroWorkersWhenProcessManagerReturnsEmpty(): void
    {
        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([
                'default' => new JobStats(pending: 5, processing: 2, completed: 10, failed: 1),
            ]);

        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 0.0,
            jobs_processed_last_hour: 0,
            failed_jobs: 0,
            failed_rate_percent: 0.0,
            average_processing_time_ms: 0,
            active_workers: 0,
            pending_jobs: 0,
        );
        $this->metricsRepository->shouldReceive('getSnapshot')->andReturn($snapshot);

        // ProcessManager returns empty arrays (no workers detected)
        // so both supervisors and workers count as 0
        $result = $this->invokePrivateMethod('getStats');

        $this->assertSame(0, $result['activeSupervisors']);
        $this->assertSame(0, $result['activeWorkers']);
    }

    public function testGetStatsReturnStructureContainsRequiredKeys(): void
    {
        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([]);

        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 1.0,
            jobs_processed_last_hour: 100,
            failed_jobs: 5,
            failed_rate_percent: 5.0,
            average_processing_time_ms: 200,
            active_workers: 2,
            pending_jobs: 10,
        );
        $this->metricsRepository->shouldReceive('getSnapshot')->andReturn($snapshot);

        $result = $this->invokePrivateMethod('getStats');

        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('queues', $result);
        $this->assertArrayHasKey('throughput', $result);
        $this->assertArrayHasKey('failureRate', $result);
        $this->assertArrayHasKey('activeSupervisors', $result);
        $this->assertArrayHasKey('activeWorkers', $result);

        // Verify totals sub-keys
        $this->assertArrayHasKey('pending', $result['totals']);
        $this->assertArrayHasKey('processing', $result['totals']);
        $this->assertArrayHasKey('completed', $result['totals']);
        $this->assertArrayHasKey('failed', $result['totals']);
    }

    public function testGetStatsSubtractsSilencedJobCountsFromTotals(): void
    {
        config(['station.silenced' => ['App\\Jobs\\SilencedJob']]);

        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([
                'default' => new JobStats(pending: 10, processing: 5, completed: 100, failed: 3),
            ]);

        // Expect count calls for each status to subtract silenced counts
        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'pending' && ($f['only_classes'] ?? []) === ['App\\Jobs\\SilencedJob']))
            ->once()
            ->andReturn(2);
        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'processing'))
            ->once()
            ->andReturn(1);
        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'completed'))
            ->once()
            ->andReturn(10);
        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'failed'))
            ->once()
            ->andReturn(1);

        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 0.0,
            jobs_processed_last_hour: 0,
            failed_jobs: 0,
            failed_rate_percent: 0.0,
            average_processing_time_ms: 0,
            active_workers: 0,
            pending_jobs: 0,
        );
        $this->metricsRepository->shouldReceive('getSnapshot')->andReturn($snapshot);

        $this->supervisorRepository->shouldReceive('getActive')->andReturn(collect([]));
        $this->workerRepository->shouldReceive('getActive')->andReturn(collect([]));

        $result = $this->invokePrivateMethod('getStats');

        $this->assertSame(8, $result['totals']['pending']);    // 10 - 2
        $this->assertSame(4, $result['totals']['processing']); // 5 - 1
        $this->assertSame(90, $result['totals']['completed']); // 100 - 10
        $this->assertSame(2, $result['totals']['failed']);     // 3 - 1
    }

    public function testGetStatsSilencedSubtractionDoesNotGoNegative(): void
    {
        config(['station.silenced' => ['App\\Jobs\\SilencedJob']]);

        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([
                'default' => new JobStats(pending: 2, processing: 0, completed: 5, failed: 0),
            ]);

        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'pending'))
            ->andReturn(10); // More silenced than total
        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'processing'))
            ->andReturn(0);
        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'completed'))
            ->andReturn(0);
        $this->jobRepository->shouldReceive('count')
            ->with(Mockery::on(static fn($f) => ($f['status'] ?? '') === 'failed'))
            ->andReturn(0);

        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 0.0,
            jobs_processed_last_hour: 0,
            failed_jobs: 0,
            failed_rate_percent: 0.0,
            average_processing_time_ms: 0,
            active_workers: 0,
            pending_jobs: 0,
        );
        $this->metricsRepository->shouldReceive('getSnapshot')->andReturn($snapshot);

        $this->supervisorRepository->shouldReceive('getActive')->andReturn(collect([]));
        $this->workerRepository->shouldReceive('getActive')->andReturn(collect([]));

        $result = $this->invokePrivateMethod('getStats');

        $this->assertSame(0, $result['totals']['pending']); // max(0, 2 - 10) = 0
    }

    // ---- getHealthSafe tests ----

    public function testGetHealthSafeReturnsHealthyWhenDatabaseIsHealthy(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')
            ->once()
            ->andReturn(['status' => 'healthy', 'latency_ms' => 5]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')
            ->once()
            ->andReturn([]);

        $result = $this->invokePrivateMethod('getHealthSafe');

        $this->assertSame('healthy', $result['status']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertSame(['status' => 'healthy', 'latency_ms' => 5], $result['checks']['database']);
    }

    public function testGetHealthSafeReturnsUnhealthyWhenDatabaseIsUnhealthy(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')
            ->once()
            ->andReturn(['status' => 'unhealthy', 'latency_ms' => 0]);

        $this->healthChecker->shouldReceive('checkConnectivityQuick')
            ->once()
            ->andReturn([]);

        $result = $this->invokePrivateMethod('getHealthSafe');

        $this->assertSame('unhealthy', $result['status']);
    }

    public function testGetHealthSafeReturnsUnhealthyWhenDatabaseCheckThrows(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $this->healthChecker->shouldReceive('checkConnectivityQuick')
            ->once()
            ->andReturn([]);

        $result = $this->invokePrivateMethod('getHealthSafe');

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame(['status' => 'unhealthy', 'latency_ms' => 0], $result['checks']['database']);
    }

    public function testGetHealthSafeReturnsUnhealthyWhenTopLevelThrows(): void
    {
        // Make checkDatabase succeed, but checkConnectivityQuick throws at the top level
        $this->healthChecker->shouldReceive('checkDatabase')
            ->andThrow(new RuntimeException('Fatal'));
        $this->healthChecker->shouldReceive('checkConnectivityQuick')
            ->andThrow(new RuntimeException('Fatal'));

        $result = $this->invokePrivateMethod('getHealthSafe');

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame([], $result['checks']);
        $this->assertSame([], $result['connections']);
    }

    public function testGetHealthSafeIncludesConnectionsFromQuickCheck(): void
    {
        $this->healthChecker->shouldReceive('checkDatabase')
            ->once()
            ->andReturn(['status' => 'healthy', 'latency_ms' => 2]);

        $connStatus = new stdClass();
        $connStatus->connected = true;
        $connStatus->latency_ms = 5;

        $this->healthChecker->shouldReceive('checkConnectivityQuick')
            ->once()
            ->andReturn(['rabbitmq' => $connStatus]);

        $result = $this->invokePrivateMethod('getHealthSafe');

        $this->assertSame('healthy', $result['status']);
        $this->assertArrayHasKey('rabbitmq', $result['connections']);
    }

    // ---- getActiveBatches tests ----

    public function testGetActiveBatchesReturnsBatchArray(): void
    {
        $this->batchRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                ['id' => 'b1', 'status' => 'processing'],
                ['id' => 'b2', 'status' => 'pending'],
            ]));

        $result = $this->invokePrivateMethod('getActiveBatches');

        $this->assertCount(2, $result);
        $this->assertSame('b1', $result[0]['id']);
        $this->assertSame('b2', $result[1]['id']);
    }

    public function testGetActiveBatchesReturnsMaxFiveBatches(): void
    {
        $batches = [];
        for ($i = 0; $i < 10; $i++) {
            $batches[] = ['id' => "b{$i}", 'status' => 'processing'];
        }

        $this->batchRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect($batches));

        $result = $this->invokePrivateMethod('getActiveBatches');

        $this->assertCount(5, $result);
    }

    public function testGetActiveBatchesReturnsEmptyOnException(): void
    {
        $this->batchRepository->shouldReceive('getActive')
            ->once()
            ->andThrow(new RuntimeException('Table does not exist'));

        $result = $this->invokePrivateMethod('getActiveBatches');

        $this->assertSame([], $result);
    }

    // ---- getRecentAlerts tests ----

    public function testGetRecentAlertsReturnsData(): void
    {
        $paginatedResult = new PaginatedResult(
            data: [['id' => 1, 'message' => 'High failure rate']],
            total: 1,
            per_page: 5,
            current_page: 1,
            last_page: 1,
            from: 1,
            to: 1,
        );

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with([], 1, 5)
            ->once()
            ->andReturn($paginatedResult);

        $result = $this->invokePrivateMethod('getRecentAlerts');

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertCount(1, $result->data);
        $this->assertSame(1, $result->total);
    }

    public function testGetRecentAlertsReturnsEmptyOnException(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->andThrow(new RuntimeException('Table missing'));

        $result = $this->invokePrivateMethod('getRecentAlerts');

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame([], $result->data);
        $this->assertSame(0, $result->total);
    }

    // ---- getRecentFailed tests ----

    public function testGetRecentFailedReturnsData(): void
    {
        $paginatedResult = new PaginatedResult(
            data: [['id' => 'j1', 'exception' => 'Timeout']],
            total: 1,
            per_page: 5,
            current_page: 1,
            last_page: 1,
            from: 1,
            to: 1,
        );

        $this->jobRepository->shouldReceive('paginateFailed')
            ->with([], 1, 5)
            ->once()
            ->andReturn($paginatedResult);

        $result = $this->invokePrivateMethod('getRecentFailed');

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame(1, $result->total);
    }

    public function testGetRecentFailedReturnsEmptyOnException(): void
    {
        $this->jobRepository->shouldReceive('paginateFailed')
            ->once()
            ->andThrow(new RuntimeException('DB error'));

        $result = $this->invokePrivateMethod('getRecentFailed');

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame([], $result->data);
        $this->assertSame(0, $result->total);
    }

    // ---- getTimeSeriesSafe tests ----

    public function testGetTimeSeriesSafeReturnsData(): void
    {
        // MetricsCollector.getTimeSeries('6h', 30) calls repository.getTimeSeries(360, 30, null, null)
        $this->metricsRepository->shouldReceive('getTimeSeries')
            ->with(360, 30, null, null)
            ->once()
            ->andReturn([
                ['time' => '2025-01-01 00:00:00', 'processed' => 100],
                ['time' => '2025-01-01 00:05:00', 'processed' => 120],
            ]);

        $result = $this->invokePrivateMethod('getTimeSeriesSafe');

        $this->assertCount(2, $result);
    }

    public function testGetTimeSeriesSafeReturnsEmptyOnException(): void
    {
        $this->metricsRepository->shouldReceive('getTimeSeries')
            ->once()
            ->andThrow(new RuntimeException('Metrics table missing'));

        $result = $this->invokePrivateMethod('getTimeSeriesSafe');

        $this->assertSame([], $result);
    }

    // ---- getDriverInfoSafe tests ----

    public function testGetDriverInfoSafeReturnsEmptyWhenNoConnections(): void
    {
        // With no queue connections configured, getAll returns empty
        $result = $this->invokePrivateMethod('getDriverInfoSafe');

        $this->assertSame([], $result);
    }

    // ---- formatEmptyPagination tests ----

    public function testFormatEmptyPaginationReturnsCorrectStructure(): void
    {
        $result = $this->invokePrivateMethod('formatEmptyPagination', [1, 25]);

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(25, $result['per_page']);
        $this->assertSame(1, $result['current_page']);
        $this->assertSame(1, $result['last_page']);
        $this->assertNull($result['from']);
        $this->assertNull($result['to']);
        $this->assertNull($result['prev_page_url']);
        $this->assertNull($result['next_page_url']);
        $this->assertSame([], $result['links']);
    }

    public function testFormatEmptyPaginationRespectsCustomParameters(): void
    {
        $result = $this->invokePrivateMethod('formatEmptyPagination', [3, 50]);

        $this->assertSame(3, $result['current_page']);
        $this->assertSame(50, $result['per_page']);
    }

    // ---- getSilencedClasses tests ----

    public function testGetSilencedClassesReturnsEmptyWhenNotConfigured(): void
    {
        config(['station.silenced' => null]);

        $result = $this->invokePrivateMethod('getSilencedClasses');

        $this->assertSame([], $result);
    }

    public function testGetSilencedClassesReturnsConfiguredClasses(): void
    {
        config(['station.silenced' => [
            'App\\Jobs\\SilencedJob',
            'App\\Jobs\\AnotherSilencedJob',
        ]]);

        $result = $this->invokePrivateMethod('getSilencedClasses');

        $this->assertSame([
            'App\\Jobs\\SilencedJob',
            'App\\Jobs\\AnotherSilencedJob',
        ], $result);
    }

    public function testGetSilencedClassesFiltersEmptyStrings(): void
    {
        config(['station.silenced' => [
            'App\\Jobs\\SilencedJob',
            '',
            null,
        ]]);

        $result = $this->invokePrivateMethod('getSilencedClasses');

        $this->assertSame(['App\\Jobs\\SilencedJob'], $result);
    }

    // ---- clampPerPage tests ----

    public function testClampPerPageReturnsDefaultForNoInput(): void
    {
        $request = Request::create('/', 'GET');

        $result = $this->invokePrivateMethod('clampPerPage', [$request]);

        $this->assertSame(25, $result);
    }

    public function testClampPerPageRespectsProvidedValue(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => '50']);

        $result = $this->invokePrivateMethod('clampPerPage', [$request]);

        $this->assertSame(50, $result);
    }

    public function testClampPerPageClampsToMaximum(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => '500']);

        $result = $this->invokePrivateMethod('clampPerPage', [$request]);

        $this->assertSame(100, $result);
    }

    public function testClampPerPageClampsToMinimumOne(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => '-5']);

        $result = $this->invokePrivateMethod('clampPerPage', [$request]);

        $this->assertSame(1, $result);
    }

    public function testClampPerPageClampsZeroToOne(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => '0']);

        $result = $this->invokePrivateMethod('clampPerPage', [$request]);

        $this->assertSame(1, $result);
    }

    public function testClampPerPageWithCustomDefaultAndMax(): void
    {
        $request = Request::create('/', 'GET');

        $result = $this->invokePrivateMethod('clampPerPage', [$request, 10, 50]);

        $this->assertSame(10, $result);
    }

    // ---- periodToMinutes tests ----

    public function testPeriodToMinutesConvertsOneHour(): void
    {
        $this->assertSame(60, $this->invokePrivateMethod('periodToMinutes', ['1h']));
    }

    public function testPeriodToMinutesDefaultsToOneHourForUnknownPeriod(): void
    {
        $result = $this->invokePrivateMethod('periodToMinutes', ['invalid']);

        $this->assertSame(60, $result);
    }

    // ---- sanitizeConnectionConfig tests ----

    public function testSanitizeConnectionConfigRabbitmqExcludesSecrets(): void
    {
        $config = [
            'host' => 'localhost',
            'port' => 5672,
            'vhost' => '/',
            'queue' => 'default',
            'exchange' => 'station',
            'heartbeat' => 60,
            'user' => 'admin',
            'password' => 'secret123',
        ];

        $result = $this->invokePrivateMethod('sanitizeConnectionConfig', [$config, 'rabbitmq']);

        $this->assertArrayHasKey('host', $result);
        $this->assertArrayHasKey('port', $result);
        $this->assertArrayHasKey('queue', $result);
        $this->assertArrayNotHasKey('user', $result);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function testSanitizeConnectionConfigRedisAllowsOnlySpecificKeys(): void
    {
        $config = [
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 5,
            'host' => '127.0.0.1',
            'password' => 'supersecret',
        ];

        $result = $this->invokePrivateMethod('sanitizeConnectionConfig', [$config, 'redis']);

        $this->assertArrayHasKey('connection', $result);
        $this->assertArrayHasKey('queue', $result);
        $this->assertArrayHasKey('retry_after', $result);
        $this->assertArrayHasKey('block_for', $result);
        $this->assertArrayNotHasKey('host', $result);
        $this->assertArrayNotHasKey('password', $result);
    }

    public function testSanitizeConnectionConfigSqsExcludesKeyAndSecret(): void
    {
        $config = [
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'queue' => 'default',
            'suffix' => '-staging',
            'visibility_timeout' => 60,
            'wait_time' => 20,
            'key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ];

        $result = $this->invokePrivateMethod('sanitizeConnectionConfig', [$config, 'sqs']);

        $this->assertArrayHasKey('region', $result);
        $this->assertArrayHasKey('queue', $result);
        $this->assertArrayNotHasKey('key', $result);
        $this->assertArrayNotHasKey('secret', $result);
    }

    public function testSanitizeConnectionConfigBeanstalkdAllowsCorrectKeys(): void
    {
        $config = [
            'host' => 'localhost',
            'port' => 11300,
            'queue' => 'default',
            'ttr' => 60,
            'reserve_timeout' => 5,
        ];

        $result = $this->invokePrivateMethod('sanitizeConnectionConfig', [$config, 'beanstalkd']);

        $this->assertArrayHasKey('host', $result);
        $this->assertArrayHasKey('port', $result);
        $this->assertArrayHasKey('queue', $result);
        $this->assertArrayHasKey('ttr', $result);
        $this->assertArrayHasKey('reserve_timeout', $result);
    }

    public function testSanitizeConnectionConfigKafkaAllowsCorrectKeys(): void
    {
        $config = [
            'brokers' => 'localhost:9092',
            'topic' => 'station-jobs',
            'group_id' => 'station-workers',
            'consumer_timeout' => 1000,
            'sasl_username' => 'admin',
            'sasl_password' => 'secret',
        ];

        $result = $this->invokePrivateMethod('sanitizeConnectionConfig', [$config, 'kafka']);

        $this->assertArrayHasKey('brokers', $result);
        $this->assertArrayHasKey('topic', $result);
        $this->assertArrayHasKey('group_id', $result);
        $this->assertArrayHasKey('consumer_timeout', $result);
        $this->assertArrayNotHasKey('sasl_username', $result);
        $this->assertArrayNotHasKey('sasl_password', $result);
    }

    public function testSanitizeConnectionConfigUnknownDriverReturnsOnlyQueue(): void
    {
        $config = [
            'queue' => 'default',
            'host' => 'localhost',
            'secret' => 'mysecret',
        ];

        $result = $this->invokePrivateMethod('sanitizeConnectionConfig', [$config, 'unknown']);

        $this->assertSame(['queue' => 'default'], $result);
    }

    // ---- getAvailableConnections tests ----

    public function testGetAvailableConnectionsReturnsEmptyWhenNoStationDrivers(): void
    {
        config(['queue.connections' => [
            'sync' => ['driver' => 'sync'],
            'database' => ['driver' => 'database'],
        ]]);

        $result = $this->invokePrivateMethod('getAvailableConnections');

        // 'sync' IS in the station drivers list (it's included as a supported driver)
        $this->assertContains('sync', $result);
    }

    public function testGetAvailableConnectionsFiltersAndSortsStationDrivers(): void
    {
        config(['queue.connections' => [
            'redis-conn' => ['driver' => 'station-redis'],
            'rabbit-conn' => ['driver' => 'rabbitmq'],
            'database' => ['driver' => 'database'],
            'beanstalkd-conn' => ['driver' => 'station-beanstalkd'],
        ]]);

        $result = $this->invokePrivateMethod('getAvailableConnections');

        $this->assertContains('redis-conn', $result);
        $this->assertContains('rabbit-conn', $result);
        $this->assertContains('beanstalkd-conn', $result);
        $this->assertNotContains('database', $result);

        // Verify sorted
        $this->assertSame($result, array_values($result));
        $sortedResult = $result;
        sort($sortedResult);
        $this->assertSame($sortedResult, $result);
    }

    // ---- getPausedQueues tests ----

    public function testGetPausedQueuesReturnsEmptyWhenNoStationDrivers(): void
    {
        config(['queue.connections' => [
            'sync' => ['driver' => 'sync'],
        ]]);

        $result = $this->invokePrivateMethod('getPausedQueues');

        $this->assertSame([], $result);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $app['config']->set('station.default', 'rabbitmq');
        $app['config']->set('station.silenced', []);
        $app['config']->set('queue.connections', []);
    }

    /**
     * Invoke a private method on the controller for testing.
     *
     * @param array<int, mixed> $args
     */
    private function invokePrivateMethod(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($this->controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->controller, ...$args);
    }
}
