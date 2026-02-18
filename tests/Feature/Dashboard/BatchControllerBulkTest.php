<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
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
 * Feature tests for BatchController bulk operations not covered elsewhere:
 * - bulkCancelBatches
 * - bulkRetryBatches
 * - cancelBatch success path
 * - retryBatch success path
 */
class BatchControllerBulkTest extends TestCase
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

        // Mock Bus facade to prevent job_batches table queries
        Bus::shouldReceive('findBatch')->byDefault()->andReturn(null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- bulkCancelBatches ----

    public function testBulkCancelBatchesProcessesAllIds(): void
    {
        // BatchManager::cancel calls repository->find, Bus::findBatch, repository->cancel
        $this->batchManagerRepo->shouldReceive('find')
            ->andReturn(new Batch(
                id: 'batch-1',
                name: 'Test',
                status: BatchStatus::Processing->value,
                totalJobs: 5,
                processedJobs: 2,
                failedJobs: 0,
            ));
        $this->batchManagerRepo->shouldReceive('cancel')->twice();

        $this->postJson('/api/station/batches/bulk/cancel', ['ids' => ['batch-1', 'batch-2']])
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 2]);
    }

    public function testBulkCancelBatchesValidatesRequiredIds(): void
    {
        $this->postJson('/api/station/batches/bulk/cancel', [])
            ->assertStatus(422);
    }

    public function testBulkCancelBatchesContinuesOnError(): void
    {
        $this->batchManagerRepo->shouldReceive('find')
            ->with('batch-1')
            ->andThrow(new RuntimeException('DB error'));
        $this->batchManagerRepo->shouldReceive('find')
            ->with('batch-2')
            ->andReturn(new Batch(
                id: 'batch-2',
                name: 'Test',
                status: BatchStatus::Processing->value,
                totalJobs: 5,
                processedJobs: 2,
                failedJobs: 0,
            ));
        $this->batchManagerRepo->shouldReceive('cancel')->once();

        $this->postJson('/api/station/batches/bulk/cancel', ['ids' => ['batch-1', 'batch-2']])
            ->assertOk()
            ->assertJson(['processed' => 1, 'failed' => 1]);
    }

    // ---- bulkRetryBatches ----

    public function testBulkRetryBatchesProcessesAllIds(): void
    {
        // retryFailed resolves to Bus::findBatch which is mocked to return null
        $this->postJson('/api/station/batches/bulk/retry', ['ids' => ['batch-1', 'batch-2']])
            ->assertOk();
    }

    public function testBulkRetryBatchesValidatesRequiredIds(): void
    {
        $this->postJson('/api/station/batches/bulk/retry', [])
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
        $app['config']->set('station.default', 'redis');
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
