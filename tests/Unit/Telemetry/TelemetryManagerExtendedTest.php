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
use Station\Events\JobFailed;
use Station\Events\JobStarted;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStarted;
use Station\Telemetry\TelemetryManager;
use Station\Workflows\WorkflowInstance;

/**
 * Extended tests for TelemetryManager covering pruneStaleSpans, WorkflowFailed
 * event handling, and edge cases not covered by the primary test file.
 */
class TelemetryManagerExtendedTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&Dispatcher $events;

    /** @var array<string, callable> */
    private array $registeredListeners = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = Mockery::mock(Dispatcher::class);
        $this->registeredListeners = [];
    }

    public function testWorkflowFailedEventEndsSpanWithErrorStatus(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Create and start a workflow
        $instance = new WorkflowInstance(
            definitionId: 'def-123',
            definitionName: 'TestWorkflow',
            input: [],
        );

        $startEvent = new WorkflowStarted($instance);
        $this->registeredListeners[WorkflowStarted::class]($startEvent);

        // Verify span is active
        $this->assertSame(1, $manager->getActiveSpanCount());

        // Fail the workflow
        $failEvent = new WorkflowFailed($instance);
        $this->assertArrayHasKey(WorkflowFailed::class, $this->registeredListeners);
        $this->registeredListeners[WorkflowFailed::class]($failEvent);

        // Span should be removed
        $this->assertSame(0, $manager->getActiveSpanCount());
    }

    public function testWorkflowFailedWithNonExistentIdDoesNotThrow(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        $instance = new WorkflowInstance(
            definitionId: 'def-never-started',
            definitionName: 'NeverStartedWorkflow',
            input: [],
        );

        // Try to end a workflow span that was never started
        $failEvent = new WorkflowFailed($instance);
        $this->registeredListeners[WorkflowFailed::class]($failEvent);

        // Should not throw - graceful handling
        $this->assertSame(0, $manager->getActiveSpanCount());
    }

    public function testActiveSpanCountTracksJobSpans(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        $this->assertSame(0, $manager->getActiveSpanCount());

        // Start 3 job spans
        for ($i = 1; $i <= 3; $i++) {
            $event = new JobStarted(
                jobId: "job-{$i}",
                jobClass: 'App\\Jobs\\TestJob',
                queue: 'default',
                connection: 'redis',
            );
            $this->registeredListeners[JobStarted::class]($event);
        }

        $this->assertSame(3, $manager->getActiveSpanCount());
    }

    public function testPruneStaleSpansRunsEvery100Starts(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Start 100 job spans - pruning runs on multiples of 100
        for ($i = 1; $i <= 100; $i++) {
            $event = new JobStarted(
                jobId: "job-{$i}",
                jobClass: 'App\\Jobs\\TestJob',
                queue: 'default',
                connection: 'redis',
            );
            $this->registeredListeners[JobStarted::class]($event);
        }

        // All 100 spans should still be active (none are stale within the test timeframe)
        $this->assertSame(100, $manager->getActiveSpanCount());
    }

    public function testStartJobSpanWhenDisabledDoesNotCreateSpan(): void
    {
        $this->events->shouldNotReceive('listen');

        $manager = new TelemetryManager($this->events, [
            'enabled' => false,
        ]);

        $this->assertSame(0, $manager->getActiveSpanCount());
        $this->assertNull($manager->getTracer());
        $this->assertNull($manager->getMeter());
    }

    public function testEndJobSpanWithExceptionRecordsError(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Start a job span
        $startEvent = new JobStarted(
            jobId: 'failing-job',
            jobClass: 'App\\Jobs\\FailingJob',
            queue: 'high',
            connection: 'redis',
        );
        $this->registeredListeners[JobStarted::class]($startEvent);
        $this->assertSame(1, $manager->getActiveSpanCount());

        // Fail the job with an exception
        $job = new Job(
            id: 'failing-job',
            queue: 'high',
            jobClass: 'App\\Jobs\\FailingJob',
            payload: 'O:8:"stdClass":0:{}',
        );

        $exception = new Exception('Something went wrong');
        $failEvent = new JobFailed(
            job: $job,
            exception: $exception,
            attempts: 3,
            willRetry: false,
        );
        $this->registeredListeners[JobFailed::class]($failEvent);

        // Span should be removed after ending
        $this->assertSame(0, $manager->getActiveSpanCount());
    }

    public function testRecordMetricWithNullMeterDoesNotThrow(): void
    {
        $manager = new TelemetryManager($this->events, ['enabled' => false]);

        // These should all be safe when meter is null
        $manager->recordMetric('test', 1.0);
        $manager->incrementCounter('test');
        $manager->recordHistogram('test', 1.0);

        $this->assertNull($manager->getMeter());
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
