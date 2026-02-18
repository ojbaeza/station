<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Events\JobCompleted;
use Station\Events\JobStarted;
use Station\Events\WorkflowCompleted;
use Station\Events\WorkflowStarted;
use Station\Telemetry\TelemetryManager;
use Station\Workflows\WorkflowInstance;

/**
 * Tests for TelemetryManager hard cap pruning behavior:
 * When active spans exceed MAX_ACTIVE_SPANS (1000), oldest are evicted.
 *
 * Also covers WorkflowCompleted event span ending and JobCompleted span ending.
 */
class TelemetryManagerHardCapTest extends TestCase
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

    public function testHardCapEvictsOldestSpansWhenOverLimit(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Start 1100 jobs to exceed the hard cap.
        // Pruning runs at multiples of 100 (100, 200, ..., 1100).
        // At the 1100th start, pruneStaleSpans runs BEFORE the new span is added,
        // so activeSpans has 1099 entries > 1000 => hard cap evicts to 1000.
        // Then span-1100 is added => 1001 total.
        for ($i = 1; $i <= 1100; $i++) {
            $event = new JobStarted(
                jobId: "job-{$i}",
                jobClass: 'App\\Jobs\\TestJob',
                queue: 'default',
                connection: 'redis',
            );
            $this->registeredListeners[JobStarted::class]($event);
        }

        // Hard cap evicts oldest during prune, then new span is added after.
        // 1099 spans pruned to 1000, then +1 for the 1100th = 1001
        $this->assertSame(1001, $manager->getActiveSpanCount());

        // Verify it's significantly fewer than without hard cap (would be 1100)
        $this->assertLessThan(1100, $manager->getActiveSpanCount());
    }

    public function testHardCapTriggersOnMultiplePruningCycles(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Create 1200 spans, triggering hard cap at both 1100 and 1200
        for ($i = 1; $i <= 1200; $i++) {
            $event = new JobStarted(
                jobId: "hardcap-job-{$i}",
                jobClass: 'App\\Jobs\\TestJob',
                queue: 'default',
                connection: 'redis',
            );
            $this->registeredListeners[JobStarted::class]($event);
        }

        // At 1200: prune runs with 1100 spans (1001 from last cap + 99 = 1100)
        // Hard cap evicts to 1000, then span-1200 added => 1001
        $this->assertSame(1001, $manager->getActiveSpanCount());
    }

    public function testWorkflowCompletedEventEndsSpanSuccessfully(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Start a workflow
        $instance = new WorkflowInstance(
            definitionId: 'def-wf-complete',
            definitionName: 'CompletableWorkflow',
            input: [],
        );

        $startEvent = new WorkflowStarted($instance);
        $this->registeredListeners[WorkflowStarted::class]($startEvent);

        $this->assertSame(1, $manager->getActiveSpanCount());

        // Complete the workflow
        $completeEvent = new WorkflowCompleted($instance);
        $this->assertArrayHasKey(WorkflowCompleted::class, $this->registeredListeners);
        $this->registeredListeners[WorkflowCompleted::class]($completeEvent);

        // Span should be removed
        $this->assertSame(0, $manager->getActiveSpanCount());
    }

    public function testJobCompletedEventEndsSpanAndRecordsMetrics(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Start a job span
        $startEvent = new JobStarted(
            jobId: 'job-complete-test',
            jobClass: 'App\\Jobs\\CompletableJob',
            queue: 'emails',
            connection: 'redis',
        );
        $this->registeredListeners[JobStarted::class]($startEvent);

        $this->assertSame(1, $manager->getActiveSpanCount());

        // Complete the job using correct constructor parameters
        $completeEvent = new JobCompleted(
            jobId: 'job-complete-test',
            jobClass: 'App\\Jobs\\CompletableJob',
            queue: 'emails',
            connection: 'redis',
            durationMs: 250.0,
        );
        $this->registeredListeners[JobCompleted::class]($completeEvent);

        // Span should be removed
        $this->assertSame(0, $manager->getActiveSpanCount());
    }

    public function testEndJobSpanWithNonExistentIdDoesNotThrow(): void
    {
        $this->captureEventListeners();

        $manager = new TelemetryManager($this->events, [
            'enabled' => true,
        ]);

        // Try to complete a job that was never started
        $completeEvent = new JobCompleted(
            jobId: 'job-never-existed',
            jobClass: 'App\\Jobs\\Test',
            queue: 'default',
            connection: 'redis',
            durationMs: 100.0,
        );
        $this->registeredListeners[JobCompleted::class]($completeEvent);

        $this->assertSame(0, $manager->getActiveSpanCount());
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
