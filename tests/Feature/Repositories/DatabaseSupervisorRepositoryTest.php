<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Orchestra\Testbench\TestCase;
use Station\Repositories\DatabaseSupervisorRepository;
use Station\StationServiceProvider;

class DatabaseSupervisorRepositoryTest extends TestCase
{
    private DatabaseSupervisorRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
        $this->repository = new DatabaseSupervisorRepository(
            $this->app['db']->connection(),
            'station_',
        );
    }

    public function testRegisterCreatesSupervisor(): void
    {
        $this->repository->register(
            'supervisor-1',
            'main',
            'localhost',
            12345,
            ['default', 'emails'],
            ['memory' => 128, 'timeout' => 60],
        );

        $this->assertDatabaseHas('station_supervisors', [
            'id' => 'supervisor-1',
            'name' => 'main',
            'hostname' => 'localhost',
            'pid' => 12345,
            'status' => 'running',
        ]);
    }

    public function testHeartbeatUpdatesSupervisor(): void
    {
        $this->repository->register(
            'supervisor-1',
            'main',
            'localhost',
            12345,
            ['default'],
            [],
        );

        $this->repository->heartbeat('supervisor-1', 100);

        $this->assertDatabaseHas('station_supervisors', [
            'id' => 'supervisor-1',
            'jobs_processed' => 100,
        ]);
    }

    public function testUpdateStatusChangesStatus(): void
    {
        $this->repository->register(
            'supervisor-1',
            'main',
            'localhost',
            12345,
            ['default'],
            [],
        );

        $this->repository->updateStatus('supervisor-1', 'paused');

        $this->assertDatabaseHas('station_supervisors', [
            'id' => 'supervisor-1',
            'status' => 'paused',
        ]);
    }

    public function testRemoveDeletesSupervisor(): void
    {
        $this->repository->register(
            'supervisor-1',
            'main',
            'localhost',
            12345,
            ['default'],
            [],
        );

        $this->repository->remove('supervisor-1');

        $this->assertDatabaseMissing('station_supervisors', [
            'id' => 'supervisor-1',
        ]);
    }

    public function testGetActiveReturnsActiveSupervisors(): void
    {
        $this->repository->register('supervisor-1', 'main', 'localhost', 1001, ['default'], []);
        $this->repository->register('supervisor-2', 'main', 'localhost', 1002, ['default'], []);
        $this->repository->updateStatus('supervisor-2', 'terminated');

        $result = $this->repository->getActive();

        $this->assertCount(1, $result);
        $this->assertSame('supervisor-1', $result->first()['id']);
    }

    public function testGetActiveIncludesPausedSupervisors(): void
    {
        $this->repository->register('supervisor-1', 'main', 'localhost', 1001, ['default'], []);
        $this->repository->register('supervisor-2', 'main', 'localhost', 1002, ['default'], []);
        $this->repository->updateStatus('supervisor-2', 'paused');

        $result = $this->repository->getActive();

        $this->assertCount(2, $result);
    }

    public function testFindReturnsSupervisorById(): void
    {
        $this->repository->register(
            'supervisor-1',
            'main',
            'localhost',
            12345,
            ['default'],
            ['memory' => 128],
        );

        $result = $this->repository->find('supervisor-1');

        $this->assertNotNull($result);
        $this->assertSame('supervisor-1', $result['id']);
        $this->assertSame('main', $result['name']);
    }

    public function testFindReturnsNullForNonexistentSupervisor(): void
    {
        $result = $this->repository->find('nonexistent');

        $this->assertNull($result);
    }

    public function testGetStaleReturnsStaleSupervisors(): void
    {
        $this->repository->register('supervisor-1', 'main', 'localhost', 1001, ['default'], []);

        // Manually update heartbeat to be old
        $this->app['db']->table('station_supervisors')
            ->where('id', 'supervisor-1')
            ->update([
                'last_heartbeat_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
            ]);

        $result = $this->repository->getStale(60); // 60 second timeout

        $this->assertCount(1, $result);
        $this->assertSame('supervisor-1', $result->first()['id']);
    }

    public function testGetStaleExcludesRecentSupervisors(): void
    {
        $this->repository->register('supervisor-1', 'main', 'localhost', 1001, ['default'], []);

        $result = $this->repository->getStale(60);

        $this->assertCount(0, $result);
    }

    public function testPruneStaleRemovesStaleSupervisors(): void
    {
        $this->repository->register('supervisor-1', 'main', 'localhost', 1001, ['default'], []);
        $this->repository->register('supervisor-2', 'main', 'localhost', 1002, ['default'], []);

        // Make supervisor-1 stale
        $this->app['db']->table('station_supervisors')
            ->where('id', 'supervisor-1')
            ->update([
                'last_heartbeat_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
            ]);

        $deleted = $this->repository->pruneStale(60);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('station_supervisors', ['id' => 'supervisor-1']);
        $this->assertDatabaseHas('station_supervisors', ['id' => 'supervisor-2']);
    }

    public function testMarkTerminatedSetsStatusToTerminated(): void
    {
        $this->repository->register('supervisor-1', 'main', 'localhost', 12345, ['default'], []);

        $this->repository->markTerminated('supervisor-1');

        $this->assertDatabaseHas('station_supervisors', [
            'id' => 'supervisor-1',
            'status' => 'terminated',
        ]);
    }

    public function testGetStaleExcludesTerminatedSupervisors(): void
    {
        $this->repository->register('supervisor-1', 'main', 'localhost', 1001, ['default'], []);

        // Make it stale
        $this->app['db']->table('station_supervisors')
            ->where('id', 'supervisor-1')
            ->update([
                'last_heartbeat_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
            ]);

        // But mark as terminated
        $this->repository->markTerminated('supervisor-1');

        $result = $this->repository->getStale(60);

        $this->assertCount(0, $result);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }
}
