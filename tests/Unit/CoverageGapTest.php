<?php

declare(strict_types=1);

namespace Station\Tests\Unit;

use ArrayObject;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Core\Job;
use Station\Core\JobManager;
use Station\Core\MetricsCollector;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\QueueStats;
use Station\Enums\JobStatus;
use Station\Telemetry\InternalMeter;
use Station\Testing\Fakes\StationFake;
use Station\Workflows\WorkflowDefinition;
use Station\Workflows\WorkflowStep;
use stdClass;

/**
 * Targeted tests to close coverage gaps in:
 * - JobManager: dispatchSync, retryAll, retryAllFailed, retry sub-paths
 * - MetricsCollector: record with sampling, getAverageWaitTime weighted average
 * - InternalMeter: exportPrometheus
 * - StationFake: processAll, clear, retry, cancel, find, getStats, getDispatched
 * - WorkflowStep: uncovered methods
 * - WorkflowDefinition: uncovered methods
 */
class CoverageGapTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    // ---- JobManager::retryAll ----

    public function testJobManagerRetryAllWithMultipleFailedJobs(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        $queue = Mockery::mock(QueueFactory::class);
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        // getFailed returns collection of failed jobs
        $failedJob1 = new Job(
            id: 'failed-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test1',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
        );
        $failedJob2 = new Job(
            id: 'failed-2',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test2',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
        );

        $repository->shouldReceive('getFailed')
            ->with(null)
            ->andReturn(collect([$failedJob1, $failedJob2]));

        // retry() for each job: find returns the job, then update, push, delete failed
        $repository->shouldReceive('find')
            ->with('failed-1')
            ->andReturn($failedJob1);
        $repository->shouldReceive('find')
            ->with('failed-2')
            ->andReturn($failedJob2);
        $repository->shouldReceive('update')->twice();
        $repository->shouldReceive('deleteFailed')->twice();
        $repository->shouldReceive('findFailed')->byDefault()->andReturn(null);

        // pushToQueue uses $queue->connection()->push()
        $queueConnection = Mockery::mock(Queue::class);
        $queueConnection->shouldReceive('push')->twice();
        $queue->shouldReceive('connection')->andReturn($queueConnection);

        $manager = new JobManager($repository, $queue, $events, []);

        $count = $manager->retryAll();
        $this->assertSame(2, $count);
    }

    // ---- JobManager::retry with failedJob only (no main job) ----

    public function testJobManagerRetryWithFailedJobNotInMainTable(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        $queue = Mockery::mock(QueueFactory::class);
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->once();

        // find returns null (not in main table)
        $repository->shouldReceive('find')
            ->with('failed-only')
            ->andReturn(null);

        // findFailed returns the job
        $failedJob = new Job(
            id: 'failed-only',
            queue: 'default',
            jobClass: 'App\\Jobs\\FailedOnlyJob',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
        );
        $repository->shouldReceive('findFailed')
            ->with('failed-only')
            ->andReturn($failedJob);

        // store is called because job doesn't exist in main table
        $repository->shouldReceive('store')->once();
        $repository->shouldReceive('deleteFailed')->once();

        $queueConnection = Mockery::mock(Queue::class);
        $queueConnection->shouldReceive('push')->once();
        $queue->shouldReceive('connection')->andReturn($queueConnection);

        $manager = new JobManager($repository, $queue, $events, []);

        $result = $manager->retry('failed-only');
        $this->assertTrue($result);
    }

    // ---- JobManager::retry with job exists but not failed, and failedJob found ----

    public function testJobManagerRetryWithNonFailedJobAndFailedEntry(): void
    {
        $repository = Mockery::mock(JobRepositoryInterface::class);
        $queue = Mockery::mock(QueueFactory::class);
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->once();

        // find returns a completed job (not failed status)
        $mainJob = new Job(
            id: 'completed-job',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: serialize(new stdClass()),
            status: JobStatus::Completed->value,
        );
        $repository->shouldReceive('find')
            ->with('completed-job')
            ->andReturn($mainJob);

        // findFailed also returns a record
        $failedJob = new Job(
            id: 'completed-job',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
        );
        $repository->shouldReceive('findFailed')
            ->with('completed-job')
            ->andReturn($failedJob);

        // update is called (job exists in main table, reset it)
        $repository->shouldReceive('update')->once();
        $repository->shouldReceive('deleteFailed')->once();

        $queueConnection = Mockery::mock(Queue::class);
        $queueConnection->shouldReceive('push')->once();
        $queue->shouldReceive('connection')->andReturn($queueConnection);

        $manager = new JobManager($repository, $queue, $events, []);

        $result = $manager->retry('completed-job');
        $this->assertTrue($result);
    }

    // ---- MetricsCollector: record with sampling ----

    public function testMetricsCollectorRecordWithSamplingRate(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);
        // With sample_rate=100, record should always be called
        $repository->shouldReceive('record')->once();

        $collector = new MetricsCollector($repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true, 'sample_rate' => 100],
        ]);

        $collector->record('default', 10, 1, 5, 100, 50, 1024, 2);
    }

    public function testMetricsCollectorRecordWhenDisabled(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);
        $repository->shouldNotReceive('record');

        $collector = new MetricsCollector($repository, [
            'enabled' => false,
        ]);

        $collector->record('default', 10, 1, 5, 100, 50, 1024, 2);
    }

    public function testMetricsCollectorRecordWhenMetricsDisabled(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);
        $repository->shouldNotReceive('record');

        $collector = new MetricsCollector($repository, [
            'enabled' => true,
            'metrics' => ['enabled' => false],
        ]);

        $collector->record('default', 10, 1, 5, 100, 50, 1024, 2);
    }

    // ---- MetricsCollector: buffer flush at threshold ----

    public function testMetricsCollectorRecordJobCompletionFlushesAtBufferSize(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);
        // recordBatch is called when buffer reaches 50
        $repository->shouldReceive('recordBatch')->once();

        $collector = new MetricsCollector($repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true, 'sample_rate' => 100],
        ]);

        // Record 50 completions to trigger flush
        for ($i = 0; $i < 50; $i++) {
            $collector->recordJobCompletion('default', 100, 50, 1024);
        }
    }

    public function testMetricsCollectorRecordJobFailureFlushesAtBufferSize(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);
        $repository->shouldReceive('recordBatch')->once();

        $collector = new MetricsCollector($repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true, 'sample_rate' => 100],
        ]);

        // Record 50 failures to trigger flush
        for ($i = 0; $i < 50; $i++) {
            $collector->recordJobFailure('default');
        }
    }

    // ---- MetricsCollector: getAverageWaitTime weighted average ----

    public function testMetricsCollectorGetAverageWaitTimeWithQueue(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);
        $repository->shouldReceive('getAggregated')
            ->with('emails', 60)
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 5,
                avg_processing_time: 200.0,
                avg_wait_time: 50.0,
                failure_rate: 0.05,
            ));

        $collector = new MetricsCollector($repository, ['enabled' => true]);

        $result = $collector->getAverageWaitTime('emails');
        $this->assertSame(50.0, $result);
    }

    public function testMetricsCollectorGetAverageWaitTimeWeightedAcrossQueues(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);

        // getQueueStats returns two queues
        $repository->shouldReceive('getQueueStats')
            ->with([])
            ->andReturn([
                'default' => new QueueStats(
                    size: 10,
                    paused: false,
                    workers: 2,
                    throughput: 10.0,
                ),
                'emails' => new QueueStats(
                    size: 5,
                    paused: false,
                    workers: 1,
                    throughput: 5.0,
                ),
            ]);

        // getAggregated for each queue
        $repository->shouldReceive('getAggregated')
            ->with('default', 60)
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 2,
                avg_processing_time: 200.0,
                avg_wait_time: 30.0,
                failure_rate: 0.02,
            ));

        $repository->shouldReceive('getAggregated')
            ->with('emails', 60)
            ->andReturn(new MetricsAggregation(
                jobs_processed: 50,
                jobs_failed: 1,
                avg_processing_time: 150.0,
                avg_wait_time: 60.0,
                failure_rate: 0.02,
            ));

        $collector = new MetricsCollector($repository, ['enabled' => true]);

        $result = $collector->getAverageWaitTime(null);
        // Weighted: (30.0*100 + 60.0*50) / (100+50) = (3000+3000)/150 = 40.0
        $this->assertSame(40.0, $result);
    }

    public function testMetricsCollectorGetAverageWaitTimeWithNoProcessedJobs(): void
    {
        $repository = Mockery::mock(MetricsRepositoryInterface::class);

        $repository->shouldReceive('getQueueStats')
            ->with([])
            ->andReturn([
                'default' => new QueueStats(size: 0, paused: false, workers: 0, throughput: 0.0),
            ]);

        $repository->shouldReceive('getAggregated')
            ->with('default', 60)
            ->andReturn(new MetricsAggregation(
                jobs_processed: 0,
                jobs_failed: 0,
                avg_processing_time: 0.0,
                avg_wait_time: 0.0,
                failure_rate: 0.0,
            ));

        $collector = new MetricsCollector($repository, ['enabled' => true]);

        $result = $collector->getAverageWaitTime(null);
        $this->assertSame(0.0, $result);
    }

    // ---- InternalMeter: exportPrometheus ----

    public function testInternalMeterExportPrometheusFormatsAllMetricTypes(): void
    {
        $meter = new InternalMeter(['enabled' => true]);

        // Record some counters, gauges, and histograms
        $meter->incrementCounter('station.jobs.processed', ['queue' => 'default'], 10);
        $meter->incrementCounter('station.jobs.failed', ['queue' => 'default'], 2);
        $meter->recordValue('station.memory.used', 1024.5, ['queue' => 'default']);
        $meter->recordHistogram('station.processing.time', 150.0, ['queue' => 'default']);
        $meter->recordHistogram('station.processing.time', 250.0, ['queue' => 'default']);

        $output = $meter->exportPrometheus();

        $this->assertStringContainsString('station.jobs.processed', $output);
        $this->assertStringContainsString('station.jobs.failed', $output);
        $this->assertStringContainsString('station.memory.used', $output);
        $this->assertStringContainsString('station.processing.time_count', $output);
        $this->assertStringContainsString('station.processing.time_sum', $output);
        $this->assertStringContainsString('queue="default"', $output);
    }

    public function testInternalMeterExportPrometheusWithNoLabels(): void
    {
        $meter = new InternalMeter(['enabled' => true]);

        $meter->incrementCounter('total_jobs', [], 5);
        $meter->recordValue('current_load', 0.75);

        $output = $meter->exportPrometheus();

        $this->assertStringContainsString('total_jobs 5', $output);
        $this->assertStringContainsString('current_load 0.75', $output);
    }

    // ---- StationFake ----

    public function testStationFakeProcessAllCallsHandleOnJobs(): void
    {
        $fake = new StationFake();

        $job = new class {
            public bool $handled = false;

            public function handle(): void
            {
                $this->handled = true;
            }
        };

        $fake->dispatch($job);
        $fake->processAll();

        $this->assertTrue($job->handled);
    }

    public function testStationFakeClearRemovesAllRecords(): void
    {
        $fake = new StationFake();

        $fake->dispatch(new stdClass());
        $fake->dispatch(new stdClass());

        $this->assertCount(2, $fake->getDispatched());

        $fake->clear();

        $this->assertCount(0, $fake->getDispatched());
    }

    public function testStationFakeRetryDoesNotThrow(): void
    {
        $fake = new StationFake();
        $fake->retry('any-id');
        $this->addToAssertionCount(1);
    }

    public function testStationFakeCancelDoesNotThrow(): void
    {
        $fake = new StationFake();
        $fake->cancel('any-id');
        $this->addToAssertionCount(1);
    }

    public function testStationFakeFindReturnsNull(): void
    {
        $fake = new StationFake();
        $this->assertNull($fake->find('any-id'));
    }

    public function testStationFakeGetStatsReturnsExpectedStructure(): void
    {
        $fake = new StationFake();
        $fake->dispatch(new stdClass());
        $fake->recordBatch([new stdClass()]);
        $fake->recordChain([new stdClass()]);

        $stats = $fake->getStats();

        $this->assertSame(1, $stats['dispatched']);
        $this->assertSame(1, $stats['batches']);
        $this->assertSame(1, $stats['chains']);
    }

    public function testStationFakeGetDispatchedWithTypeFilter(): void
    {
        $fake = new StationFake();

        $fake->dispatch(new stdClass());
        $fake->dispatch(new ArrayObject());

        $all = $fake->getDispatched();
        $this->assertCount(2, $all);

        $onlyStd = $fake->getDispatched(stdClass::class);
        $this->assertCount(1, $onlyStd);
    }

    // ---- WorkflowStep: uncovered methods ----

    public function testWorkflowStepConditionWithCallable(): void
    {
        $step = new WorkflowStep(
            name: 'conditional-step',
            jobClass: 'App\\Jobs\\TestStep',
            condition: static fn(array $context) => $context['enabled'] ?? false,
        );

        // Condition should evaluate to false with empty context
        $this->assertFalse($step->shouldRun([]));

        // Condition should evaluate to true with enabled context
        $this->assertTrue($step->shouldRun(['enabled' => true]));
    }

    public function testWorkflowStepWithMaxRetries(): void
    {
        $step = new WorkflowStep(
            name: 'retryable-step',
            jobClass: 'App\\Jobs\\RetryableStep',
            retries: 5,
        );

        $this->assertSame(5, $step->getRetries());
    }

    public function testWorkflowStepWithTimeout(): void
    {
        $step = new WorkflowStep(
            name: 'timeout-step',
            jobClass: 'App\\Jobs\\TimeoutStep',
            timeout: 120,
        );

        $this->assertSame(120, $step->getTimeout());
    }

    // ---- WorkflowDefinition: uncovered methods ----

    public function testWorkflowDefinitionGetStepReturnsNullForNonExistentStep(): void
    {
        $definition = new WorkflowDefinition('test-def', 'Test Workflow');
        $definition->addStep('step-1', 'App\\Jobs\\Step1');

        $this->assertNull($definition->getStep('nonexistent'));
    }

    public function testWorkflowDefinitionGetStepReturnsStepWhenExists(): void
    {
        $definition = new WorkflowDefinition('test-def', 'Test Workflow');
        $definition->addStep('step-1', 'App\\Jobs\\Step1');

        $step = $definition->getStep('step-1');
        $this->assertNotNull($step);
        $this->assertSame('step-1', $step->getName());
    }
}
