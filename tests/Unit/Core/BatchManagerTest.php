<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Contracts\BatchRepositoryInterface;
use Station\Core\Batch;
use Station\Core\BatchManager;
use Station\Enums\BatchStatus;
use Station\Events\BatchCancelled;
use Station\Events\BatchCompleted;
use Station\Events\BatchCreated;
use Station\Events\BatchFailed;
use Station\Events\BatchProgress;
use stdClass;

/**
 * Tests for BatchManager performance optimizations:
 *
 * - recordJobCompletion() only calls find() when batch is finishing (pendingJobs <= 0)
 * - recordJobFailure() still calls find() (needs hasExceededAllowedFailures check)
 * - Correct events dispatched at completion/failure boundaries
 */
class BatchManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private BatchRepositoryInterface&MockInterface $repository;

    private BatchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(BatchRepositoryInterface::class);
        $events = $this->app['events'];

        $this->manager = new BatchManager(
            $this->repository,
            $events,
            ['pruning' => ['completed_after' => 24, 'cancelled_after' => 72, 'failed_after' => 168]],
        );
    }

    // ──────────────────────────────────────────────────────────────
    // recordJobCompletion — only find() when finishing
    // ──────────────────────────────────────────────────────────────

    public function testRecordJobCompletionSkipsFindWhenPendingRemains(): void
    {
        // incrementProcessed returns 5 remaining → should NOT call find()
        $this->repository
            ->shouldReceive('incrementProcessed')
            ->once()
            ->with('batch-1')
            ->andReturn(5);

        // find() should NOT be called — this is the key optimization
        $this->repository->shouldNotReceive('find');

        $this->manager->recordJobCompletion('batch-1');
    }

    public function testRecordJobCompletionCallsFindWhenPendingReachesZero(): void
    {
        $batch = $this->makeBatch('batch-2', 3, 0, 3, 0);
        $finishedBatch = $this->makeBatch('batch-2', 3, 0, 3, 0, BatchStatus::Completed->value);

        $this->repository
            ->shouldReceive('incrementProcessed')
            ->once()
            ->with('batch-2')
            ->andReturn(0);

        // find() IS called because pending reached 0
        // First call: before finishBatch; second call: inside finishBatch after markAsFinished
        $this->repository
            ->shouldReceive('find')
            ->with('batch-2')
            ->andReturn($batch, $finishedBatch);

        $this->repository
            ->shouldReceive('markAsFinished')
            ->once()
            ->with('batch-2', BatchStatus::Completed->value)
            ->andReturn(true);

        $this->manager->recordJobCompletion('batch-2');
    }

    public function testRecordJobCompletionHandlesNullBatchGracefully(): void
    {
        $this->repository
            ->shouldReceive('incrementProcessed')
            ->once()
            ->with('batch-gone')
            ->andReturn(0);

        $this->repository
            ->shouldReceive('find')
            ->once()
            ->with('batch-gone')
            ->andReturnNull();

        $this->manager->recordJobCompletion('batch-gone');
    }

    public function testRecordJobCompletionDoesNotCallFindForHighPending(): void
    {
        // Even with 1 pending left, should not call find()
        $this->repository
            ->shouldReceive('incrementProcessed')
            ->once()
            ->with('batch-x')
            ->andReturn(1);

        $this->repository->shouldNotReceive('find');

        $this->manager->recordJobCompletion('batch-x');
    }

    // ──────────────────────────────────────────────────────────────
    // recordJobCompletion — event dispatch
    // ──────────────────────────────────────────────────────────────

    public function testRecordJobCompletionDispatchesBatchProgressAndCompletedEvents(): void
    {
        Event::fake([BatchProgress::class, BatchCompleted::class]);

        // Recreate manager with the faked event dispatcher
        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            ['pruning' => ['completed_after' => 24, 'cancelled_after' => 72, 'failed_after' => 168]],
        );

        $batch = $this->makeBatch('batch-evt-1', 3, 0, 3, 0);
        $finishedBatch = $this->makeBatch('batch-evt-1', 3, 0, 3, 0, BatchStatus::Completed->value);

        $this->repository
            ->shouldReceive('incrementProcessed')
            ->once()
            ->with('batch-evt-1')
            ->andReturn(0);

        $this->repository
            ->shouldReceive('find')
            ->with('batch-evt-1')
            ->andReturn($batch, $finishedBatch);

        $this->repository
            ->shouldReceive('markAsFinished')
            ->once()
            ->with('batch-evt-1', BatchStatus::Completed->value)
            ->andReturn(true);

        $manager->recordJobCompletion('batch-evt-1');

        Event::assertDispatched(BatchProgress::class, static fn(BatchProgress $e): bool => $e->batch->id === 'batch-evt-1'
                && $e->processed === 3
                && $e->failed === 0);

        Event::assertDispatched(BatchCompleted::class, static fn(BatchCompleted $e): bool => $e->batch->id === 'batch-evt-1'
                && $e->jobsProcessed === 3);
    }

    public function testRecordJobCompletionDoesNotDispatchEventsWhenPendingRemains(): void
    {
        Event::fake([BatchProgress::class, BatchCompleted::class]);

        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            ['pruning' => ['completed_after' => 24, 'cancelled_after' => 72, 'failed_after' => 168]],
        );

        $this->repository
            ->shouldReceive('incrementProcessed')
            ->once()
            ->with('batch-skip')
            ->andReturn(3);

        $this->repository->shouldNotReceive('find');

        $manager->recordJobCompletion('batch-skip');

        Event::assertNotDispatched(BatchProgress::class);
        Event::assertNotDispatched(BatchCompleted::class);
    }

    // ──────────────────────────────────────────────────────────────
    // recordJobFailure — always does find() for failure threshold
    // ──────────────────────────────────────────────────────────────

    public function testRecordJobFailureAlwaysCallsFind(): void
    {
        $batch = $this->makeBatch('batch-fail', 10, 5, 5, 1, BatchStatus::Processing->value, 3);

        $this->repository
            ->shouldReceive('incrementFailed')
            ->once()
            ->with('batch-fail', 'job-1')
            ->andReturn(5);

        // find() is always called for failure path (needs threshold check)
        $this->repository
            ->shouldReceive('find')
            ->once()
            ->with('batch-fail')
            ->andReturn($batch);

        $this->manager->recordJobFailure('batch-fail', 'job-1');

        // Verify: failedJobs (1) <= allowedFailures (3), pending > 0 → no finish
    }

    public function testRecordJobFailureExceedsAllowedFailuresTriggersFailBatch(): void
    {
        $batch = $this->makeBatch('batch-exceed', 10, 3, 7, 6, BatchStatus::Processing->value, 5);
        $failedBatch = $this->makeBatch('batch-exceed', 10, 3, 7, 6, BatchStatus::Failed->value, 5);

        $this->repository
            ->shouldReceive('incrementFailed')
            ->once()
            ->with('batch-exceed', 'job-bad')
            ->andReturn(3);

        $this->repository
            ->shouldReceive('find')
            ->with('batch-exceed')
            ->andReturn($batch, $failedBatch);

        // failBatch: mark as failed + cancel via Bus
        $this->repository
            ->shouldReceive('markAsFinished')
            ->once()
            ->with('batch-exceed', BatchStatus::Failed->value)
            ->andReturn(true);

        Bus::shouldReceive('findBatch')
            ->with('batch-exceed')
            ->andReturnNull();

        $this->manager->recordJobFailure('batch-exceed', 'job-bad');
    }

    public function testRecordJobFailureExceedsAllowedFailuresDispatchesEvents(): void
    {
        Event::fake([BatchProgress::class, BatchFailed::class]);

        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            ['pruning' => ['completed_after' => 24, 'cancelled_after' => 72, 'failed_after' => 168]],
        );

        $batch = $this->makeBatch('batch-evt-fail', 10, 3, 7, 6, BatchStatus::Processing->value, 5);
        $failedBatch = $this->makeBatch('batch-evt-fail', 10, 3, 7, 6, BatchStatus::Failed->value, 5);

        $this->repository
            ->shouldReceive('incrementFailed')
            ->once()
            ->with('batch-evt-fail', 'job-x')
            ->andReturn(3);

        $this->repository
            ->shouldReceive('find')
            ->with('batch-evt-fail')
            ->andReturn($batch, $failedBatch);

        $this->repository
            ->shouldReceive('markAsFinished')
            ->once()
            ->with('batch-evt-fail', BatchStatus::Failed->value)
            ->andReturn(true);

        Bus::shouldReceive('findBatch')
            ->with('batch-evt-fail')
            ->andReturnNull();

        $manager->recordJobFailure('batch-evt-fail', 'job-x');

        Event::assertDispatched(BatchProgress::class, static fn(BatchProgress $e): bool => $e->batch->id === 'batch-evt-fail');

        Event::assertDispatched(BatchFailed::class, static fn(BatchFailed $e): bool => $e->batch->id === 'batch-evt-fail');
    }

    public function testRecordJobFailureFinishesBatchWhenPendingReachesZero(): void
    {
        // failedJobs (1) <= allowedFailures (5) → finishBatch marks as Completed
        $batch = $this->makeBatch('batch-done', 3, 0, 3, 1, BatchStatus::Processing->value, 5);
        $completedBatch = $this->makeBatch('batch-done', 3, 0, 3, 1, BatchStatus::Completed->value, 5);

        $this->repository
            ->shouldReceive('incrementFailed')
            ->once()
            ->with('batch-done', 'job-last')
            ->andReturn(0);

        $this->repository
            ->shouldReceive('find')
            ->with('batch-done')
            ->andReturn($batch, $completedBatch);

        $this->repository
            ->shouldReceive('markAsFinished')
            ->once()
            ->with('batch-done', BatchStatus::Completed->value)
            ->andReturn(true);

        $this->manager->recordJobFailure('batch-done', 'job-last');
    }

    public function testRecordJobFailureWithPendingZeroAndNoFailuresDispatchesCompleted(): void
    {
        Event::fake([BatchProgress::class, BatchCompleted::class]);

        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            ['pruning' => ['completed_after' => 24, 'cancelled_after' => 72, 'failed_after' => 168]],
        );

        $batch = $this->makeBatch('batch-clean', 3, 0, 3, 0, BatchStatus::Processing->value, 5);
        $completedBatch = $this->makeBatch('batch-clean', 3, 0, 3, 0, BatchStatus::Completed->value, 5);

        $this->repository
            ->shouldReceive('incrementFailed')
            ->once()
            ->with('batch-clean', 'job-y')
            ->andReturn(0);

        $this->repository
            ->shouldReceive('find')
            ->with('batch-clean')
            ->andReturn($batch, $completedBatch);

        // failedJobs == 0 → finishBatch marks as Completed
        $this->repository
            ->shouldReceive('markAsFinished')
            ->once()
            ->with('batch-clean', BatchStatus::Completed->value)
            ->andReturn(true);

        $manager->recordJobFailure('batch-clean', 'job-y');

        Event::assertDispatched(BatchProgress::class);
        Event::assertDispatched(BatchCompleted::class, static fn(BatchCompleted $e): bool => $e->batch->id === 'batch-clean');
    }

    public function testRecordJobFailureHandlesNullBatchGracefully(): void
    {
        $this->repository
            ->shouldReceive('incrementFailed')
            ->once()
            ->with('batch-gone', 'job-x')
            ->andReturn(0);

        $this->repository
            ->shouldReceive('find')
            ->once()
            ->with('batch-gone')
            ->andReturnNull();

        $this->manager->recordJobFailure('batch-gone', 'job-x');
    }

    // ──────────────────────────────────────────────────────────────
    // find / getActive / getRecent / getByStatus
    // ──────────────────────────────────────────────────────────────

    public function testFindReturnsBatch(): void
    {
        $batch = $this->makeBatch('batch-1', 10, 5, 5, 0);

        $this->repository->shouldReceive('find')
            ->with('batch-1')
            ->andReturn($batch);

        $result = $this->manager->find('batch-1');
        $this->assertSame($batch, $result);
    }

    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->repository->shouldReceive('find')
            ->with('nonexistent')
            ->andReturn(null);

        $this->assertNull($this->manager->find('nonexistent'));
    }

    public function testGetActiveReturnsBatches(): void
    {
        $batch = $this->makeBatch('b-1', 10, 3, 7, 0);
        $collection = new Collection([$batch]);

        $this->repository->shouldReceive('getActive')
            ->andReturn($collection);

        $result = $this->manager->getActive();
        $this->assertCount(1, $result);
        $this->assertSame($batch, $result->first());
    }

    public function testGetRecentReturnsBatches(): void
    {
        $this->repository->shouldReceive('getRecent')
            ->with(5)
            ->andReturn(new Collection([]));

        $result = $this->manager->getRecent(5);
        $this->assertEmpty($result);
    }

    public function testGetRecentDefaultsTo10(): void
    {
        $this->repository->shouldReceive('getRecent')
            ->with(10)
            ->once()
            ->andReturn(new Collection([]));

        $this->manager->getRecent();
        // Mockery verifies the call was made with 10
    }

    public function testGetByStatusReturnsBatches(): void
    {
        $this->repository->shouldReceive('getByStatus')
            ->with('completed', 50)
            ->andReturn(new Collection([]));

        $result = $this->manager->getByStatus('completed', 50);
        $this->assertEmpty($result);
    }

    // ──────────────────────────────────────────────────────────────
    // cancel
    // ──────────────────────────────────────────────────────────────

    public function testCancelReturnsFalseWhenBatchNotFound(): void
    {
        $this->repository->shouldReceive('find')
            ->with('batch-1')
            ->andReturn(null);

        $this->assertFalse($this->manager->cancel('batch-1'));
    }

    public function testCancelReturnsFalseWhenBatchIsFinished(): void
    {
        $batch = $this->makeBatch('batch-1', 10, 0, 10, 0, BatchStatus::Completed->value);

        $this->repository->shouldReceive('find')
            ->with('batch-1')
            ->andReturn($batch);

        $this->assertFalse($this->manager->cancel('batch-1'));
    }

    public function testCancelSuccessfullyForProcessingBatch(): void
    {
        $processingBatch = $this->makeBatch('batch-1', 10, 5, 5, 0, BatchStatus::Processing->value);
        $cancelledBatch = $this->makeBatch('batch-1', 10, 5, 5, 0, BatchStatus::Cancelled->value);

        // First call: initial check; second call: after cancel for event
        $this->repository->shouldReceive('find')
            ->with('batch-1')
            ->andReturn($processingBatch, $cancelledBatch);

        $this->repository->shouldReceive('cancel')
            ->with('batch-1')
            ->once();

        Bus::shouldReceive('findBatch')
            ->with('batch-1')
            ->andReturnNull(); // No Laravel batch

        $result = $this->manager->cancel('batch-1');

        $this->assertTrue($result);
    }

    public function testCancelDispatchesBatchCancelledEvent(): void
    {
        Event::fake([BatchCancelled::class]);

        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            ['pruning' => ['completed_after' => 24, 'cancelled_after' => 72, 'failed_after' => 168]],
        );

        $processingBatch = $this->makeBatch('batch-cancel-evt', 10, 5, 5, 0, BatchStatus::Processing->value);
        $cancelledBatch = $this->makeBatch('batch-cancel-evt', 10, 5, 5, 0, BatchStatus::Cancelled->value);

        $this->repository->shouldReceive('find')
            ->with('batch-cancel-evt')
            ->andReturn($processingBatch, $cancelledBatch);

        $this->repository->shouldReceive('cancel')
            ->with('batch-cancel-evt')
            ->once();

        Bus::shouldReceive('findBatch')
            ->with('batch-cancel-evt')
            ->andReturnNull();

        $manager->cancel('batch-cancel-evt');

        Event::assertDispatched(BatchCancelled::class, static fn(BatchCancelled $e): bool => $e->batch->id === 'batch-cancel-evt');
    }

    // ──────────────────────────────────────────────────────────────
    // prune
    // ──────────────────────────────────────────────────────────────

    public function testPruneUsesConfiguredThresholds(): void
    {
        $this->repository->shouldReceive('prune')
            ->with(24, 72, 168)
            ->andReturn(15);

        $result = $this->manager->prune();
        $this->assertSame(15, $result);
    }

    public function testPruneUsesDefaultsWhenConfigMissing(): void
    {
        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            [], // No pruning config
        );

        $this->repository->shouldReceive('prune')
            ->with(24, 72, 168) // defaults
            ->andReturn(0);

        $result = $manager->prune();
        $this->assertSame(0, $result);
    }

    // ──────────────────────────────────────────────────────────────
    // create
    // ──────────────────────────────────────────────────────────────

    public function testCreateDispatchesLaravelBatchAndStoresStationBatch(): void
    {
        $job1 = new stdClass();
        $job2 = new stdClass();

        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->id = 'laravel-batch-id';

        $pendingBatch = Mockery::mock(PendingBatch::class);
        $pendingBatch->shouldReceive('onQueue')->with('emails')->andReturnSelf();
        $pendingBatch->shouldReceive('allowFailures')->andReturnSelf();
        $pendingBatch->shouldReceive('name')->with('Email Batch')->andReturnSelf();
        $pendingBatch->shouldNotReceive('onConnection');
        $pendingBatch->shouldReceive('dispatch')->andReturn($laravelBatch);

        Bus::shouldReceive('batch')
            ->once()
            ->with([$job1, $job2])
            ->andReturn($pendingBatch);

        $storedBatch = $this->makeBatch('laravel-batch-id', 2, 2, 0, 0, BatchStatus::Processing->value);

        $this->repository->shouldReceive('store')
            ->once()
            ->with(Mockery::on(
                static fn(Batch $b): bool => $b->id === 'laravel-batch-id'
                && $b->totalJobs === 2
                && $b->queue === 'emails'
                && $b->allowedFailures === 1,
            ));

        $this->repository->shouldReceive('markAsStarted')
            ->once()
            ->with('laravel-batch-id');

        $this->repository->shouldReceive('find')
            ->once()
            ->with('laravel-batch-id')
            ->andReturn($storedBatch);

        Event::fake([BatchCreated::class]);

        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            ['pruning' => ['completed_after' => 24, 'cancelled_after' => 72, 'failed_after' => 168]],
        );

        $result = $manager->create(
            jobs: [$job1, $job2],
            name: 'Email Batch',
            queue: 'emails',
            allowedFailures: 1,
            options: ['priority' => 'high'],
        );

        $this->assertSame('laravel-batch-id', $result->id);

        Event::assertDispatched(
            BatchCreated::class,
            static fn(BatchCreated $e): bool => $e->batch->id === 'laravel-batch-id'
            && $e->totalJobs === 2,
        );
    }

    public function testCreateWithConnectionSetsConnectionOnPendingBatch(): void
    {
        $job = new stdClass();

        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->id = 'batch-conn';

        $pendingBatch = Mockery::mock(PendingBatch::class);
        $pendingBatch->shouldReceive('onQueue')->with('default')->andReturnSelf();
        $pendingBatch->shouldReceive('allowFailures')->andReturnSelf();
        $pendingBatch->shouldReceive('onConnection')->once()->with('redis')->andReturnSelf();
        $pendingBatch->shouldReceive('dispatch')->andReturn($laravelBatch);

        Bus::shouldReceive('batch')
            ->once()
            ->with([$job])
            ->andReturn($pendingBatch);

        $storedBatch = $this->makeBatch('batch-conn', 1, 1, 0, 0, BatchStatus::Processing->value);

        $this->repository->shouldReceive('store')->once();
        $this->repository->shouldReceive('markAsStarted')->once();
        $this->repository->shouldReceive('find')->andReturn($storedBatch);

        Event::fake([BatchCreated::class]);

        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            [],
        );

        $result = $manager->create(
            jobs: [$job],
            connection: 'redis',
        );

        $this->assertSame('batch-conn', $result->id);
    }

    public function testCreateReturnsOriginalBatchWhenFindReturnsNull(): void
    {
        $job = new stdClass();

        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->id = 'batch-null';

        $pendingBatch = Mockery::mock(PendingBatch::class);
        $pendingBatch->shouldReceive('onQueue')->andReturnSelf();
        $pendingBatch->shouldReceive('allowFailures')->andReturnSelf();
        $pendingBatch->shouldReceive('dispatch')->andReturn($laravelBatch);

        Bus::shouldReceive('batch')->with([$job])->andReturn($pendingBatch);

        $this->repository->shouldReceive('store')->once();
        $this->repository->shouldReceive('markAsStarted')->once();
        $this->repository->shouldReceive('find')->andReturn(null);

        Event::fake([BatchCreated::class]);

        $manager = new BatchManager(
            $this->repository,
            $this->app['events'],
            [],
        );

        $result = $manager->create(jobs: [$job]);

        // Should return the originally created batch, not null
        $this->assertSame('batch-null', $result->id);
    }

    // ──────────────────────────────────────────────────────────────
    // retryFailed
    // ──────────────────────────────────────────────────────────────

    public function testRetryFailedReturnsZeroWhenLaravelBatchNotFound(): void
    {
        Bus::shouldReceive('findBatch')
            ->with('batch-gone')
            ->andReturn(null);

        $result = $this->manager->retryFailed('batch-gone');

        $this->assertSame(0, $result);
    }

    public function testRetryFailedRetriesAndResetsStatus(): void
    {
        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->failedJobIds = ['job-a', 'job-b', 'job-c'];
        $laravelBatch->shouldReceive('retry')->once();

        Bus::shouldReceive('findBatch')
            ->with('batch-retry')
            ->andReturn($laravelBatch);

        $this->repository->shouldReceive('markAsProcessing')
            ->once()
            ->with('batch-retry');

        $result = $this->manager->retryFailed('batch-retry');

        $this->assertSame(3, $result);
    }

    public function testRetryFailedReturnsZeroWhenNoFailedJobs(): void
    {
        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->failedJobIds = [];
        $laravelBatch->shouldReceive('retry')->once();

        Bus::shouldReceive('findBatch')
            ->with('batch-no-fails')
            ->andReturn($laravelBatch);

        $this->repository->shouldReceive('markAsProcessing')->once();

        $result = $this->manager->retryFailed('batch-no-fails');

        $this->assertSame(0, $result);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeBatch(
        string $id,
        int $totalJobs,
        int $pendingJobs,
        int $processedJobs,
        int $failedJobs,
        string $status = BatchStatus::Processing->value,
        int $allowedFailures = 0,
    ): Batch {
        return new Batch(
            id: $id,
            name: 'Test',
            totalJobs: $totalJobs,
            pendingJobs: $pendingJobs,
            processedJobs: $processedJobs,
            failedJobs: $failedJobs,
            status: $status,
            allowedFailures: $allowedFailures,
        );
    }
}
