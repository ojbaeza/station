<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Orchestra\Testbench\TestCase;
use Station\Core\Batch;
use Station\Enums\BatchStatus;
use Station\Repositories\DatabaseBatchRepository;
use Station\StationServiceProvider;

class DatabaseBatchRepositoryTest extends TestCase
{
    private DatabaseBatchRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->repository = new DatabaseBatchRepository(DB::connection(), 'station_');
    }

    public function testStoreAndFind(): void
    {
        $batch = $this->createBatch(['id' => 'batch-1']);

        $this->repository->store($batch);

        $found = $this->repository->find('batch-1');

        $this->assertNotNull($found);
        $this->assertSame('batch-1', $found->id);
        $this->assertSame('default', $found->queue);
        $this->assertSame(BatchStatus::Pending->value, $found->status);
    }

    public function testFindReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->repository->find('nonexistent'));
    }

    public function testUpdate(): void
    {
        $batch = $this->createBatch(['id' => 'batch-update']);
        $this->repository->store($batch);

        $batch->status = BatchStatus::Processing->value;
        $batch->processedJobs = 5;
        $this->repository->update($batch);

        $found = $this->repository->find('batch-update');

        $this->assertSame(BatchStatus::Processing->value, $found->status);
        $this->assertSame(5, $found->processedJobs);
    }

    public function testDelete(): void
    {
        $batch = $this->createBatch(['id' => 'batch-delete']);
        $this->repository->store($batch);

        $this->repository->delete('batch-delete');

        $this->assertNull($this->repository->find('batch-delete'));
    }

    public function testGetByStatus(): void
    {
        $this->repository->store($this->createBatch(['id' => 'b1', 'status' => BatchStatus::Pending->value]));
        $this->repository->store($this->createBatch(['id' => 'b2', 'status' => BatchStatus::Pending->value]));
        $this->repository->store($this->createBatch(['id' => 'b3', 'status' => BatchStatus::Completed->value]));

        $pending = $this->repository->getByStatus(BatchStatus::Pending->value);

        $this->assertCount(2, $pending);
    }

    public function testGetActive(): void
    {
        $this->repository->store($this->createBatch(['id' => 'b-pending', 'status' => BatchStatus::Pending->value]));
        $this->repository->store($this->createBatch(['id' => 'b-processing', 'status' => BatchStatus::Processing->value]));
        $this->repository->store($this->createBatch(['id' => 'b-completed', 'status' => BatchStatus::Completed->value]));
        $this->repository->store($this->createBatch(['id' => 'b-failed', 'status' => BatchStatus::Failed->value]));

        $active = $this->repository->getActive();

        $this->assertCount(2, $active);
        $ids = $active->pluck('id')->toArray();
        $this->assertContains('b-pending', $ids);
        $this->assertContains('b-processing', $ids);
    }

    public function testGetRecent(): void
    {
        $this->repository->store($this->createBatch(['id' => 'b1']));
        $this->repository->store($this->createBatch(['id' => 'b2']));
        $this->repository->store($this->createBatch(['id' => 'b3']));

        $recent = $this->repository->getRecent(2);

        $this->assertCount(2, $recent);
    }

    public function testIncrementProcessed(): void
    {
        $batch = $this->createBatch(['id' => 'b-inc', 'totalJobs' => 5, 'pendingJobs' => 5]);
        $this->repository->store($batch);

        $pending = $this->repository->incrementProcessed('b-inc');

        $this->assertSame(4, $pending);

        $found = $this->repository->find('b-inc');
        $this->assertSame(1, $found->processedJobs);
        $this->assertSame(4, $found->pendingJobs);
    }

    public function testIncrementProcessedClampsPendingAtZero(): void
    {
        $batch = $this->createBatch(['id' => 'b-clamp', 'totalJobs' => 1, 'pendingJobs' => 0, 'processedJobs' => 1]);
        $this->repository->store($batch);

        $pending = $this->repository->incrementProcessed('b-clamp');

        $this->assertSame(0, $pending);
    }

    public function testIncrementFailed(): void
    {
        $batch = $this->createBatch(['id' => 'b-fail', 'totalJobs' => 5, 'pendingJobs' => 5]);
        $this->repository->store($batch);

        $pending = $this->repository->incrementFailed('b-fail', 'job-1');

        $this->assertSame(4, $pending);

        $found = $this->repository->find('b-fail');
        $this->assertSame(1, $found->failedJobs);
        $this->assertSame(1, $found->processedJobs);
        $this->assertSame(4, $found->pendingJobs);
    }

    public function testMarkAsStarted(): void
    {
        $batch = $this->createBatch(['id' => 'b-start', 'status' => BatchStatus::Pending->value]);
        $this->repository->store($batch);

        $this->repository->markAsStarted('b-start');

        $found = $this->repository->find('b-start');
        $this->assertSame(BatchStatus::Processing->value, $found->status);
        $this->assertNotNull($found->startedAt);
    }

    public function testMarkAsStartedOnlyAffectsPendingBatches(): void
    {
        $batch = $this->createBatch(['id' => 'b-already', 'status' => BatchStatus::Processing->value]);
        $this->repository->store($batch);

        $this->repository->markAsStarted('b-already');

        // Status should remain processing (not changed because it wasn't pending)
        $found = $this->repository->find('b-already');
        $this->assertSame(BatchStatus::Processing->value, $found->status);
    }

    public function testMarkAsProcessing(): void
    {
        $batch = $this->createBatch(['id' => 'b-proc', 'status' => BatchStatus::Failed->value]);
        $this->repository->store($batch);

        $this->repository->markAsProcessing('b-proc');

        $found = $this->repository->find('b-proc');
        $this->assertSame(BatchStatus::Processing->value, $found->status);
    }

    public function testMarkAsFinished(): void
    {
        $batch = $this->createBatch(['id' => 'b-fin', 'status' => BatchStatus::Processing->value]);
        $this->repository->store($batch);

        $this->repository->markAsFinished('b-fin', BatchStatus::Completed->value);

        $found = $this->repository->find('b-fin');
        $this->assertSame(BatchStatus::Completed->value, $found->status);
        $this->assertNotNull($found->finishedAt);
    }

    public function testCancel(): void
    {
        $batch = $this->createBatch(['id' => 'b-cancel', 'status' => BatchStatus::Processing->value]);
        $this->repository->store($batch);

        $this->repository->cancel('b-cancel');

        $found = $this->repository->find('b-cancel');
        $this->assertSame(BatchStatus::Cancelled->value, $found->status);
        $this->assertNotNull($found->cancelledAt);
        $this->assertNotNull($found->finishedAt);
    }

    public function testPrune(): void
    {
        // Old completed batch
        $this->repository->store($this->createBatch(['id' => 'b-old-completed', 'status' => BatchStatus::Completed->value]));
        DB::table('station_batches')->where('id', 'b-old-completed')->update([
            'finished_at' => CarbonImmutable::now()->subHours(48)->toDateTimeString(),
        ]);

        // Recent completed batch
        $this->repository->store($this->createBatch(['id' => 'b-new-completed', 'status' => BatchStatus::Completed->value]));
        DB::table('station_batches')->where('id', 'b-new-completed')->update([
            'finished_at' => CarbonImmutable::now()->subHours(12)->toDateTimeString(),
        ]);

        // Old cancelled batch
        $this->repository->store($this->createBatch(['id' => 'b-old-cancelled', 'status' => BatchStatus::Cancelled->value]));
        DB::table('station_batches')->where('id', 'b-old-cancelled')->update([
            'cancelled_at' => CarbonImmutable::now()->subHours(100)->toDateTimeString(),
        ]);

        // Old failed batch
        $this->repository->store($this->createBatch(['id' => 'b-old-failed', 'status' => BatchStatus::Failed->value]));
        DB::table('station_batches')->where('id', 'b-old-failed')->update([
            'finished_at' => CarbonImmutable::now()->subHours(200)->toDateTimeString(),
        ]);

        $deleted = $this->repository->prune(24, 72, 168);

        $this->assertSame(3, $deleted);
        $this->assertNull($this->repository->find('b-old-completed'));
        $this->assertNotNull($this->repository->find('b-new-completed'));
        $this->assertNull($this->repository->find('b-old-cancelled'));
        $this->assertNull($this->repository->find('b-old-failed'));
    }

    public function testPaginate(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->repository->store($this->createBatch(['id' => "b-page-{$i}"]));
        }

        $page1 = $this->repository->paginate([], 1, 3);

        $this->assertSame(10, $page1['total']);
        $this->assertSame(3, $page1['per_page']);
        $this->assertSame(1, $page1['current_page']);
        $this->assertSame(4, $page1['last_page']);
        $this->assertCount(3, $page1['data']);
    }

    public function testPaginateWithStatusFilter(): void
    {
        $this->repository->store($this->createBatch(['id' => 'b-p1', 'status' => BatchStatus::Pending->value]));
        $this->repository->store($this->createBatch(['id' => 'b-p2', 'status' => BatchStatus::Pending->value]));
        $this->repository->store($this->createBatch(['id' => 'b-c1', 'status' => BatchStatus::Completed->value]));

        $result = $this->repository->paginate(['status' => BatchStatus::Pending->value], 1, 25);

        $this->assertSame(2, $result['total']);
    }

    public function testPaginateWithConnectionFilter(): void
    {
        $this->repository->store($this->createBatch(['id' => 'b-redis', 'connection' => 'redis']));
        $this->repository->store($this->createBatch(['id' => 'b-rabbit', 'connection' => 'rabbitmq']));
        $this->repository->store($this->createBatch(['id' => 'b-redis2', 'connection' => 'redis']));

        $result = $this->repository->paginate(['connection' => 'redis'], 1, 25);

        $this->assertSame(2, $result['total']);
    }

    public function testRetry(): void
    {
        $batch = $this->createBatch([
            'id' => 'b-retry',
            'status' => BatchStatus::Failed->value,
            'failedJobs' => 2,
            'failedJobIds' => ['job-1', 'job-2'],
        ]);
        $this->repository->store($batch);

        $retried = $this->repository->retry('b-retry');

        $this->assertSame(2, $retried);

        $found = $this->repository->find('b-retry');
        $this->assertSame(BatchStatus::Pending->value, $found->status);
        $this->assertSame(0, $found->failedJobs);
    }

    public function testRetryReturnsZeroForNonExistentBatch(): void
    {
        $retried = $this->repository->retry('nonexistent');

        $this->assertSame(0, $retried);
    }

    public function testSyncFromLaravel(): void
    {
        $batch = $this->createBatch(['id' => 'b-sync', 'totalJobs' => 10]);
        $this->repository->store($batch);

        $laravelBatch = Mockery::mock(\Illuminate\Bus\Batch::class);
        $laravelBatch->totalJobs = 10;
        $laravelBatch->pendingJobs = 3;
        $laravelBatch->failedJobs = 2;
        $laravelBatch->failedJobIds = ['job-a', 'job-b'];

        $this->repository->syncFromLaravel('b-sync', $laravelBatch);

        $found = $this->repository->find('b-sync');
        $this->assertSame(10, $found->totalJobs);
        $this->assertSame(3, $found->pendingJobs);
        $this->assertSame(7, $found->processedJobs);
        $this->assertSame(2, $found->failedJobs);
    }

    public function testPaginateEmptyResult(): void
    {
        $result = $this->repository->paginate([], 1, 25);

        $this->assertSame(0, $result['total']);
        $this->assertSame(1, $result['last_page']);
        $this->assertNull($result['from']);
        $this->assertNull($result['to']);
    }

    public function testPaginateLastPage(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->store($this->createBatch(['id' => "b-lp-{$i}"]));
        }

        $lastPage = $this->repository->paginate([], 2, 3);

        $this->assertSame(5, $lastPage['total']);
        $this->assertSame(2, $lastPage['current_page']);
        $this->assertCount(2, $lastPage['data']);
        $this->assertNull($lastPage['next_page_url']);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS station_batches (
            id VARCHAR(255) PRIMARY KEY,
            name VARCHAR(255) NULL,
            queue VARCHAR(255) NOT NULL DEFAULT "default",
            connection VARCHAR(255) NULL,
            total_jobs INTEGER NOT NULL DEFAULT 0,
            pending_jobs INTEGER NOT NULL DEFAULT 0,
            processed_jobs INTEGER NOT NULL DEFAULT 0,
            failed_jobs INTEGER NOT NULL DEFAULT 0,
            failed_job_ids TEXT NULL,
            options TEXT NULL,
            allowed_failures INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT "pending",
            started_at TIMESTAMP NULL,
            finished_at TIMESTAMP NULL,
            cancelled_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )');
    }

    private function createBatch(array $overrides = []): Batch
    {
        $defaults = [
            'id' => 'batch-' . uniqid(),
            'totalJobs' => 10,
            'name' => 'Test Batch',
            'queue' => 'default',
            'allowedFailures' => 0,
            'options' => [],
            'connection' => null,
        ];

        $merged = array_merge($defaults, $overrides);

        $batch = Batch::create(
            id: $merged['id'],
            totalJobs: $merged['totalJobs'],
            name: $merged['name'],
            queue: $merged['queue'],
            allowedFailures: $merged['allowedFailures'],
            options: $merged['options'],
            connection: $merged['connection'],
        );

        if (isset($overrides['status'])) {
            $batch->status = $overrides['status'];
        }

        if (isset($overrides['processedJobs'])) {
            $batch->processedJobs = $overrides['processedJobs'];
        }

        if (isset($overrides['pendingJobs'])) {
            $batch->pendingJobs = $overrides['pendingJobs'];
        }

        if (isset($overrides['failedJobs'])) {
            $batch->failedJobs = $overrides['failedJobs'];
        }

        if (isset($overrides['failedJobIds'])) {
            $batch->failedJobIds = $overrides['failedJobIds'];
        }

        return $batch;
    }
}
