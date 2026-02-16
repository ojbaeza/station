<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\Core\JobManager;
use Station\Dashboard\Http\Controllers\JobController;
use Station\DTOs\PaginatedResult;
use Station\StationServiceProvider;

class JobControllerTest extends TestCase
{
    private JobRepositoryInterface&MockInterface $jobRepository;

    private Dispatcher&MockInterface $events;

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

    // ---- Jobs endpoints ----

    public function testJobsEndpointReturnsPaginatedJobs(): void
    {
        $this->get('/station/api/jobs')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function testJobsEndpointAcceptsFilters(): void
    {
        $this->get('/station/api/jobs?queue=default&status=pending&tag=important&connection=redis&search=test')
            ->assertOk();
    }

    public function testJobEndpointReturnsJobDetail(): void
    {
        $job = new Job(
            id: 'test-job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\TestJob',
            payload: serialize(['data' => 'test']),
            status: 'pending',
        );

        $this->jobRepository->shouldReceive('find')
            ->with('test-job-1')
            ->andReturn($job);
        $this->jobRepository->shouldReceive('getEvents')
            ->with('test-job-1')
            ->andReturn(new Collection([]));

        $this->get('/station/api/jobs/test-job-1')
            ->assertOk()
            ->assertJsonStructure(['job', 'events']);
    }

    public function testJobEndpointReturns404WhenNotFound(): void
    {
        $this->get('/station/api/jobs/nonexistent')
            ->assertStatus(404)
            ->assertJson(['error' => 'Job not found']);
    }

    // ---- Retry Job endpoint ----

    public function testRetryJobReturnsSuccessOnRetry(): void
    {
        $failedJob = new Job(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: '',
            status: 'failed',
        );

        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($failedJob);
        $this->jobRepository->shouldReceive('update')->once();
        $this->jobRepository->shouldReceive('deleteFailed')->with('job-1');

        $this->post('/station/api/jobs/job-1/retry')
            ->assertOk()
            ->assertJson(['message' => 'Job queued for retry']);
    }

    public function testRetryJobReturns404WhenJobNotFound(): void
    {
        $this->post('/station/api/jobs/job-1/retry')
            ->assertStatus(404);
    }

    public function testRetryJobReturns400WhenCannotRetry(): void
    {
        $pendingJob = $this->makeJob('job-1', 'pending');

        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($pendingJob);

        $this->post('/station/api/jobs/job-1/retry')
            ->assertStatus(400)
            ->assertJsonFragment(['error' => 'Job cannot be retried (status: pending)']);
    }

    public function testRetryJobHandlesExceptionGracefully(): void
    {
        $this->jobRepository->shouldReceive('find')
            ->with('job-err')
            ->andThrow(new RuntimeException('Database error'));

        $this->post('/station/api/jobs/job-err/retry')
            ->assertStatus(400);
    }

    // ---- Cancel Job endpoint ----

    public function testCancelJobReturnsSuccess(): void
    {
        $job = new Job(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: '',
            status: 'processing',
        );

        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($job);
        $this->jobRepository->shouldReceive('delete')->with('job-1')->once();

        $this->post('/station/api/jobs/job-1/cancel')
            ->assertOk()
            ->assertJson(['message' => 'Job cancelled']);
    }

    public function testCancelJobHandlesExceptionGracefully(): void
    {
        $this->jobRepository->shouldReceive('find')
            ->with('job-err')
            ->andThrow(new RuntimeException('Database error'));

        $this->post('/station/api/jobs/job-err/cancel')
            ->assertStatus(400);
    }

    // ---- Failed Jobs ----

    public function testFailedJobsEndpointReturnsPaginatedResults(): void
    {
        $this->get('/station/api/failed')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function testFailedJobsEndpointAcceptsFilters(): void
    {
        $this->get('/station/api/failed?queue=default&tag=critical&connection=redis')
            ->assertOk();
    }

    public function testFlushFailedReturnsCount(): void
    {
        $this->jobRepository->shouldReceive('flushFailed')->andReturn(5);

        $this->delete('/station/api/failed')
            ->assertOk()
            ->assertJson(['count' => 5]);
    }

    public function testFlushFailedHandlesExceptionGracefully(): void
    {
        $this->jobRepository->shouldReceive('flushFailed')
            ->andThrow(new RuntimeException('DB error'));

        $this->delete('/station/api/failed')
            ->assertStatus(400);
    }

    public function testRetryAllFailedReturnsCount(): void
    {
        $this->jobRepository->shouldReceive('getFailed')->andReturn(new Collection([]));

        $this->post('/station/api/failed/retry-all')
            ->assertOk()
            ->assertJsonStructure(['message', 'count']);
    }

    public function testRetryAllFailedHandlesException(): void
    {
        $this->jobRepository->shouldReceive('getFailed')
            ->andThrow(new RuntimeException('DB error'));

        $this->post('/station/api/failed/retry-all')
            ->assertStatus(400);
    }

    public function testDeleteFailedJobReturnsSuccess(): void
    {
        $this->jobRepository->shouldReceive('deleteFailed')->with('job-1');

        $this->delete('/station/api/failed/job-1')
            ->assertOk()
            ->assertJson(['message' => 'Failed job deleted']);
    }

    public function testDeleteFailedJobHandlesException(): void
    {
        $this->jobRepository->shouldReceive('deleteFailed')
            ->with('job-1')
            ->andThrow(new RuntimeException('DB error'));

        $this->delete('/station/api/failed/job-1')
            ->assertStatus(400);
    }

    public function testRetryFailedJobReturnsSuccess(): void
    {
        $failedJob = new Job(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: '',
            status: 'failed',
        );

        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($failedJob);
        $this->jobRepository->shouldReceive('update')->once();
        $this->jobRepository->shouldReceive('deleteFailed')->with('job-1');

        $this->post('/station/api/failed/job-1/retry')
            ->assertOk()
            ->assertJson(['message' => 'Failed job queued for retry']);
    }

    public function testRetryFailedJobReturns404WhenNotFound(): void
    {
        $this->post('/station/api/failed/nonexistent/retry')
            ->assertStatus(404);
    }

    public function testRetryFailedJobReturns400WhenCannotRetry(): void
    {
        $pendingJob = $this->makeJob('job-1', 'pending');

        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($pendingJob);

        $this->post('/station/api/failed/job-1/retry')
            ->assertStatus(400)
            ->assertJsonFragment(['error' => 'Job cannot be retried (status: pending)']);
    }

    // ---- Bulk Operations ----

    public function testBulkDeleteJobsProcessesAllIds(): void
    {
        $this->jobRepository->shouldReceive('delete')
            ->with('job-1')
            ->once();
        $this->jobRepository->shouldReceive('delete')
            ->with('job-2')
            ->once();

        $this->postJson('/station/api/jobs/bulk/delete', ['ids' => ['job-1', 'job-2']])
            ->assertOk()
            ->assertJson(['processed' => 2, 'failed' => 0, 'success' => true]);
    }

    public function testBulkDeleteJobsValidatesRequiredIds(): void
    {
        $this->postJson('/station/api/jobs/bulk/delete', [])
            ->assertStatus(422);
    }

    public function testBulkDeleteJobsContinuesOnError(): void
    {
        $this->jobRepository->shouldReceive('delete')
            ->with('job-1')
            ->andThrow(new RuntimeException('DB error'));
        $this->jobRepository->shouldReceive('delete')
            ->with('job-2')
            ->once();

        $this->postJson('/station/api/jobs/bulk/delete', ['ids' => ['job-1', 'job-2']])
            ->assertOk()
            ->assertJson(['processed' => 1, 'failed' => 1, 'success' => false]);
    }

    public function testBulkDeleteFailedEndpoint(): void
    {
        $this->jobRepository->shouldReceive('deleteFailed')->with('job-1');

        $this->postJson('/station/api/failed/bulk/delete', ['ids' => ['job-1']])
            ->assertOk()
            ->assertJson(['processed' => 1, 'failed' => 0, 'success' => true]);
    }

    public function testBulkDeleteFailedProcessesAllIds(): void
    {
        $this->jobRepository->shouldReceive('deleteFailed')
            ->with('job-1')
            ->once();
        $this->jobRepository->shouldReceive('deleteFailed')
            ->with('job-2')
            ->once();

        $this->postJson('/station/api/failed/bulk/delete', ['ids' => ['job-1', 'job-2']])
            ->assertOk()
            ->assertJson(['processed' => 2, 'failed' => 0, 'success' => true]);
    }

    public function testBulkDeleteFailedValidatesRequiredIds(): void
    {
        $this->postJson('/station/api/failed/bulk/delete', [])
            ->assertStatus(422);
    }

    // ---- Per-page clamping ----

    public function testJobsEndpointClampsPerPage(): void
    {
        $this->get('/station/api/jobs?per_page=999')
            ->assertOk();
    }

    public function testJobsEndpointClampsPerPageToMinimum(): void
    {
        $this->get('/station/api/jobs?per_page=0')
            ->assertOk();
    }

    // ---- Tags ----

    public function testTagsEndpointReturnsTags(): void
    {
        $this->get('/station/api/tags')
            ->assertOk();
    }

    public function testAddJobTagReturns404WhenJobNotFound(): void
    {
        $this->postJson('/station/api/jobs/nonexistent/tags', ['tag' => 'important'])
            ->assertStatus(404)
            ->assertJson(['error' => 'Job not found']);
    }

    public function testRemoveJobTagReturns404WhenJobNotFound(): void
    {
        $this->deleteJson('/station/api/jobs/nonexistent/tags/test')
            ->assertStatus(404)
            ->assertJson(['error' => 'Job not found']);
    }

    public function testAddJobTagReturnsSuccess(): void
    {
        $job = new Job(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: '',
            status: 'pending',
        );
        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($job);
        $this->jobRepository->shouldReceive('addTag')
            ->with('job-1', 'urgent')
            ->once();

        $this->postJson('/station/api/jobs/job-1/tags', ['tag' => 'urgent'])
            ->assertOk()
            ->assertJson(['message' => 'Tag added']);
    }

    public function testRemoveJobTagReturnsSuccess(): void
    {
        $job = new Job(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\Test',
            payload: '',
            status: 'pending',
        );
        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($job);
        $this->jobRepository->shouldReceive('removeTag')
            ->with('job-1', 'urgent')
            ->once();

        $this->deleteJson('/station/api/jobs/job-1/tags/urgent')
            ->assertOk()
            ->assertJson(['message' => 'Tag removed']);
    }

    public function testAddJobTagValidatesTagRequired(): void
    {
        $this->postJson('/station/api/jobs/job-1/tags', [])
            ->assertStatus(422);
    }

    public function testAddJobTagValidatesTagMaxLength(): void
    {
        $this->postJson('/station/api/jobs/job-1/tags', ['tag' => str_repeat('a', 101)])
            ->assertStatus(422);
    }

    public function testAddJobTagHandlesRepositoryException(): void
    {
        $job = $this->makeJob('job-tag-err', 'pending');
        $this->jobRepository->shouldReceive('find')
            ->with('job-tag-err')
            ->andReturn($job);
        $this->jobRepository->shouldReceive('addTag')
            ->with('job-tag-err', 'failing-tag')
            ->andThrow(new RuntimeException('DB constraint violation'));

        $this->postJson('/station/api/jobs/job-tag-err/tags', ['tag' => 'failing-tag'])
            ->assertStatus(400);
    }

    public function testRemoveJobTagHandlesRepositoryException(): void
    {
        $job = $this->makeJob('job-tag-err', 'pending');
        $this->jobRepository->shouldReceive('find')
            ->with('job-tag-err')
            ->andReturn($job);
        $this->jobRepository->shouldReceive('removeTag')
            ->with('job-tag-err', 'bad-tag')
            ->andThrow(new RuntimeException('DB error'));

        $this->deleteJson('/station/api/jobs/job-tag-err/tags/bad-tag')
            ->assertStatus(400);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.api.auth', 'none');
        $app['config']->set('station.api.middleware', []);
        $app['config']->set('station.default', 'redis');
        $app['config']->set('queue.connections', []);
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

    private function makeJob(string $id, string $status): Job
    {
        return new Job(
            id: $id,
            queue: 'default',
            jobClass: 'App\\Jobs\\TestJob',
            payload: serialize(['data' => 'test']),
            status: $status,
        );
    }

    private function createMockDependencies(): void
    {
        $this->jobRepository = Mockery::mock(JobRepositoryInterface::class);
        $this->jobRepository->shouldReceive('paginate')->byDefault()->andReturn(
            PaginatedResult::empty(25),
        );
        $this->jobRepository->shouldReceive('paginateFailed')->byDefault()->andReturn(
            PaginatedResult::empty(25),
        );
        $this->jobRepository->shouldReceive('find')->byDefault()->andReturn(null);
        $this->jobRepository->shouldReceive('findFailed')->byDefault()->andReturn(null);
        $this->jobRepository->shouldReceive('getFailed')->byDefault()->andReturn(new Collection([]));
        $this->jobRepository->shouldReceive('getEvents')->byDefault()->andReturn(new Collection([]));
        $this->jobRepository->shouldReceive('getDistinctTags')->byDefault()->andReturn(['tag1', 'tag2']);
        $this->jobRepository->shouldReceive('getQueues')->byDefault()->andReturn(['default', 'emails']);
        $this->jobRepository->shouldReceive('getByBatch')->byDefault()->andReturn([]);
        $this->jobRepository->shouldReceive('getByStatus')->byDefault()->andReturn(new Collection([]));
        $this->jobRepository->shouldReceive('flushFailed')->byDefault()->andReturn(0);
        $this->jobRepository->shouldReceive('deleteFailed')->byDefault();
        $this->jobRepository->shouldReceive('addTag')->byDefault();
        $this->jobRepository->shouldReceive('removeTag')->byDefault();
        $this->jobRepository->shouldReceive('delete')->byDefault();
        $this->jobRepository->shouldReceive('update')->byDefault();
        $this->jobRepository->shouldReceive('updateStatus')->byDefault();

        $this->events = Mockery::mock(Dispatcher::class);
        $this->events->shouldReceive('dispatch')->byDefault();
    }

    private function bindController(): void
    {
        $queueFactory = Mockery::mock(QueueFactory::class);
        $queueFactory->shouldReceive('connection')->byDefault()->andReturnSelf();
        $queueFactory->shouldReceive('push')->byDefault();
        $queueFactory->shouldReceive('later')->byDefault();

        $jobManager = new JobManager(
            $this->jobRepository,
            $queueFactory,
            $this->events,
            [],
        );

        $controller = new JobController(
            jobRepository: $this->jobRepository,
            jobManager: $jobManager,
        );

        $this->app->instance(JobController::class, $controller);
    }
}
