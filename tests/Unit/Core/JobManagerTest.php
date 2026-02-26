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
use Station\Core\PendingDispatch;
use Station\DTOs\JobStats;
use Station\Enums\JobStatus;
use Station\Events\JobDispatched;
use Station\Events\JobFailed;
use Station\Events\JobProcessed;
use Station\Events\JobRetrying;
use Station\Tests\Fixtures\TestJob;
use stdClass;

class JobManagerTest extends TestCase
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

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testJobCreatesPendingDispatch(): void
    {
        $job = new class {
            public function handle(): void {}
        };

        $result = $this->manager->job($job);

        $this->assertInstanceOf(PendingDispatch::class, $result);
    }

    public function testDispatchStoresAndPushesJob(): void
    {
        $job = new TestJob('test message');

        $this->repository->shouldReceive('store')
            ->once()
            ->with(Mockery::type(Job::class));

        $this->queue->shouldReceive('push')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(JobDispatched::class));

        $id = $this->manager->dispatch($job, 'test-queue');

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithDelayUsesLater(): void
    {
        $job = new TestJob('delayed message');

        $delay = CarbonImmutable::now()->addMinutes(5);

        $this->repository->shouldReceive('store')
            ->once();

        $this->queue->shouldReceive('later')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once();

        $id = $this->manager->dispatch($job, 'test-queue', $delay);

        $this->assertNotEmpty($id);
    }

    public function testFindDelegatesToRepository(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('test-id')
            ->andReturn($job);

        $result = $this->manager->find('test-id');

        $this->assertSame($job, $result);
    }

    public function testFindReturnsNullForNonexistentJob(): void
    {
        $this->repository->shouldReceive('find')
            ->once()
            ->with('nonexistent')
            ->andReturn(null);

        $result = $this->manager->find('nonexistent');

        $this->assertNull($result);
    }

    public function testDeleteDelegatesToRepository(): void
    {
        $this->repository->shouldReceive('delete')
            ->once()
            ->with('test-id');

        $this->manager->delete('test-id');
    }

    public function testRetryResetsFailedJob(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: serialize(new stdClass()),
            status: JobStatus::Failed->value,
            attempts: 3,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('test-id')
            ->andReturn($job);

        $this->repository->shouldReceive('update')
            ->once()
            ->with(Mockery::on(static fn(Job $j): bool => $j->status === JobStatus::Pending->value && $j->attempts === 0));

        $this->queue->shouldReceive('push')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(JobRetrying::class));

        $this->repository->shouldReceive('deleteFailed')
            ->once()
            ->with('test-id');

        $result = $this->manager->retry('test-id');

        $this->assertTrue($result);
    }

    public function testRetryReturnsFalseForNonexistentJob(): void
    {
        $this->repository->shouldReceive('find')
            ->once()
            ->with('nonexistent')
            ->andReturn(null);

        $this->repository->shouldReceive('findFailed')
            ->once()
            ->with('nonexistent')
            ->andReturn(null);

        $result = $this->manager->retry('nonexistent');

        $this->assertFalse($result);
    }

    public function testRetryReturnsFalseForNonFailedJob(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Completed->value,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('test-id')
            ->andReturn($job);

        $this->repository->shouldReceive('findFailed')
            ->once()
            ->with('test-id')
            ->andReturn(null);

        $result = $this->manager->retry('test-id');

        $this->assertFalse($result);
    }

    public function testRetryAllRetriesAllFailedJobs(): void
    {
        $jobs = collect([
            new Job(id: 'job-1', queue: 'default', jobClass: 'TestJob', payload: serialize(new stdClass()), status: JobStatus::Failed->value),
            new Job(id: 'job-2', queue: 'default', jobClass: 'TestJob', payload: serialize(new stdClass()), status: JobStatus::Failed->value),
        ]);

        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null)
            ->andReturn($jobs);

        $this->repository->shouldReceive('find')
            ->times(2)
            ->andReturnUsing(static fn(string $id) => $jobs->first(static fn(Job $j): bool => $j->id === $id));

        $this->repository->shouldReceive('update')
            ->times(2);

        $this->queue->shouldReceive('push')
            ->times(2);

        $this->events->shouldReceive('dispatch')
            ->times(2);

        $this->repository->shouldReceive('deleteFailed')
            ->times(2);

        $count = $this->manager->retryAll();

        $this->assertSame(2, $count);
    }

    public function testCompleteUpdatesJobAndDispatchesEvent(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            workerId: 'worker-1',
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('test-id')
            ->andReturn($job);

        $this->repository->shouldReceive('complete')
            ->once()
            ->with('test-id', 500, 1024);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(JobProcessed::class));

        $this->manager->complete('test-id', 500, 1024);
    }

    public function testCompleteDoesNothingForNonexistentJob(): void
    {
        $this->repository->shouldReceive('find')
            ->once()
            ->with('nonexistent')
            ->andReturn(null);

        $this->repository->shouldNotReceive('complete');
        $this->events->shouldNotReceive('dispatch');

        $this->manager->complete('nonexistent', 500, 1024);
    }

    public function testFailUpdatesJobAndDispatchesEvent(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            attempts: 1,
            maxTries: 3,
        );

        $exception = new RuntimeException('Test error');

        $this->repository->shouldReceive('find')
            ->once()
            ->with('test-id')
            ->andReturn($job);

        $this->repository->shouldReceive('fail')
            ->once()
            ->with('test-id', Mockery::type('string'), []);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn($event): bool => $event instanceof JobFailed
                && $event->willRetry === true));

        $this->manager->fail('test-id', $exception);
    }

    public function testFailSetsWillRetryFalseWhenMaxAttemptsReached(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            attempts: 3,
            maxTries: 3,
        );

        $exception = new RuntimeException('Test error');

        $this->repository->shouldReceive('find')
            ->once()
            ->andReturn($job);

        $this->repository->shouldReceive('fail')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn($event): bool => $event instanceof JobFailed
                && $event->willRetry === false));

        $this->manager->fail('test-id', $exception);
    }

    public function testGetByStatusDelegatesToRepository(): void
    {
        $jobs = collect([
            new Job(id: 'job-1', queue: 'default', jobClass: 'TestJob', payload: '{}', status: JobStatus::Pending->value),
        ]);

        $this->repository->shouldReceive('getByStatus')
            ->once()
            ->with(JobStatus::Pending->value, 'test-queue', 50)
            ->andReturn($jobs);

        $result = $this->manager->getByStatus(JobStatus::Pending->value, 'test-queue', 50);

        $this->assertSame($jobs, $result);
    }

    public function testGetRecentDelegatesToRepository(): void
    {
        $jobs = collect([
            new Job(id: 'job-1', queue: 'default', jobClass: 'TestJob', payload: '{}', status: JobStatus::Completed->value),
        ]);

        $this->repository->shouldReceive('getRecent')
            ->once()
            ->with(10, null)
            ->andReturn($jobs);

        $result = $this->manager->getRecent(10);

        $this->assertSame($jobs, $result);
    }

    public function testGetStatsDelegatesToRepository(): void
    {
        $stats = new JobStats(pending: 5, processing: 2, completed: 100, failed: 3);

        $this->repository->shouldReceive('getStats')
            ->once()
            ->with('test-queue')
            ->andReturn($stats);

        $result = $this->manager->getStats('test-queue');

        $this->assertSame(5, $result->pending);
        $this->assertSame(2, $result->processing);
        $this->assertSame(100, $result->completed);
        $this->assertSame(3, $result->failed);
    }

    public function testSearchDelegatesToRepository(): void
    {
        $jobs = collect([]);

        $this->repository->shouldReceive('search')
            ->once()
            ->with(['status' => 'pending'], 25, 10)
            ->andReturn($jobs);

        $result = $this->manager->search(['status' => 'pending'], 25, 10);

        $this->assertSame($jobs, $result);
    }

    public function testCountDelegatesToRepository(): void
    {
        $this->repository->shouldReceive('count')
            ->once()
            ->with(['status' => 'failed'])
            ->andReturn(42);

        $result = $this->manager->count(['status' => 'failed']);

        $this->assertSame(42, $result);
    }

    public function testPruneCompletedDelegatesToRepository(): void
    {
        $this->repository->shouldReceive('pruneCompleted')
            ->once()
            ->with(24)
            ->andReturn(150);

        $result = $this->manager->pruneCompleted(24);

        $this->assertSame(150, $result);
    }

    public function testDispatchUsesJobQueuePropertyIfSet(): void
    {
        $job = new TestJob('custom queue test');
        $job->queue = 'custom-queue';

        $this->repository->shouldReceive('store')
            ->once()
            ->with(Mockery::on(static fn(Job $j): bool => $j->queue === 'custom-queue'));

        $this->queue->shouldReceive('push')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once();

        $this->manager->dispatch($job);
    }

    public function testDispatchMergesTagsFromJob(): void
    {
        $job = new TestJobWithTags('tagged job');

        $this->repository->shouldReceive('store')
            ->once()
            ->with(Mockery::on(static fn(Job $j): bool => \in_array('tag1', $j->tags, true)
                && \in_array('tag2', $j->tags, true)
                && \in_array('tag3', $j->tags, true)));

        $this->queue->shouldReceive('push')
            ->once();

        $this->events->shouldReceive('dispatch')
            ->once();

        $this->manager->dispatch($job, 'default', null, null, ['tag3']);
    }

    // ──────────────────────────────────────────────────────────────
    // cancel
    // ──────────────────────────────────────────────────────────────

    public function testCancelReturnsFalseWhenJobNotFound(): void
    {
        $this->repository->shouldReceive('find')
            ->once()
            ->with('nonexistent')
            ->andReturn(null);

        $result = $this->manager->cancel('nonexistent');

        $this->assertFalse($result);
    }

    public function testCancelReturnsFalseForCompletedJob(): void
    {
        $job = new Job(
            id: 'completed-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Completed->value,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('completed-job')
            ->andReturn($job);

        $result = $this->manager->cancel('completed-job');

        $this->assertFalse($result);
    }

    public function testCancelReturnsFalseForFailedJob(): void
    {
        $job = new Job(
            id: 'failed-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Failed->value,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('failed-job')
            ->andReturn($job);

        $result = $this->manager->cancel('failed-job');

        $this->assertFalse($result);
    }

    public function testCancelDeletesPendingJobAndReturnsTrue(): void
    {
        $job = new Job(
            id: 'pending-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('pending-job')
            ->andReturn($job);

        $this->repository->shouldReceive('delete')
            ->once()
            ->with('pending-job');

        $result = $this->manager->cancel('pending-job');

        $this->assertTrue($result);
    }

    public function testCancelDeletesProcessingJobAndReturnsTrue(): void
    {
        $job = new Job(
            id: 'processing-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('processing-job')
            ->andReturn($job);

        $this->repository->shouldReceive('delete')
            ->once()
            ->with('processing-job');

        $result = $this->manager->cancel('processing-job');

        $this->assertTrue($result);
    }

    public function testCancelDeletesReservedJobAndReturnsTrue(): void
    {
        $job = new Job(
            id: 'reserved-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Reserved->value,
        );

        $this->repository->shouldReceive('find')
            ->once()
            ->with('reserved-job')
            ->andReturn($job);

        $this->repository->shouldReceive('delete')
            ->once()
            ->with('reserved-job');

        $result = $this->manager->cancel('reserved-job');

        $this->assertTrue($result);
    }
}

class TestJobWithTags
{
    public ?string $stationJobId = null;

    public function __construct(public readonly string $message = 'test') {}

    public function handle(): void {}

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['tag1', 'tag2'];
    }
}
