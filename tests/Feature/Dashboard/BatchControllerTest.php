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

class BatchControllerTest extends TestCase
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

    // ---- Batches list ----

    public function testBatchesEndpointReturnsPaginatedResults(): void
    {
        $this->get('/station/api/batches')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function testBatchDetailWithStatus(): void
    {
        $this->get('/station/api/batches?status=completed')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    // ---- Batch detail ----

    public function testBatchEndpointReturns404WhenNotFound(): void
    {
        $this->get('/station/api/batches/nonexistent')
            ->assertStatus(404)
            ->assertJson(['error' => 'Batch not found']);
    }

    public function testBatchDetailReturnsJobsWithBatch(): void
    {
        $batch = new Batch(
            id: 'batch-detail-1',
            name: 'Test Batch',
            totalJobs: 5,
            pendingJobs: 2,
            processedJobs: 3,
            failedJobs: 0,
            status: BatchStatus::Processing->value,
        );

        $this->batchRepository->shouldReceive('find')
            ->with('batch-detail-1')
            ->andReturn($batch);
        $this->jobRepository->shouldReceive('getByBatch')
            ->with('batch-detail-1')
            ->andReturn(new Collection([]));

        $this->get('/station/api/batches/batch-detail-1')
            ->assertOk()
            ->assertJsonStructure(['batch', 'jobs'])
            ->assertJsonFragment(['id' => 'batch-detail-1']);
    }

    // ---- Cancel batch ----

    public function testCancelBatchReturnsErrorWhenNotFound(): void
    {
        $this->post('/station/api/batches/batch-1/cancel')
            ->assertStatus(400)
            ->assertJson(['error' => 'Batch not found or already finished']);
    }

    public function testCancelBatchHandlesException(): void
    {
        $batch = new Batch(
            id: 'batch-err',
            name: 'Error Batch',
            totalJobs: 5,
            pendingJobs: 3,
            processedJobs: 2,
            failedJobs: 0,
            status: BatchStatus::Processing->value,
        );

        $this->batchManagerRepo->shouldReceive('find')
            ->with('batch-err')
            ->andReturn($batch);

        Bus::shouldReceive('findBatch')
            ->with('batch-err')
            ->andThrow(new RuntimeException('Bus error'));

        $this->post('/station/api/batches/batch-err/cancel')
            ->assertStatus(400);
    }

    // ---- Retry batch ----

    public function testRetryBatchReturnsErrorWhenBatchNotInDatabase(): void
    {
        $this->post('/station/api/batches/batch-1/retry')
            ->assertStatus(400);
    }

    public function testRetryBatchHandlesException(): void
    {
        Bus::shouldReceive('findBatch')
            ->with('batch-err')
            ->andThrow(new RuntimeException('Bus error'));

        $this->post('/station/api/batches/batch-err/retry')
            ->assertStatus(400);
    }

    // ---- Bulk operations ----

    public function testBulkDeleteBatchesEndpoint(): void
    {
        $this->batchRepository->shouldReceive('delete')->with('batch-1');

        $this->postJson('/station/api/batches/bulk/delete', ['ids' => ['batch-1']])
            ->assertOk()
            ->assertJson(['processed' => 1, 'failed' => 0, 'success' => true]);
    }

    public function testBulkDeleteBatchesProcessesAllIds(): void
    {
        $this->batchRepository->shouldReceive('delete')
            ->with('batch-1')
            ->once();
        $this->batchRepository->shouldReceive('delete')
            ->with('batch-2')
            ->once();

        $this->postJson('/station/api/batches/bulk/delete', ['ids' => ['batch-1', 'batch-2']])
            ->assertOk()
            ->assertJson(['processed' => 2, 'failed' => 0, 'success' => true]);
    }

    public function testBulkDeleteBatchesValidatesRequiredIds(): void
    {
        $this->postJson('/station/api/batches/bulk/delete', [])
            ->assertStatus(422);
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
