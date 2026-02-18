<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
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
use Station\DTOs\JobStats;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\MetricsSnapshot;
use Station\StationServiceProvider;
use Station\Telemetry\InternalMeter;
use Station\Telemetry\MeterInterface;
use Station\Telemetry\TelemetryManager;

/**
 * Feature tests for the Prometheus metrics export endpoint.
 *
 * Tests the MetricsController::prometheus() method through both the web (session)
 * and API (token) route, covering:
 * - InternalMeter available: 200 with text/plain prometheus output
 * - No meter (telemetry disabled): 501 with explanatory message
 * - Non-InternalMeter (e.g. OpenTelemetry): 501 with explanatory message
 * - Exception thrown: 500 with error message
 *
 * Since TelemetryManager is a final class, real instances are created with
 * controlled configuration and reflection is used where needed to inject
 * specific meter implementations.
 */
class PrometheusEndpointTest extends TestCase
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

    // ---------------------------------------------------------------
    // Data provider for Prometheus endpoint scenarios
    // ---------------------------------------------------------------

    /**
     * Each dataset specifies a meter setup strategy and the expected HTTP response.
     *
     * @return array<string, array{meterSetup: string, expectedStatus: int, expectedContentType: string, expectedBodyContains: string, description: string}>
     */
    public static function prometheusEndpointDataProvider(): array
    {
        return [
            'internal_meter_with_metrics_returns_200' => [
                'meterSetup' => 'internal_with_metrics',
                'expectedStatus' => 200,
                'expectedContentType' => 'text/plain; charset=utf-8',
                'expectedBodyContains' => 'station_jobs_total',
                'description' => 'InternalMeter with recorded metrics should return 200 with Prometheus output',
            ],
            'internal_meter_empty_returns_200' => [
                'meterSetup' => 'internal_empty',
                'expectedStatus' => 200,
                'expectedContentType' => 'text/plain; charset=utf-8',
                'expectedBodyContains' => '',
                'description' => 'InternalMeter with no metrics should return 200 with empty body',
            ],
            'null_meter_telemetry_disabled_returns_501' => [
                'meterSetup' => 'null_meter',
                'expectedStatus' => 501,
                'expectedContentType' => 'text/plain',
                'expectedBodyContains' => 'Prometheus export requires the internal meter driver',
                'description' => 'Null meter (telemetry disabled) should return 501',
            ],
            'non_internal_meter_returns_501' => [
                'meterSetup' => 'non_internal_meter',
                'expectedStatus' => 501,
                'expectedContentType' => 'text/plain',
                'expectedBodyContains' => 'Prometheus export requires the internal meter driver',
                'description' => 'Non-InternalMeter (e.g. OpenTelemetry) should return 501',
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Web route tests (session-authenticated, named station.api.metrics.prometheus)
    // ---------------------------------------------------------------

    #[DataProvider('prometheusEndpointDataProvider')]
    public function testPrometheusWebRouteReturnsExpectedResponse(
        string $meterSetup,
        int $expectedStatus,
        string $expectedContentType,
        string $expectedBodyContains,
        string $description,
    ): void {
        $this->configureTelemetryManager($meterSetup);

        $response = $this->get('/station/api/metrics/prometheus');

        $response->assertStatus($expectedStatus);
        $this->assertStringContainsString(
            $expectedContentType,
            $response->headers->get('Content-Type', ''),
            $description . ' - Content-Type mismatch on web route',
        );

        if ($expectedBodyContains !== '') {
            $this->assertStringContainsString(
                $expectedBodyContains,
                $response->getContent(),
                $description . ' - body content mismatch on web route',
            );
        }
    }

    // ---------------------------------------------------------------
    // API route tests (token-authenticated)
    // ---------------------------------------------------------------

    #[DataProvider('prometheusEndpointDataProvider')]
    public function testPrometheusApiRouteReturnsExpectedResponse(
        string $meterSetup,
        int $expectedStatus,
        string $expectedContentType,
        string $expectedBodyContains,
        string $description,
    ): void {
        $this->configureTelemetryManager($meterSetup);

        $response = $this->get('/api/station/metrics/prometheus');

        $response->assertStatus($expectedStatus);
        $this->assertStringContainsString(
            $expectedContentType,
            $response->headers->get('Content-Type', ''),
            $description . ' - Content-Type mismatch on API route',
        );

        if ($expectedBodyContains !== '') {
            $this->assertStringContainsString(
                $expectedBodyContains,
                $response->getContent(),
                $description . ' - body content mismatch on API route',
            );
        }
    }

    // ---------------------------------------------------------------
    // Additional specific tests
    // ---------------------------------------------------------------

    public function testPrometheusWithCountersGaugesAndHistogramsFormatsCorrectly(): void
    {
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('listen')->zeroOrMoreTimes();

        $telemetry = new TelemetryManager($events, ['enabled' => true]);

        // Get the InternalMeter and populate it with metrics
        $meter = $telemetry->getMeter();
        $this->assertInstanceOf(InternalMeter::class, $meter);

        $meter->incrementCounter('station_jobs_processed', ['queue' => 'default'], 42);
        $meter->incrementCounter('station_jobs_failed', ['queue' => 'emails'], 3);
        $meter->recordValue('station_workers_active', 5.0, ['connection' => 'redis']);
        $meter->recordHistogram('station_processing_time', 150.5, ['queue' => 'default']);
        $meter->recordHistogram('station_processing_time', 200.0, ['queue' => 'default']);

        $this->app->instance(TelemetryManager::class, $telemetry);

        $response = $this->get('/station/api/metrics/prometheus');

        $response->assertStatus(200);
        $content = $response->getContent();

        // Verify counter output
        $this->assertStringContainsString('station_jobs_processed', $content);
        $this->assertStringContainsString('42', $content);
        $this->assertStringContainsString('queue="default"', $content);

        // Verify gauge output
        $this->assertStringContainsString('station_workers_active', $content);
        $this->assertStringContainsString('connection="redis"', $content);

        // Verify histogram output
        $this->assertStringContainsString('station_processing_time_count', $content);
        $this->assertStringContainsString('station_processing_time_sum', $content);
    }

    public function testPrometheusWithInternalMeterReturnsTextPlainWithCharsetUtf8(): void
    {
        $this->configureTelemetryManager('internal_empty');

        $response = $this->get('/station/api/metrics/prometheus');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'text/plain',
            $response->headers->get('Content-Type', ''),
        );
        $this->assertStringContainsString(
            'charset=utf-8',
            $response->headers->get('Content-Type', ''),
        );
    }

    public function testPrometheusNonInternalMeterReturnsPlainText501(): void
    {
        $this->configureTelemetryManager('non_internal_meter');

        $response = $this->get('/station/api/metrics/prometheus');

        $response->assertStatus(501);
        // 501 response should be text/plain
        $this->assertStringContainsString(
            'text/plain',
            $response->headers->get('Content-Type', ''),
        );
    }

    public function testPrometheusExceptionReturns500WithErrorMessage(): void
    {
        // Create a TelemetryManager where getMeter() will cause an exception
        // by binding an anonymous class wrapper that throws on getMeter()
        $this->app->bind(TelemetryManager::class, static function (): void {
            throw new RuntimeException('Connection lost to metrics backend');
        });

        $response = $this->get('/station/api/metrics/prometheus');

        $response->assertStatus(500);
        $this->assertStringContainsString(
            'Failed to export metrics',
            $response->getContent(),
        );
        $this->assertStringContainsString(
            'Connection lost to metrics backend',
            $response->getContent(),
        );
    }

    public function testPrometheusWebRouteNameIsCorrect(): void
    {
        $this->configureTelemetryManager('internal_empty');

        $url = route('station.api.metrics.prometheus');
        $this->assertStringContainsString('/station/api/metrics/prometheus', $url);
    }

    public function testPrometheusApiRouteAndWebRouteReturnSameContent(): void
    {
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('listen')->zeroOrMoreTimes();

        $telemetry = new TelemetryManager($events, ['enabled' => true]);
        $meter = $telemetry->getMeter();
        $this->assertInstanceOf(InternalMeter::class, $meter);
        $meter->incrementCounter('test_counter', [], 10);

        $this->app->instance(TelemetryManager::class, $telemetry);

        $webResponse = $this->get('/station/api/metrics/prometheus');
        $apiResponse = $this->get('/api/station/metrics/prometheus');

        $webResponse->assertStatus(200);
        $apiResponse->assertStatus(200);

        // Both routes should return the same content
        $this->assertSame($webResponse->getContent(), $apiResponse->getContent());
    }

    public function testPrometheusTelemetryDisabledReturns501OnBothRoutes(): void
    {
        $this->configureTelemetryManager('null_meter');

        $webResponse = $this->get('/station/api/metrics/prometheus');
        $apiResponse = $this->get('/api/station/metrics/prometheus');

        $webResponse->assertStatus(501);
        $apiResponse->assertStatus(501);
    }

    public function testPrometheusResponseContainsProperMetricFormat(): void
    {
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('listen')->zeroOrMoreTimes();

        $telemetry = new TelemetryManager($events, ['enabled' => true]);
        $meter = $telemetry->getMeter();
        $this->assertInstanceOf(InternalMeter::class, $meter);

        // Add a counter with labels to verify Prometheus label formatting
        $meter->incrementCounter('station_http_requests', ['method' => 'GET', 'status' => '200'], 50);

        $this->app->instance(TelemetryManager::class, $telemetry);

        $response = $this->get('/station/api/metrics/prometheus');

        $response->assertStatus(200);
        $content = $response->getContent();

        // Prometheus format: metric_name{label1="value1",label2="value2"} value
        $this->assertMatchesRegularExpression(
            '/station_http_requests\{.*method="GET".*\}\s+50/',
            $content,
            'Prometheus output should contain properly formatted metric with labels',
        );
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

    /**
     * Configure the TelemetryManager in the service container based on the test scenario.
     *
     * Since TelemetryManager is final, we create real instances with appropriate
     * configuration. For the non-InternalMeter scenario, we use reflection to
     * replace the meter property with a non-InternalMeter implementation.
     */
    private function configureTelemetryManager(string $setup): void
    {
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('listen')->zeroOrMoreTimes();

        match ($setup) {
            'internal_with_metrics' => (function () use ($events): void {
                $telemetry = new TelemetryManager($events, ['enabled' => true]);
                $meter = $telemetry->getMeter();
                // Populate with some metrics
                $meter->incrementCounter('station_jobs_total', ['queue' => 'default'], 100);
                $this->app->instance(TelemetryManager::class, $telemetry);
            })(),
            'internal_empty' => (function () use ($events): void {
                $telemetry = new TelemetryManager($events, ['enabled' => true]);
                $this->app->instance(TelemetryManager::class, $telemetry);
            })(),
            'null_meter' => (function () use ($events): void {
                // Disabled telemetry: getMeter() returns null
                $telemetry = new TelemetryManager($events, ['enabled' => false]);
                $this->app->instance(TelemetryManager::class, $telemetry);
            })(),
            'non_internal_meter' => (function () use ($events): void {
                // Create enabled TelemetryManager then replace its meter with a non-InternalMeter
                $telemetry = new TelemetryManager($events, ['enabled' => true]);

                // Use reflection to replace the meter with a non-InternalMeter implementation
                $nonInternalMeter = new class implements MeterInterface {
                    public function incrementCounter(string $name, array $labels = [], int $value = 1): void {}

                    public function recordValue(string $name, float $value, array $labels = []): void {}

                    public function recordHistogram(string $name, float $value, array $labels = []): void {}
                };

                $reflection = new ReflectionClass($telemetry);
                $meterProp = $reflection->getProperty('meter');
                $meterProp->setValue($telemetry, $nonInternalMeter);

                $this->app->instance(TelemetryManager::class, $telemetry);
            })(),
        };
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
            new MetricsAggregation(jobs_processed: 0, jobs_failed: 0, avg_processing_time: 0.0, avg_wait_time: 0.0, failure_rate: 0.0),
        );
        $this->metricsRepository->shouldReceive('getGlobalAggregated')->byDefault()->andReturn(
            new MetricsAggregation(jobs_processed: 0, jobs_failed: 0, avg_processing_time: 0.0, avg_wait_time: 0.0, failure_rate: 0.0),
        );
        $this->metricsRepository->shouldReceive('getTimeSeries')->byDefault()->andReturn([]);
        $this->metricsRepository->shouldReceive('store')->byDefault();
        $this->metricsRepository->shouldReceive('getHistoricalMetrics')->byDefault()->andReturn([]);

        $this->laravelQueueManager = Mockery::mock(LaravelQueueManager::class);
    }

    private function bindController(): void
    {
        $metricsCollector = new MetricsCollector($this->metricsRepository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        $driverInfoCollector = new DriverInfoCollector($this->laravelQueueManager);
        $processManager = new ProcessManager(['enabled' => false]);

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
