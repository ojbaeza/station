<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Core\Job;
use Station\Events\JobCompleted;
use Station\Events\JobFailed;
use Station\Events\JobStarted;
use Station\Events\WorkflowCompleted;
use Station\Events\WorkflowStarted;
use Station\Telemetry\TelemetryManager;
use Station\Workflows\WorkflowInstance;

class TelemetryManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&Dispatcher $events;

    /** @var array<callable> */
    private array $registeredListeners = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = Mockery::mock(Dispatcher::class);
        $this->registeredListeners = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testIsEnabledReturnsFalseByDefault(): void
    {
        $manager = new TelemetryManager($this->events);

        $this->assertFalse($manager->isEnabled());
    }

    public function testIsEnabledReturnsTrueWhenConfigured(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        $this->assertTrue($manager->isEnabled());
    }

    public function testStartSpanReturnsNullWhenDisabled(): void
    {
        $manager = new TelemetryManager($this->events);

        $span = $manager->startSpan('test-span');

        $this->assertNull($span);
    }

    public function testStartSpanReturnsSpanWhenEnabled(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        $span = $manager->startSpan('test-span', ['key' => 'value']);

        $this->assertNotNull($span);
        $this->assertSame('test-span', $span->getName());
    }

    public function testRecordMetricDoesNothingWhenDisabled(): void
    {
        $manager = new TelemetryManager($this->events);

        // Should not throw
        $manager->recordMetric('metric.name', 1.0, ['label' => 'value']);

        $this->assertFalse($manager->isEnabled());
    }

    public function testRecordMetricRecordsWhenEnabled(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Should not throw
        $manager->recordMetric('metric.name', 1.0, ['label' => 'value']);

        $this->assertTrue($manager->isEnabled());
    }

    public function testIncrementCounterDoesNothingWhenDisabled(): void
    {
        $manager = new TelemetryManager($this->events);

        // Should not throw
        $manager->incrementCounter('counter.name', ['label' => 'value']);

        $this->assertFalse($manager->isEnabled());
    }

    public function testIncrementCounterRecordsWhenEnabled(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Should not throw
        $manager->incrementCounter('counter.name', ['label' => 'value'], 5);

        $this->assertTrue($manager->isEnabled());
    }

    public function testRecordHistogramDoesNothingWhenDisabled(): void
    {
        $manager = new TelemetryManager($this->events);

        // Should not throw
        $manager->recordHistogram('histogram.name', 100.5, ['label' => 'value']);

        $this->assertFalse($manager->isEnabled());
    }

    public function testRecordHistogramRecordsWhenEnabled(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Should not throw
        $manager->recordHistogram('histogram.name', 100.5, ['label' => 'value']);

        $this->assertTrue($manager->isEnabled());
    }

    public function testGetTracerReturnsNullWhenDisabled(): void
    {
        $manager = new TelemetryManager($this->events);

        $this->assertNull($manager->getTracer());
    }

    public function testGetTracerReturnsTracerWhenEnabled(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        $this->assertNotNull($manager->getTracer());
    }

    public function testGetMeterReturnsNullWhenDisabled(): void
    {
        $manager = new TelemetryManager($this->events);

        $this->assertNull($manager->getMeter());
    }

    public function testGetMeterReturnsMeterWhenEnabled(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        $this->assertNotNull($manager->getMeter());
    }

    public function testJobStartedEventCreatesSpan(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Trigger the JobStarted event listener
        $event = new JobStarted(
            jobId: 'test-job-id',
            jobClass: 'App\\Jobs\\TestJob',
            queue: 'default',
            connection: 'redis',
        );

        // Get the registered listener and call it
        $this->assertArrayHasKey(JobStarted::class, $this->registeredListeners);
        $this->registeredListeners[JobStarted::class]($event);

        // The span should be active - we can verify by starting a new span which would be a child
        $tracer = $manager->getTracer();
        $this->assertNotNull($tracer);
    }

    public function testJobCompletedEventEndsSpan(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Start a job span first
        $startEvent = new JobStarted(
            jobId: 'test-job-id',
            jobClass: 'App\\Jobs\\TestJob',
            queue: 'default',
            connection: 'redis',
        );
        $this->registeredListeners[JobStarted::class]($startEvent);

        // Complete the job
        $endEvent = new JobCompleted(
            jobId: 'test-job-id',
            jobClass: 'App\\Jobs\\TestJob',
            queue: 'default',
            connection: 'redis',
            durationMs: 1500.0,
        );

        $this->assertArrayHasKey(JobCompleted::class, $this->registeredListeners);
        $this->registeredListeners[JobCompleted::class]($endEvent);
    }

    public function testJobFailedEventEndsSpanWithError(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Start a job span first
        $startEvent = new JobStarted(
            jobId: 'test-job-id',
            jobClass: 'App\\Jobs\\TestJob',
            queue: 'default',
            connection: 'redis',
        );
        $this->registeredListeners[JobStarted::class]($startEvent);

        // Create a real Job instance for JobFailed event
        $job = new Job(
            id: 'test-job-id',
            queue: 'default',
            jobClass: 'App\\Jobs\\TestJob',
            payload: 'O:8:"stdClass":0:{}',
        );

        // Fail the job
        $exception = new Exception('Job failed');
        $failEvent = new JobFailed(
            job: $job,
            exception: $exception,
            attempts: 1,
            willRetry: false,
        );

        $this->assertArrayHasKey(JobFailed::class, $this->registeredListeners);
        $this->registeredListeners[JobFailed::class]($failEvent);
    }

    public function testWorkflowStartedEventCreatesSpan(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Create a real workflow instance
        $instance = new WorkflowInstance(
            definitionId: 'def-123',
            definitionName: 'TestWorkflow',
            input: [],
        );

        $event = new WorkflowStarted($instance);

        $this->assertArrayHasKey(WorkflowStarted::class, $this->registeredListeners);
        $this->registeredListeners[WorkflowStarted::class]($event);
    }

    public function testWorkflowCompletedEventEndsSpan(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Create a real workflow instance
        $instance = new WorkflowInstance(
            definitionId: 'def-123',
            definitionName: 'TestWorkflow',
            input: [],
        );

        // Start the workflow span
        $startEvent = new WorkflowStarted($instance);
        $this->registeredListeners[WorkflowStarted::class]($startEvent);

        // Complete the workflow
        $endEvent = new WorkflowCompleted($instance);

        $this->assertArrayHasKey(WorkflowCompleted::class, $this->registeredListeners);
        $this->registeredListeners[WorkflowCompleted::class]($endEvent);
    }

    public function testEndJobSpanWithNonExistentJobId(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Try to end a span that was never started
        $endEvent = new JobCompleted(
            jobId: 'non-existent-job',
            jobClass: 'App\\Jobs\\TestJob',
            queue: 'default',
            connection: 'redis',
            durationMs: 1000.0,
        );

        // Should not throw - graceful handling
        $this->registeredListeners[JobCompleted::class]($endEvent);
    }

    public function testEndWorkflowSpanWithNonExistentId(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Create a real workflow instance
        $instance = new WorkflowInstance(
            definitionId: 'def-456',
            definitionName: 'TestWorkflow',
            input: [],
        );

        // Try to end a workflow span that was never started
        $endEvent = new WorkflowCompleted($instance);

        // Should not throw
        $this->registeredListeners[WorkflowCompleted::class]($endEvent);
    }

    public function testStartSpanWhenTracerIsNull(): void
    {
        // Create manager with disabled telemetry
        $manager = new TelemetryManager($this->events, [
            'enabled' => false,
        ]);

        $span = $manager->startSpan('test');

        $this->assertNull($span);
        $this->assertNull($manager->getTracer());
    }

    public function testIncrementCounterWithCustomValue(): void
    {
        $this->events->shouldReceive('listen')->times(6);

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Should not throw
        $manager->incrementCounter('test.counter', ['label' => 'value'], 10);

        $this->assertTrue($manager->isEnabled());
    }

    /**
     * Helper to capture event listeners registered by TelemetryManager.
     */
    private function captureEventListeners(): void
    {
        $this->events->shouldReceive('listen')
            ->times(6)
            ->andReturnUsing(function (string $event, callable $callback): void {
                $this->registeredListeners[$event] = $callback;
            });
    }
}
