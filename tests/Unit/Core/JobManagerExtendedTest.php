<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\Core\JobManager;
use Station\Enums\JobStatus;
use Station\Events\JobRetrying;
use Station\Tests\Fixtures\TestJob;
use stdClass;

/**
 * Extended tests for JobManager covering:
 * - retry() path where job exists in main table but not failed,
 *   and also found in failed_jobs table (lines 166-175)
 * - retry() path where job doesn't exist in main table,
 *   but found in failed_jobs table (lines 176-186)
 * - retryAllFailed() alias method
 * - dispatchSync with object that has no handle method
 * - complete() with null workerId (uses 'unknown')
 * - fail() with willRetry=false when job not found
 * - retry() path where failed_jobs table returns null (already covered,
 *   but verifying edge case with processing status)
 */
class JobManagerExtendedTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&JobRepositoryInterface $repository;

    private MockInterface&QueueFactory $queueFactory;

    private MockInterface&Dispatcher $events;

    private MockInterface&Queue $queue;

    private JobManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(JobRepositoryInterface::class);
        $this->queueFactory = Mockery::mock(QueueFactory::class);
        $this->events = Mockery::mock(Dispatcher::class);
        $this->queue = Mockery::mock(Queue::class);

        $this->queueFactory->shouldReceive('connection')
            ->andReturn($this->queue);

        $this->manager = new JobManager(
            $this->repository,
            $this->queueFactory,
            $this->events,
            ['default' => 'station'],
        );
    }

    // ---- retry: job exists in main table with non-failed status, found in failed_jobs ----

    public function testRetryJobExistsInMainTableWithProcessingStatusAndFoundInFailedTable(): void
    {
        $mainJob = new Job(
            id: 'job-1',
            queue: 'default',
            jobClass: 'TestJob',
            payload: serialize(new stdClass()),
            status: JobStatus::Processing->value,
            attempts: 2,
        );

        $failedJob = new Job(
            id: 'job-1',
            queue: 'default',
            jobClass: 'TestJob',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
            attempts: 3,
        );

        // find() returns job with processing status (not failed)
        $this->repository->shouldReceive('find')
            ->with('job-1')
            ->once()
            ->andReturn($mainJob);

        // findFailed() returns a failed record
        $this->repository->shouldReceive('findFailed')
            ->with('job-1')
            ->once()
            ->andReturn($failedJob);

        // update() should be called since job exists in main table
        $this->repository->shouldReceive('update')
            ->once()
            ->with(Mockery::on(static fn(Job $j): bool => $j->status === JobStatus::Pending->value
                && $j->attempts === 0
                && $j->workerId === null
                && $j->reservedAt === null
                && $j->startedAt === null
                && $j->completedAt === null));

        $this->queue->shouldReceive('push')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(JobRetrying::class));

        $this->repository->shouldReceive('deleteFailed')
            ->once()
            ->with('job-1');

        $result = $this->manager->retry('job-1');

        $this->assertTrue($result);
    }

    // ---- retry: job doesn't exist in main table, found in failed_jobs ----

    public function testRetryJobNotInMainTableButFoundInFailedTable(): void
    {
        $failedJob = new Job(
            id: 'orphan-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
            attempts: 3,
        );

        // find() returns null - not in main table
        $this->repository->shouldReceive('find')
            ->with('orphan-job')
            ->once()
            ->andReturn(null);

        // findFailed() returns a failed record
        $this->repository->shouldReceive('findFailed')
            ->with('orphan-job')
            ->once()
            ->andReturn($failedJob);

        // store() should be called to insert into main table (not update)
        $this->repository->shouldReceive('store')
            ->once()
            ->with(Mockery::on(static fn(Job $j): bool => $j->id === 'orphan-job'
                && $j->status === JobStatus::Pending->value
                && $j->attempts === 0
                && $j->workerId === null));

        $this->queue->shouldReceive('push')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(JobRetrying::class));

        $this->repository->shouldReceive('deleteFailed')
            ->once()
            ->with('orphan-job');

        $result = $this->manager->retry('orphan-job');

        $this->assertTrue($result);
    }

    // ---- retryAllFailed delegates to retryAll ----

    public function testRetryAllFailedDelegatesToRetryAll(): void
    {
        $jobs = collect([
            new Job(id: 'job-1', queue: 'default', jobClass: 'TestJob', payload: serialize(new stdClass()), status: JobStatus::Failed->value),
        ]);

        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with('high')
            ->andReturn($jobs);

        // retry() internal calls
        $this->repository->shouldReceive('find')
            ->once()
            ->andReturn($jobs->first());

        $this->repository->shouldReceive('update')->once();
        $this->queue->shouldReceive('push')->once();
        $this->events->shouldReceive('dispatch')->once();
        $this->repository->shouldReceive('deleteFailed')->once();

        $count = $this->manager->retryAllFailed('high');

        $this->assertSame(1, $count);
    }

    // ---- complete with null workerId ----

    public function testCompleteWithNullWorkerIdUsesUnknown(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            workerId: null,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('test-id')
            ->andReturn($job);

        $this->repository->shouldReceive('complete')
            ->once()
            ->with('test-id', 200, 512);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn($event): bool => $event->worker === 'unknown'));

        $this->manager->complete('test-id', 200, 512);
    }

    // ---- fail with job not found ----

    public function testFailDoesNothingWhenJobNotFound(): void
    {
        $this->repository->shouldReceive('find')
            ->once()
            ->with('missing-job')
            ->andReturn(null);

        $this->repository->shouldNotReceive('fail');
        $this->events->shouldNotReceive('dispatch');

        $this->manager->fail('missing-job', new RuntimeException('Test'));
    }

    // ---- retry where job is in main table with reserved status, no failed entry ----

    public function testRetryReservedJobWithNoFailedEntryReturnsFalse(): void
    {
        $job = new Job(
            id: 'reserved-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Reserved->value,
        );

        $this->repository->shouldReceive('find')
            ->with('reserved-job')
            ->once()
            ->andReturn($job);

        $this->repository->shouldReceive('findFailed')
            ->with('reserved-job')
            ->once()
            ->andReturn(null);

        $result = $this->manager->retry('reserved-job');

        $this->assertFalse($result);
    }

    // ---- retry where job is pending, and not in failed_jobs either ----

    public function testRetryPendingJobWithNoFailedEntryReturnsFalse(): void
    {
        $job = new Job(
            id: 'pending-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
        );

        $this->repository->shouldReceive('find')
            ->with('pending-job')
            ->once()
            ->andReturn($job);

        $this->repository->shouldReceive('findFailed')
            ->with('pending-job')
            ->once()
            ->andReturn(null);

        $result = $this->manager->retry('pending-job');

        $this->assertFalse($result);
    }

    // ---- retry: job exists in main table with reserved status AND in failed_jobs ----

    public function testRetryReservedJobWithFailedEntryResetsAndRetries(): void
    {
        $mainJob = new Job(
            id: 'reserved-retry',
            queue: 'default',
            jobClass: 'TestJob',
            payload: serialize(new stdClass()),
            status: JobStatus::Reserved->value,
            attempts: 1,
        );

        $failedJob = new Job(
            id: 'reserved-retry',
            queue: 'default',
            jobClass: 'TestJob',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
            attempts: 3,
        );

        $this->repository->shouldReceive('find')
            ->with('reserved-retry')
            ->once()
            ->andReturn($mainJob);

        $this->repository->shouldReceive('findFailed')
            ->with('reserved-retry')
            ->once()
            ->andReturn($failedJob);

        // update() should be called since job exists in main table
        $this->repository->shouldReceive('update')
            ->once()
            ->with(Mockery::on(static fn(Job $j): bool => $j->status === JobStatus::Pending->value && $j->attempts === 0));

        $this->queue->shouldReceive('push')->once();
        $this->events->shouldReceive('dispatch')->once()->with(Mockery::type(JobRetrying::class));
        $this->repository->shouldReceive('deleteFailed')->once()->with('reserved-retry');

        $result = $this->manager->retry('reserved-retry');

        $this->assertTrue($result);
    }

    // ---- retryAll with queue filter ----

    public function testRetryAllWithQueueFilterPassesQueueToRepository(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with('emails')
            ->andReturn(collect([]));

        $count = $this->manager->retryAll('emails');

        $this->assertSame(0, $count);
    }

    // ---- dispatch with delayed job that has past availableAt ----

    public function testDispatchWithPastDelayUsesPush(): void
    {
        $job = new TestJob('past delay');

        $pastDelay = CarbonImmutable::now()->subMinutes(5);

        $this->repository->shouldReceive('store')->once();
        $this->queue->shouldReceive('push')->once();
        $this->events->shouldReceive('dispatch')->once();

        $id = $this->manager->dispatch($job, 'test-queue', $pastDelay);

        $this->assertNotEmpty($id);
    }
}
