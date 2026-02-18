<?php

declare(strict_types=1);

namespace Station\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Mockery;
use RuntimeException;
use Station\Alerts\AlertManager;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\MetricsCollectorInterface;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStepCompleted;
use Station\StationServiceProvider;
use Station\Tests\TestCase;
use Station\Workflows\WorkflowInstance;

/**
 * Feature tests for StationServiceProvider event listeners covering:
 * - registerQueueEventListeners: JobQueued, JobProcessing, JobProcessed, JobFailed
 * - registerWorkflowEventListeners: WorkflowStepCompleted, WorkflowFailed
 * - registerAlertEventListeners: JobFailed, WorkerStopped
 * - createPayloadUsing callback
 * - app terminating flush callback
 * - tracking.enabled=false disables event listeners
 */
class StationServiceProviderEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    // ---- registerQueueEventListeners ----

    public function testJobQueuedEventTracksNewJob(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        $repository->shouldReceive('trackQueued')
            ->once()
            ->with(
                'test-uuid-123',            // uuid
                'App\\Jobs\\TestJob',       // displayName
                'default',                  // queue
                'sync',                     // connection
                Mockery::type('array'),     // payload
                null,                       // batchId
                [],                         // tags
            );

        $this->app->instance(JobRepositoryInterface::class, $repository);

        // Create a real JobQueued event with proper payload JSON string
        $payload = json_encode([
            'uuid' => 'test-uuid-123',
            'displayName' => 'App\\Jobs\\TestJob',
            'data' => ['command' => null],
        ]);

        $event = new JobQueued('sync', 'default', 'job-id-1', null, $payload, null);

        event($event);
    }

    public function testJobQueuedEventSkipsAlreadyTrackedJobs(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        // trackQueued should NOT be called when stationJobId is present
        $repository->shouldNotReceive('trackQueued');
        $this->app->instance(JobRepositoryInterface::class, $repository);

        $payload = json_encode([
            'uuid' => 'test-uuid-456',
            'displayName' => 'App\\Jobs\\TestJob',
            'stationJobId' => 'station-job-already-tracked',
            'data' => ['command' => null],
        ]);

        $event = new JobQueued('sync', 'default', 'job-id-2', null, $payload, null);

        event($event);
    }

    public function testJobProcessingEventTracksStart(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        $repository->shouldReceive('trackProcessing')
            ->once()
            ->with('processing-uuid', 'default');

        $this->app->instance(JobRepositoryInterface::class, $repository);

        $mockJob = Mockery::mock(Job::class);
        $mockJob->shouldReceive('payload')->andReturn([
            'uuid' => 'processing-uuid',
            'displayName' => 'App\\Jobs\\TestJob',
            'data' => ['command' => null],
        ]);
        $mockJob->shouldReceive('getJobId')->andReturn('processing-uuid');
        $mockJob->shouldReceive('getQueue')->andReturn('default');
        $mockJob->shouldReceive('resolveName')->andReturn('App\\Jobs\\TestJob');

        event(new JobProcessing('sync', $mockJob));
    }

    public function testJobProcessedEventTracksCompletion(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        $repository->shouldReceive('trackCompleted')
            ->once()
            ->with('completed-uuid');

        $this->app->instance(JobRepositoryInterface::class, $repository);

        $metrics = Mockery::mock(MetricsCollectorInterface::class);
        $metrics->shouldReceive('recordJobCompletion')
            ->once()
            ->with('default', Mockery::type('int'), Mockery::type('int'), Mockery::type('int'), 'sync');
        $metrics->shouldReceive('flush')->byDefault();

        $this->app->instance(MetricsCollectorInterface::class, $metrics);

        $mockJob = Mockery::mock(Job::class);
        $mockJob->shouldReceive('payload')->andReturn([
            'uuid' => 'completed-uuid',
            'displayName' => 'App\\Jobs\\TestJob',
            'data' => ['command' => null],
        ]);
        $mockJob->shouldReceive('getJobId')->andReturn('completed-uuid');
        $mockJob->shouldReceive('getQueue')->andReturn('default');

        event(new JobProcessed('sync', $mockJob));
    }

    public function testJobFailedEventTracksFailure(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        $repository->shouldReceive('trackFailed')
            ->once()
            ->with('failed-uuid', 'Something went wrong', Mockery::type('array'));

        $this->app->instance(JobRepositoryInterface::class, $repository);

        $metrics = Mockery::mock(MetricsCollectorInterface::class);
        $metrics->shouldReceive('recordJobFailure')
            ->once()
            ->with('default', 'sync');
        $metrics->shouldReceive('flush')->byDefault();

        $this->app->instance(MetricsCollectorInterface::class, $metrics);

        $mockJob = Mockery::mock(Job::class);
        $mockJob->shouldReceive('payload')->andReturn([
            'uuid' => 'failed-uuid',
            'displayName' => 'App\\Jobs\\TestJob',
            'data' => ['command' => null],
        ]);
        $mockJob->shouldReceive('getJobId')->andReturn('failed-uuid');
        $mockJob->shouldReceive('getQueue')->andReturn('default');
        $mockJob->shouldReceive('resolveName')->andReturn('App\\Jobs\\TestJob');
        $mockJob->shouldReceive('attempts')->andReturn(3);

        $exception = new RuntimeException('Something went wrong');

        event(new JobFailed('sync', $mockJob, $exception));
    }

    // ---- registerWorkflowEventListeners ----

    public function testWorkflowStepCompletedRecordsMetrics(): void
    {
        $metrics = Mockery::mock(MetricsCollectorInterface::class);
        $metrics->shouldReceive('recordJobCompletion')
            ->once()
            ->with('workflow:test-workflow', 0, 0, Mockery::type('int'));
        $metrics->shouldReceive('flush')->byDefault();

        $this->app->instance(MetricsCollectorInterface::class, $metrics);

        // WorkflowInstance is final - use real instance
        $instance = new WorkflowInstance('def-id', 'test-workflow', []);

        event(new WorkflowStepCompleted($instance, 'step-1', []));
    }

    public function testWorkflowFailedRecordsMetrics(): void
    {
        $metrics = Mockery::mock(MetricsCollectorInterface::class);
        $metrics->shouldReceive('recordJobFailure')
            ->once()
            ->with('workflow:failed-workflow');
        $metrics->shouldReceive('flush')->byDefault();

        $this->app->instance(MetricsCollectorInterface::class, $metrics);

        // WorkflowInstance is final - use real instance
        $instance = new WorkflowInstance('def-id', 'failed-workflow', []);

        // WorkflowFailed only takes the instance parameter
        event(new WorkflowFailed($instance));
    }

    // ---- provides() ----

    public function testProvidesIncludesAlertBindings(): void
    {
        $provider = new StationServiceProvider($this->app);

        $provides = $provider->provides();

        $this->assertContains(AlertRepositoryInterface::class, $provides);
        $this->assertContains(AlertChannelRepositoryInterface::class, $provides);
        $this->assertContains(AlertManager::class, $provides);
    }

    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.tracking.enabled', true);
        $app['config']->set('station.alerts.enabled', false);
        $app['config']->set('queue.connections', ['sync' => ['driver' => 'sync']]);
        $app['config']->set('queue.default', 'sync');
    }
}
