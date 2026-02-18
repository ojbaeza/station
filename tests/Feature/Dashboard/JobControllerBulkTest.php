<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\Core\JobManager;
use Station\Dashboard\Http\Controllers\JobController;
use Station\DTOs\PaginatedResult;
use Station\StationServiceProvider;

/**
 * Feature tests for JobController bulk operations not covered elsewhere:
 * - bulkCancelJobs
 * - bulkRetryJobs
 * - bulkRetryFailed
 */
class JobControllerBulkTest extends TestCase
{
    private JobRepositoryInterface&MockInterface $jobRepository;

    private Dispatcher&MockInterface $events;

    private QueueFactory&MockInterface $queueFactory;

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

    // ---- bulkCancelJobs (via API routes where bulk is before {id}) ----

    public function testBulkCancelJobsProcessesAllIds(): void
    {
        $this->postJson('/api/station/jobs/bulk/cancel', ['ids' => ['job-1', 'job-2']])
            ->assertOk()
            ->assertJsonStructure(['success', 'processed', 'failed']);
    }

    public function testBulkCancelJobsValidatesRequiredIds(): void
    {
        $this->postJson('/api/station/jobs/bulk/cancel', [])
            ->assertStatus(422);
    }

    public function testBulkCancelJobsWithFoundJob(): void
    {
        $job = new Job(id: 'job-1', queue: 'default', jobClass: 'App\\Jobs\\Test', payload: 'O:8:"stdClass":0:{}', status: 'pending');
        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($job);
        // JobManager::cancel() calls repository->delete(), not updateStatus()
        $this->jobRepository->shouldReceive('delete')
            ->with('job-1')
            ->once();

        $this->postJson('/api/station/jobs/bulk/cancel', ['ids' => ['job-1']])
            ->assertOk()
            ->assertJson(['processed' => 1, 'failed' => 0, 'success' => true]);
    }

    // ---- bulkRetryJobs ----

    public function testBulkRetryJobsProcessesAllIds(): void
    {
        $this->jobRepository->shouldReceive('findFailed')->byDefault()->andReturn(null);

        $this->postJson('/api/station/jobs/bulk/retry', ['ids' => ['job-1', 'job-2']])
            ->assertOk()
            ->assertJsonStructure(['success', 'processed', 'failed']);
    }

    public function testBulkRetryJobsValidatesRequiredIds(): void
    {
        $this->postJson('/api/station/jobs/bulk/retry', [])
            ->assertStatus(422);
    }

    public function testBulkRetryJobsWithFailedJob(): void
    {
        $job = new Job(id: 'job-1', queue: 'default', jobClass: 'App\\Jobs\\Test', payload: 'O:8:"stdClass":0:{}', status: 'failed');
        $this->jobRepository->shouldReceive('find')
            ->with('job-1')
            ->andReturn($job);
        $this->jobRepository->shouldReceive('findFailed')->byDefault()->andReturn(null);

        $this->postJson('/api/station/jobs/bulk/retry', ['ids' => ['job-1']])
            ->assertOk()
            ->assertJson(['processed' => 1, 'success' => true]);
    }

    // ---- bulkRetryFailed ----

    public function testBulkRetryFailedProcessesAllIds(): void
    {
        $this->jobRepository->shouldReceive('findFailed')->byDefault()->andReturn(null);

        $this->postJson('/api/station/failed/bulk/retry', ['ids' => ['fail-1', 'fail-2']])
            ->assertOk()
            ->assertJsonStructure(['success', 'processed', 'failed']);
    }

    public function testBulkRetryFailedValidatesRequiredIds(): void
    {
        $this->postJson('/api/station/failed/bulk/retry', [])
            ->assertStatus(422);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.api.enabled', true);
        $app['config']->set('station.api.auth', 'none');
        $app['config']->set('station.api.middleware', []);
        $app['config']->set('queue.connections', []);
        $app['config']->set('queue.default', 'sync');
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

    private function createMockDependencies(): void
    {
        $this->jobRepository = Mockery::mock(JobRepositoryInterface::class);
        $this->jobRepository->shouldReceive('paginate')->byDefault()->andReturn(PaginatedResult::empty());
        $this->jobRepository->shouldReceive('paginateFailed')->byDefault()->andReturn(PaginatedResult::empty());
        $this->jobRepository->shouldReceive('find')->byDefault()->andReturn(null);
        $this->jobRepository->shouldReceive('getDistinctTags')->byDefault()->andReturn([]);
        $this->jobRepository->shouldReceive('delete')->byDefault();
        $this->jobRepository->shouldReceive('deleteFailed')->byDefault();
        $this->jobRepository->shouldReceive('update')->byDefault();
        $this->jobRepository->shouldReceive('updateStatus')->byDefault();

        $this->events = Mockery::mock(Dispatcher::class);
        $this->events->shouldReceive('dispatch')->byDefault();

        $this->queueFactory = Mockery::mock(QueueFactory::class);
        $this->queueFactory->shouldReceive('connection')->byDefault()->andReturnSelf();
        $this->queueFactory->shouldReceive('push')->byDefault();
        $this->queueFactory->shouldReceive('later')->byDefault();
    }

    private function bindController(): void
    {
        $jobManager = new JobManager(
            $this->jobRepository,
            $this->queueFactory,
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
