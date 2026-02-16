<?php

declare(strict_types=1);

namespace Station\Tests\Unit;

use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use Station\Contracts\BatchRepositoryInterface;
use Station\Contracts\CheckpointManagerInterface;
use Station\Contracts\CheckpointRepositoryInterface;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\MetricsCollectorInterface;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;
use Station\Contracts\WorkerSupervisorInterface;
use Station\Core\BatchManager;
use Station\Core\DriverInfoCollector;
use Station\Core\JobManager;
use Station\Core\MetricsCollector;
use Station\Core\ProcessManager;
use Station\Core\QueueManager as StationQueueManager;
use Station\Core\WorkerSupervisor;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStepCompleted;
use Station\Recovery\CheckpointManager;
use Station\Recovery\HealthChecker;
use Station\Recovery\JobResumer;
use Station\Recovery\StuckJobDetector;
use Station\Repositories\DatabaseBatchRepository;
use Station\Repositories\DatabaseCheckpointRepository;
use Station\Repositories\DatabaseJobRepository;
use Station\Repositories\DatabaseMetricsRepository;
use Station\Repositories\DatabaseSupervisorRepository;
use Station\Repositories\DatabaseWorkerRepository;
use Station\Scaling\AutoScaler;
use Station\StationServiceProvider;
use Station\Telemetry\TelemetryManager;
use Station\Workflows\WorkflowManager;
use stdClass;

/**
 * Tests for StationServiceProvider covering:
 * - Service container bindings resolve to correct implementations
 * - Singleton bindings return the same instance
 * - Provides array
 * - Event listener registration
 * - Route registration based on config
 * - extractStationJobId and extractBatchId helpers
 */
class StationServiceProviderTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Repository bindings resolve to correct implementations
    // ──────────────────────────────────────────────────────────────

    public function testJobRepositoryResolvesToDatabaseImplementation(): void
    {
        $instance = $this->app->make(JobRepositoryInterface::class);

        $this->assertInstanceOf(DatabaseJobRepository::class, $instance);
    }

    public function testBatchRepositoryResolvesToDatabaseImplementation(): void
    {
        $instance = $this->app->make(BatchRepositoryInterface::class);

        $this->assertInstanceOf(DatabaseBatchRepository::class, $instance);
    }

    public function testCheckpointRepositoryResolvesToDatabaseImplementation(): void
    {
        $instance = $this->app->make(CheckpointRepositoryInterface::class);

        $this->assertInstanceOf(DatabaseCheckpointRepository::class, $instance);
    }

    public function testMetricsRepositoryResolvesToDatabaseImplementation(): void
    {
        $instance = $this->app->make(MetricsRepositoryInterface::class);

        $this->assertInstanceOf(DatabaseMetricsRepository::class, $instance);
    }

    public function testSupervisorRepositoryResolvesToDatabaseImplementation(): void
    {
        $instance = $this->app->make(SupervisorRepositoryInterface::class);

        $this->assertInstanceOf(DatabaseSupervisorRepository::class, $instance);
    }

    public function testWorkerRepositoryResolvesToDatabaseImplementation(): void
    {
        $instance = $this->app->make(WorkerRepositoryInterface::class);

        $this->assertInstanceOf(DatabaseWorkerRepository::class, $instance);
    }

    // ──────────────────────────────────────────────────────────────
    // Core service bindings resolve correctly and are singletons
    // ──────────────────────────────────────────────────────────────

    public function testJobManagerResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(JobManager::class);
        $instance2 = $this->app->make(JobManager::class);

        $this->assertInstanceOf(JobManager::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testBatchManagerResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(BatchManager::class);
        $instance2 = $this->app->make(BatchManager::class);

        $this->assertInstanceOf(BatchManager::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testMetricsCollectorResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(MetricsCollector::class);
        $instance2 = $this->app->make(MetricsCollector::class);

        $this->assertInstanceOf(MetricsCollector::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testWorkerSupervisorResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(WorkerSupervisor::class);
        $instance2 = $this->app->make(WorkerSupervisor::class);

        $this->assertInstanceOf(WorkerSupervisor::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testQueueManagerResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(StationQueueManager::class);
        $instance2 = $this->app->make(StationQueueManager::class);

        $this->assertInstanceOf(StationQueueManager::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testProcessManagerResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(ProcessManager::class);
        $instance2 = $this->app->make(ProcessManager::class);

        $this->assertInstanceOf(ProcessManager::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testDriverInfoCollectorResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(DriverInfoCollector::class);
        $instance2 = $this->app->make(DriverInfoCollector::class);

        $this->assertInstanceOf(DriverInfoCollector::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    // ──────────────────────────────────────────────────────────────
    // Recovery service bindings
    // ──────────────────────────────────────────────────────────────

    public function testCheckpointManagerResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(CheckpointManager::class);
        $instance2 = $this->app->make(CheckpointManager::class);

        $this->assertInstanceOf(CheckpointManager::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testStuckJobDetectorResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(StuckJobDetector::class);
        $instance2 = $this->app->make(StuckJobDetector::class);

        $this->assertInstanceOf(StuckJobDetector::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testJobResumerResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(JobResumer::class);
        $instance2 = $this->app->make(JobResumer::class);

        $this->assertInstanceOf(JobResumer::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testHealthCheckerResolvesAndIsSingleton(): void
    {
        $instance1 = $this->app->make(HealthChecker::class);
        $instance2 = $this->app->make(HealthChecker::class);

        $this->assertInstanceOf(HealthChecker::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    // ──────────────────────────────────────────────────────────────
    // Interface aliases resolve to the same singleton
    // ──────────────────────────────────────────────────────────────

    public function testJobManagerInterfaceResolvesToJobManager(): void
    {
        $concrete = $this->app->make(JobManager::class);
        $fromInterface = $this->app->make(JobManagerInterface::class);

        $this->assertSame($concrete, $fromInterface);
    }

    public function testMetricsCollectorInterfaceResolvesToMetricsCollector(): void
    {
        $concrete = $this->app->make(MetricsCollector::class);
        $fromInterface = $this->app->make(MetricsCollectorInterface::class);

        $this->assertSame($concrete, $fromInterface);
    }

    public function testWorkerSupervisorInterfaceResolvesToWorkerSupervisor(): void
    {
        $concrete = $this->app->make(WorkerSupervisor::class);
        $fromInterface = $this->app->make(WorkerSupervisorInterface::class);

        $this->assertSame($concrete, $fromInterface);
    }

    public function testCheckpointManagerInterfaceResolvesToCheckpointManager(): void
    {
        $concrete = $this->app->make(CheckpointManager::class);
        $fromInterface = $this->app->make(CheckpointManagerInterface::class);

        $this->assertSame($concrete, $fromInterface);
    }

    public function testHealthCheckerInterfaceResolvesToHealthChecker(): void
    {
        $concrete = $this->app->make(HealthChecker::class);
        $fromInterface = $this->app->make(HealthCheckerInterface::class);

        $this->assertSame($concrete, $fromInterface);
    }

    public function testStuckJobDetectorInterfaceResolvesToStuckJobDetector(): void
    {
        $concrete = $this->app->make(StuckJobDetector::class);
        $fromInterface = $this->app->make(StuckJobDetectorInterface::class);

        $this->assertSame($concrete, $fromInterface);
    }

    public function testJobResumerInterfaceResolvesToJobResumer(): void
    {
        $concrete = $this->app->make(JobResumer::class);
        $fromInterface = $this->app->make(JobResumerInterface::class);

        $this->assertSame($concrete, $fromInterface);
    }

    // ──────────────────────────────────────────────────────────────
    // String aliases resolve to the same singleton
    // ──────────────────────────────────────────────────────────────

    public function testStationAliasResolvesToJobManager(): void
    {
        $this->assertSame(
            $this->app->make(JobManager::class),
            $this->app->make('station'),
        );
    }

    public function testBatchAliasResolvesToBatchManager(): void
    {
        $this->assertSame(
            $this->app->make(BatchManager::class),
            $this->app->make('station.batch'),
        );
    }

    public function testMetricsAliasResolvesToMetricsCollector(): void
    {
        $this->assertSame(
            $this->app->make(MetricsCollector::class),
            $this->app->make('station.metrics'),
        );
    }

    public function testSupervisorAliasResolvesToWorkerSupervisor(): void
    {
        $this->assertSame(
            $this->app->make(WorkerSupervisor::class),
            $this->app->make('station.supervisor'),
        );
    }

    public function testQueuesAliasResolvesToQueueManager(): void
    {
        $this->assertSame(
            $this->app->make(StationQueueManager::class),
            $this->app->make('station.queues'),
        );
    }

    public function testCheckpointsAliasResolvesToCheckpointManager(): void
    {
        $this->assertSame(
            $this->app->make(CheckpointManager::class),
            $this->app->make('station.checkpoints'),
        );
    }

    public function testStuckDetectorAliasResolvesToStuckJobDetector(): void
    {
        $this->assertSame(
            $this->app->make(StuckJobDetector::class),
            $this->app->make('station.stuck_detector'),
        );
    }

    public function testResumerAliasResolvesToJobResumer(): void
    {
        $this->assertSame(
            $this->app->make(JobResumer::class),
            $this->app->make('station.resumer'),
        );
    }

    public function testHealthAliasResolvesToHealthChecker(): void
    {
        $this->assertSame(
            $this->app->make(HealthChecker::class),
            $this->app->make('station.health'),
        );
    }

    public function testWorkflowsAliasResolvesToWorkflowManager(): void
    {
        $this->assertSame(
            $this->app->make(WorkflowManager::class),
            $this->app->make('station.workflows'),
        );
    }

    public function testScalerAliasResolvesToAutoScaler(): void
    {
        $this->assertSame(
            $this->app->make(AutoScaler::class),
            $this->app->make('station.scaler'),
        );
    }

    public function testTelemetryAliasResolvesToTelemetryManager(): void
    {
        $this->assertSame(
            $this->app->make(TelemetryManager::class),
            $this->app->make('station.telemetry'),
        );
    }

    // ──────────────────────────────────────────────────────────────
    // provides() method
    // ──────────────────────────────────────────────────────────────

    public function testProvidesReturnsExpectedServices(): void
    {
        $provider = new StationServiceProvider($this->app);
        $provides = $provider->provides();

        $this->assertContains(JobManager::class, $provides);
        $this->assertContains(BatchManager::class, $provides);
        $this->assertContains(MetricsCollector::class, $provides);
        $this->assertContains(WorkerSupervisor::class, $provides);
        $this->assertContains(CheckpointManager::class, $provides);
        $this->assertContains(StuckJobDetector::class, $provides);
        $this->assertContains(JobResumer::class, $provides);
        $this->assertContains(HealthChecker::class, $provides);
        $this->assertContains(WorkflowManager::class, $provides);
        $this->assertContains(AutoScaler::class, $provides);
        $this->assertContains(TelemetryManager::class, $provides);
        $this->assertContains('station', $provides);
        $this->assertContains('station.batch', $provides);
        $this->assertContains('station.workflows', $provides);
        $this->assertContains('station.scaler', $provides);
        $this->assertContains('station.telemetry', $provides);
    }

    // ──────────────────────────────────────────────────────────────
    // extractStationJobId helper (via reflection)
    // ──────────────────────────────────────────────────────────────

    public function testExtractStationJobIdReturnsNullForEmptyPayload(): void
    {
        $this->assertNull($this->callExtractStationJobId([]));
    }

    public function testExtractStationJobIdReturnsNullWhenNoCommand(): void
    {
        $this->assertNull($this->callExtractStationJobId(['data' => ['command' => null]]));
    }

    public function testExtractStationJobIdReturnsNullOnInvalidSerialization(): void
    {
        $this->assertNull($this->callExtractStationJobId(['data' => ['command' => 'invalid_serialized']]));
    }

    public function testExtractStationJobIdReturnsIdFromObjectCommand(): void
    {
        $command = new stdClass();
        $command->stationJobId = 'test-station-id-123';

        $this->assertSame(
            'test-station-id-123',
            $this->callExtractStationJobId(['data' => ['command' => $command]]),
        );
    }

    public function testExtractStationJobIdReturnsNullWhenPropertyMissing(): void
    {
        $this->assertNull($this->callExtractStationJobId(['data' => ['command' => new stdClass()]]));
    }

    // ──────────────────────────────────────────────────────────────
    // extractBatchId helper (via reflection)
    // ──────────────────────────────────────────────────────────────

    public function testExtractBatchIdReturnsNullForEmptyPayload(): void
    {
        $this->assertNull($this->callExtractBatchId([]));
    }

    public function testExtractBatchIdReturnsNullWhenNoCommand(): void
    {
        $this->assertNull($this->callExtractBatchId(['data' => ['command' => null]]));
    }

    public function testExtractBatchIdReturnsIdFromBatchableCommand(): void
    {
        $command = new class {
            public ?string $batchId = 'batch-456';
        };

        $this->assertSame(
            'batch-456',
            $this->callExtractBatchId(['data' => ['command' => $command]]),
        );
    }

    public function testExtractBatchIdReturnsNullWhenBatchIdIsNull(): void
    {
        $command = new class {
            public ?string $batchId = null;
        };

        $this->assertNull($this->callExtractBatchId(['data' => ['command' => $command]]));
    }

    public function testExtractBatchIdReturnsNullWhenPropertyMissing(): void
    {
        $this->assertNull($this->callExtractBatchId(['data' => ['command' => new stdClass()]]));
    }

    // ──────────────────────────────────────────────────────────────
    // Route registration
    // ──────────────────────────────────────────────────────────────

    public function testDashboardRoutesNotRegisteredWhenDisabled(): void
    {
        $routes = $this->app['router']->getRoutes();
        $stationRoutes = array_filter(
            $routes->getRoutesByName(),
            static fn($name) => str_starts_with($name, 'station.'),
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertEmpty($stationRoutes);
    }

    // ──────────────────────────────────────────────────────────────
    // Event listener registration
    // ──────────────────────────────────────────────────────────────

    public function testQueueEventListenersAreRegisteredWhenTrackingEnabled(): void
    {
        $this->app['config']->set('station.tracking.enabled', true);

        $provider = new StationServiceProvider($this->app);
        $provider->boot();

        $events = $this->app['events'];

        $this->assertTrue($events->hasListeners(JobQueued::class));
        $this->assertTrue($events->hasListeners(JobProcessing::class));
        $this->assertTrue($events->hasListeners(JobProcessed::class));
        $this->assertTrue($events->hasListeners(JobFailed::class));
    }

    public function testQueueEventListenersNotRegisteredWhenTrackingDisabled(): void
    {
        // Use a fresh app with tracking disabled and no prior boot
        $this->app['config']->set('station.tracking.enabled', false);

        // Get the dispatcher before booting — it already has listeners from setUp
        // So create a fresh dispatcher to test cleanly
        $freshDispatcher = new Dispatcher($this->app);
        $this->app->instance('events', $freshDispatcher);

        $provider = new StationServiceProvider($this->app);
        $provider->boot();

        // With tracking disabled, queue event listeners should not be registered
        $this->assertFalse($freshDispatcher->hasListeners(JobQueued::class));
        $this->assertFalse($freshDispatcher->hasListeners(JobProcessing::class));
        $this->assertFalse($freshDispatcher->hasListeners(JobProcessed::class));
        $this->assertFalse($freshDispatcher->hasListeners(JobFailed::class));
    }

    public function testWorkflowEventListenersAreRegisteredWhenTrackingEnabled(): void
    {
        $this->app['config']->set('station.tracking.enabled', true);

        $provider = new StationServiceProvider($this->app);
        $provider->boot();

        $events = $this->app['events'];

        $this->assertTrue($events->hasListeners(WorkflowStepCompleted::class));
        $this->assertTrue($events->hasListeners(WorkflowFailed::class));
    }

    // ──────────────────────────────────────────────────────────────
    // Middleware registration
    // ──────────────────────────────────────────────────────────────

    public function testMiddlewareAliasesAreRegistered(): void
    {
        $middleware = $this->app['router']->getMiddleware();

        $this->assertArrayHasKey('station.auth', $middleware);
        $this->assertArrayHasKey('station.security', $middleware);
        $this->assertArrayHasKey('station.api.auth', $middleware);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Helper methods
    // ──────────────────────────────────────────────────────────────

    private function callExtractStationJobId(array $payload): ?string
    {
        $provider = new StationServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'extractStationJobId');
        $method->setAccessible(true);

        return $method->invoke($provider, $payload);
    }

    private function callExtractBatchId(array $payload): ?string
    {
        $provider = new StationServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'extractBatchId');
        $method->setAccessible(true);

        return $method->invoke($provider, $payload);
    }
}
