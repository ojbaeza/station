<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Orchestra\Testbench\TestCase;
use Station\Repositories\DatabaseWorkerRepository;
use Station\StationServiceProvider;

class DatabaseWorkerRepositoryTest extends TestCase
{
    private DatabaseWorkerRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
        $this->repository = new DatabaseWorkerRepository(
            $this->app['db']->connection(),
            'station_',
        );
    }

    public function testRegisterCreatesWorker(): void
    {
        $this->repository->register(
            'worker-1',
            'supervisor-1',
            'localhost',
            12345,
            'default',
        );

        $this->assertDatabaseHas('station_workers', [
            'id' => 'worker-1',
            'supervisor_id' => 'supervisor-1',
            'hostname' => 'localhost',
            'pid' => 12345,
            'queue' => 'default',
            'status' => 'running',
        ]);
    }

    public function testHeartbeatUpdatesWorker(): void
    {
        $this->repository->register(
            'worker-1',
            'supervisor-1',
            'localhost',
            12345,
            'default',
        );

        $this->repository->heartbeat('worker-1', 2048000, 'job-123');

        $this->assertDatabaseHas('station_workers', [
            'id' => 'worker-1',
            'memory_usage' => 2048000,
            'current_job_id' => 'job-123',
        ]);
    }

    public function testUpdateStatusChangesWorkerStatus(): void
    {
        $this->repository->register(
            'worker-1',
            'supervisor-1',
            'localhost',
            12345,
            'default',
        );

        $this->repository->updateStatus('worker-1', 'processing');

        $this->assertDatabaseHas('station_workers', [
            'id' => 'worker-1',
            'status' => 'processing',
        ]);
    }

    public function testIncrementJobsProcessed(): void
    {
        $this->repository->register(
            'worker-1',
            'supervisor-1',
            'localhost',
            12345,
            'default',
        );

        $this->repository->incrementJobsProcessed('worker-1');
        $this->repository->incrementJobsProcessed('worker-1');

        $this->assertDatabaseHas('station_workers', [
            'id' => 'worker-1',
            'jobs_processed' => 2,
        ]);
    }

    public function testRemoveDeletesWorker(): void
    {
        $this->repository->register(
            'worker-1',
            'supervisor-1',
            'localhost',
            12345,
            'default',
        );

        $this->repository->remove('worker-1');

        $this->assertDatabaseMissing('station_workers', [
            'id' => 'worker-1',
        ]);
    }

    public function testGetBySupervisorReturnsWorkers(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');
        $this->repository->register('worker-2', 'supervisor-1', 'localhost', 1002, 'default');
        $this->repository->register('worker-3', 'supervisor-2', 'localhost', 1003, 'default');

        $result = $this->repository->getBySupervisor('supervisor-1');

        $this->assertCount(2, $result);
    }

    public function testGetActiveReturnsOnlyActiveWorkers(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');
        $this->repository->register('worker-2', 'supervisor-1', 'localhost', 1002, 'default');
        $this->repository->updateStatus('worker-2', 'stopped');

        $result = $this->repository->getActive();

        $this->assertCount(1, $result);
        $this->assertSame('worker-1', $result->first()['id']);
    }

    public function testGetByQueueReturnsWorkersForQueue(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');
        $this->repository->register('worker-2', 'supervisor-1', 'localhost', 1002, 'emails');
        $this->repository->register('worker-3', 'supervisor-1', 'localhost', 1003, 'default');

        $result = $this->repository->getByQueue('default');

        $this->assertCount(2, $result);
    }

    public function testFindReturnsWorkerById(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 12345, 'default');

        $result = $this->repository->find('worker-1');

        $this->assertNotNull($result);
        $this->assertSame('worker-1', $result['id']);
        $this->assertSame('supervisor-1', $result['supervisor_id']);
    }

    public function testFindReturnsNullForNonexistentWorker(): void
    {
        $result = $this->repository->find('nonexistent');

        $this->assertNull($result);
    }

    public function testGetStaleReturnsStaleWorkers(): void
    {
        // Register a worker
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');

        // Manually update the heartbeat to be old
        $this->app['db']->table('station_workers')
            ->where('id', 'worker-1')
            ->update([
                'last_heartbeat_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
            ]);

        $result = $this->repository->getStale(60); // 60 second timeout

        $this->assertCount(1, $result);
        $this->assertSame('worker-1', $result->first()['id']);
    }

    public function testGetStaleExcludesRecentWorkers(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');

        $result = $this->repository->getStale(60);

        $this->assertCount(0, $result);
    }

    public function testPruneStaleRemovesStaleWorkers(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');
        $this->repository->register('worker-2', 'supervisor-1', 'localhost', 1002, 'default');

        // Make worker-1 stale
        $this->app['db']->table('station_workers')
            ->where('id', 'worker-1')
            ->update([
                'last_heartbeat_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
            ]);

        $deleted = $this->repository->pruneStale(60);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('station_workers', ['id' => 'worker-1']);
        $this->assertDatabaseHas('station_workers', ['id' => 'worker-2']);
    }

    public function testMarkStoppedSetsStatusToStopped(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 12345, 'default');

        $this->repository->markStopped('worker-1');

        $this->assertDatabaseHas('station_workers', [
            'id' => 'worker-1',
            'status' => 'stopped',
        ]);
    }

    public function testGetActiveExcludesStoppedWorkers(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');
        $this->repository->register('worker-2', 'supervisor-1', 'localhost', 1002, 'default');

        $this->repository->markStopped('worker-1');

        $result = $this->repository->getActive();

        $this->assertCount(1, $result);
        $this->assertSame('worker-2', $result->first()['id']);
    }

    public function testGetByQueueExcludesStoppedWorkers(): void
    {
        $this->repository->register('worker-1', 'supervisor-1', 'localhost', 1001, 'default');
        $this->repository->register('worker-2', 'supervisor-1', 'localhost', 1002, 'default');

        $this->repository->markStopped('worker-1');

        $result = $this->repository->getByQueue('default');

        $this->assertCount(1, $result);
        $this->assertSame('worker-2', $result->first()['id']);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }
}
