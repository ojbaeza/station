<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\BatchRepositoryInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Batch;
use Station\Core\BatchManager;
use Station\Dashboard\Http\Controllers\BatchController;
use Station\Enums\BatchStatus;
use Station\StationServiceProvider;

/**
 * Extended feature tests for BatchController covering cancel/retry
 * success paths and pagination.
 */
class BatchControllerExtendedTest extends TestCase
{
    private BatchRepositoryInterface&MockInterface $batchRepository;

    private JobRepositoryInterface&MockInterface $jobRepository;

    private BatchRepositoryInterface&MockInterface $batchManagerRepo;

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

    // ---- Cancel batch success ----

    public function testCancelBatchSuccessReturnsMessage(): void
    {
        $batch = new Batch(
            id: 'batch-cancel-1',
            name: 'Cancellable Batch',
            totalJobs: 10,
            pendingJobs: 5,
            processedJobs: 5,
            failedJobs: 0,
            status: BatchStatus::Processing->value,
        );

        $this->batchManagerRepo->shouldReceive('find')
            ->with('batch-cancel-1')
            ->andReturn($batch);

        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->shouldReceive('cancel')->once();

        Bus::shouldReceive('findBatch')
            ->with('batch-cancel-1')
            ->andReturn($laravelBatch);

        $this->batchManagerRepo->shouldReceive('cancel')
            ->with('batch-cancel-1')
            ->once();

        $this->post('/station/api/batches/batch-cancel-1/cancel')
            ->assertOk()
            ->assertJson(['message' => 'Batch cancelled']);
    }

    // ---- Retry batch success ----

    public function testRetryBatchSuccessReturnsJobCount(): void
    {
        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->failedJobIds = ['job-1', 'job-2', 'job-3'];
        $laravelBatch->shouldReceive('retry')->once();

        Bus::shouldReceive('findBatch')
            ->with('batch-retry-1')
            ->andReturn($laravelBatch);

        $this->batchManagerRepo->shouldReceive('markAsProcessing')
            ->with('batch-retry-1')
            ->once();

        $this->post('/station/api/batches/batch-retry-1/retry')
            ->assertOk()
            ->assertJson(['count' => 3, 'message' => 'Queued 3 jobs for retry']);
    }

    public function testRetryBatchReturnsZeroWhenLaravelBatchNotFound(): void
    {
        Bus::shouldReceive('findBatch')
            ->with('batch-no-laravel')
            ->andReturn(null);

        $this->post('/station/api/batches/batch-no-laravel/retry')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    // ---- Batch detail with jobs ----

    public function testBatchDetailReturnsBatchAndJobs(): void
    {
        $batch = new Batch(
            id: 'batch-detail-2',
            name: 'Detail Test',
            totalJobs: 3,
            pendingJobs: 0,
            processedJobs: 3,
            failedJobs: 0,
            status: BatchStatus::Completed->value,
        );

        $this->batchRepository->shouldReceive('find')
            ->with('batch-detail-2')
            ->andReturn($batch);
        $this->jobRepository->shouldReceive('getByBatch')
            ->with('batch-detail-2')
            ->andReturn(new Collection([]));

        $this->get('/station/api/batches/batch-detail-2')
            ->assertOk()
            ->assertJsonStructure(['batch', 'jobs'])
            ->assertJsonFragment(['id' => 'batch-detail-2', 'name' => 'Detail Test']);
    }

    // ---- Batches with pagination ----

    public function testBatchesEndpointAcceptsPagination(): void
    {
        $this->batchRepository->shouldReceive('paginate')
            ->with(Mockery::on(static fn($filters) => true), 2, Mockery::any())
            ->once()
            ->andReturn([
                'data' => [], 'total' => 0, 'per_page' => 10, 'current_page' => 2, 'last_page' => 1,
            ]);

        $this->get('/station/api/batches?page=2&per_page=10')
            ->assertOk();
    }

    // ---- Bulk delete (tested via existing test - these add more coverage) ----

    public function testBulkDeleteBatchesHandlesExceptionGracefully(): void
    {
        $this->batchRepository->shouldReceive('delete')
            ->with('batch-fail')
            ->andThrow(new RuntimeException('Delete failed'));

        $this->postJson('/station/api/batches/bulk/delete', ['ids' => ['batch-fail']])
            ->assertOk()
            ->assertJson(['processed' => 0, 'failed' => 1, 'success' => false]);
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

    private function createMockDependencies(): void
    {
        $this->batchRepository = Mockery::mock(BatchRepositoryInterface::class);
        $this->batchRepository->shouldReceive('paginate')->byDefault()->andReturn([
            'data' => [], 'total' => 0, 'per_page' => 25, 'current_page' => 1, 'last_page' => 1,
        ]);
        $this->batchRepository->shouldReceive('find')->byDefault()->andReturn(null);
        $this->batchRepository->shouldReceive('delete')->byDefault();

        $this->jobRepository = Mockery::mock(JobRepositoryInterface::class);
        $this->jobRepository->shouldReceive('getByBatch')->byDefault()->andReturn([]);

        $this->batchManagerRepo = Mockery::mock(BatchRepositoryInterface::class);
        $this->batchManagerRepo->shouldReceive('find')->byDefault()->andReturn(null);
        $this->batchManagerRepo->shouldReceive('updateStatus')->byDefault();
        $this->batchManagerRepo->shouldReceive('cancel')->byDefault();
        $this->batchManagerRepo->shouldReceive('markAsProcessing')->byDefault();

        $this->events = Mockery::mock(Dispatcher::class);
        $this->events->shouldReceive('dispatch')->byDefault();
    }

    private function bindController(): void
    {
        $batchManager = new BatchManager(
            $this->batchManagerRepo,
            $this->events,
            [],
        );

        $controller = new BatchController(
            batchRepository: $this->batchRepository,
            batchManager: $batchManager,
            jobRepository: $this->jobRepository,
        );

        $this->app->instance(BatchController::class, $controller);
    }
}
