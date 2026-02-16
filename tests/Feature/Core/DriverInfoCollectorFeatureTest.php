<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Core;

use Carbon\CarbonImmutable;
use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\DriverInterface;
use Station\Core\DriverInfoCollector;
use Station\StationServiceProvider;

/**
 * Feature tests for DriverInfoCollector covering DB-dependent methods:
 * - recordSnapshots
 * - getTimeSeries
 * - pruneSnapshots
 */
class DriverInfoCollectorFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRecordSnapshotsInsertsDriverStats(): void
    {
        $mockDriver = Mockery::mock(DriverInterface::class);
        $mockDriver->shouldReceive('getDriverInfo')
            ->with('default')
            ->andReturn([
                'driver' => 'rabbitmq',
                'size' => 42,
                'memory_used' => 2048,
                'consumers' => 3,
                'publish_rate' => 10.5,
            ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('rabbitmq')
            ->andReturn($mockDriver);

        config(['queue.connections' => [
            'rabbitmq' => ['driver' => 'rabbitmq', 'queue' => 'default'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $collector->recordSnapshots();

        $this->assertDatabaseHas('station_driver_snapshots', [
            'connection' => 'rabbitmq',
            'queue_size' => 42,
            'memory_bytes' => 2048,
            'consumers' => 3,
        ]);
    }

    public function testRecordSnapshotsSkipsErroredConnections(): void
    {
        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('redis')
            ->andThrow(new RuntimeException('Connection refused'));

        config(['queue.connections' => [
            'redis' => ['driver' => 'redis', 'queue' => 'default'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $collector->recordSnapshots();

        $count = $this->app['db']->table('station_driver_snapshots')->count();
        $this->assertSame(0, $count);
    }

    public function testRecordSnapshotsUsesMemoryFallbackKeys(): void
    {
        $mockDriver = Mockery::mock(DriverInterface::class);
        $mockDriver->shouldReceive('getDriverInfo')
            ->with('default')
            ->andReturn([
                'driver' => 'redis',
                'size' => 10,
                'memory' => 4096, // Uses 'memory' key instead of 'memory_used'
                'connected_clients' => 5, // Uses 'connected_clients' instead of 'consumers'
                'ops_per_sec' => 25.0, // Uses 'ops_per_sec' instead of 'publish_rate'
            ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('redis')
            ->andReturn($mockDriver);

        config(['queue.connections' => [
            'redis' => ['driver' => 'redis', 'queue' => 'default'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $collector->recordSnapshots();

        $this->assertDatabaseHas('station_driver_snapshots', [
            'connection' => 'redis',
            'queue_size' => 10,
            'memory_bytes' => 4096,
            'consumers' => 5,
        ]);
    }

    public function testRecordSnapshotsUsesWatchersAsFallback(): void
    {
        $mockDriver = Mockery::mock(DriverInterface::class);
        $mockDriver->shouldReceive('getDriverInfo')
            ->with('default')
            ->andReturn([
                'driver' => 'beanstalkd',
                'size' => 3,
                'watchers' => 2,
            ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('beanstalkd')
            ->andReturn($mockDriver);

        config(['queue.connections' => [
            'beanstalkd' => ['driver' => 'beanstalkd', 'queue' => 'default'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $collector->recordSnapshots();

        $this->assertDatabaseHas('station_driver_snapshots', [
            'connection' => 'beanstalkd',
            'consumers' => 2,
        ]);
    }

    public function testGetTimeSeriesReturnsDataForConnection(): void
    {
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_driver_snapshots')->insert([
            [
                'connection' => 'rabbitmq',
                'queue_size' => 10,
                'memory_bytes' => 2048,
                'consumers' => 3,
                'ops_rate' => 5.5,
                'recorded_at' => $now->subMinutes(10)->toDateTimeString(),
            ],
            [
                'connection' => 'rabbitmq',
                'queue_size' => 20,
                'memory_bytes' => 4096,
                'consumers' => 4,
                'ops_rate' => 10.0,
                'recorded_at' => $now->subMinutes(5)->toDateTimeString(),
            ],
            [
                'connection' => 'redis', // Different connection - should be excluded
                'queue_size' => 100,
                'memory_bytes' => 8192,
                'consumers' => 1,
                'ops_rate' => 50.0,
                'recorded_at' => $now->subMinutes(5)->toDateTimeString(),
            ],
        ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $collector = new DriverInfoCollector($queueManager);

        $result = $collector->getTimeSeries('rabbitmq', '1h');

        $this->assertCount(2, $result['queue_size']);
        $this->assertCount(2, $result['memory_bytes']);
        $this->assertCount(2, $result['consumers']);
        $this->assertCount(2, $result['ops_rate']);

        $this->assertSame(10, $result['queue_size'][0]['value']);
        $this->assertSame(20, $result['queue_size'][1]['value']);
        $this->assertSame(2048, $result['memory_bytes'][0]['value']);
        $this->assertSame(4096, $result['memory_bytes'][1]['value']);
        $this->assertSame(3, $result['consumers'][0]['value']);
        $this->assertSame(4, $result['consumers'][1]['value']);
        $this->assertSame(5.5, $result['ops_rate'][0]['value']);
        $this->assertSame(10.0, $result['ops_rate'][1]['value']);
    }

    public function testGetTimeSeriesWithDifferentPeriods(): void
    {
        $now = CarbonImmutable::now();

        // Insert a record from 30 minutes ago
        $this->app['db']->table('station_driver_snapshots')->insert([
            'connection' => 'rabbitmq',
            'queue_size' => 10,
            'memory_bytes' => 2048,
            'consumers' => 3,
            'ops_rate' => 5.5,
            'recorded_at' => $now->subMinutes(30)->toDateTimeString(),
        ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $collector = new DriverInfoCollector($queueManager);

        // 5m period should NOT include a 30-minute-old record
        $result5m = $collector->getTimeSeries('rabbitmq', '5m');
        $this->assertCount(0, $result5m['queue_size']);

        // 1h period SHOULD include it
        $result1h = $collector->getTimeSeries('rabbitmq', '1h');
        $this->assertCount(1, $result1h['queue_size']);

        // 6h period SHOULD include it
        $result6h = $collector->getTimeSeries('rabbitmq', '6h');
        $this->assertCount(1, $result6h['queue_size']);

        // 24h period SHOULD include it
        $result24h = $collector->getTimeSeries('rabbitmq', '24h');
        $this->assertCount(1, $result24h['queue_size']);
    }

    public function testGetTimeSeriesDefaultPeriodIsOneHour(): void
    {
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_driver_snapshots')->insert([
            'connection' => 'rabbitmq',
            'queue_size' => 10,
            'memory_bytes' => 2048,
            'consumers' => 3,
            'ops_rate' => 5.5,
            'recorded_at' => $now->subMinutes(30)->toDateTimeString(),
        ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $collector = new DriverInfoCollector($queueManager);

        // 'invalid' period should default to 1h
        $result = $collector->getTimeSeries('rabbitmq', 'invalid');
        $this->assertCount(1, $result['queue_size']);
    }

    public function testGetTimeSeriesReturnsEmptyForUnknownConnection(): void
    {
        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $collector = new DriverInfoCollector($queueManager);

        $result = $collector->getTimeSeries('nonexistent', '1h');

        $this->assertCount(0, $result['queue_size']);
        $this->assertCount(0, $result['memory_bytes']);
        $this->assertCount(0, $result['consumers']);
        $this->assertCount(0, $result['ops_rate']);
    }

    public function testGetTimeSeriesIncludesTimeKey(): void
    {
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_driver_snapshots')->insert([
            'connection' => 'rabbitmq',
            'queue_size' => 10,
            'memory_bytes' => 2048,
            'consumers' => 3,
            'ops_rate' => 5.5,
            'recorded_at' => $now->subMinutes(5)->toDateTimeString(),
        ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $collector = new DriverInfoCollector($queueManager);

        $result = $collector->getTimeSeries('rabbitmq', '1h');

        $this->assertArrayHasKey('time', $result['queue_size'][0]);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $result['queue_size'][0]['time']);
    }

    public function testPruneSnapshotsDeletesOldRecords(): void
    {
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_driver_snapshots')->insert([
            [
                'connection' => 'rabbitmq',
                'queue_size' => 10,
                'memory_bytes' => 2048,
                'consumers' => 3,
                'ops_rate' => 5.5,
                'recorded_at' => $now->subDays(10)->toDateTimeString(),
            ],
            [
                'connection' => 'rabbitmq',
                'queue_size' => 20,
                'memory_bytes' => 4096,
                'consumers' => 4,
                'ops_rate' => 10.0,
                'recorded_at' => $now->subMinutes(5)->toDateTimeString(),
            ],
        ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $collector = new DriverInfoCollector($queueManager);

        $deleted = $collector->pruneSnapshots($now->subDays(7));

        $this->assertSame(1, $deleted);

        $remaining = $this->app['db']->table('station_driver_snapshots')->count();
        $this->assertSame(1, $remaining);
    }

    public function testPruneSnapshotsReturnsZeroWhenNothingToDelete(): void
    {
        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $collector = new DriverInfoCollector($queueManager);

        $deleted = $collector->pruneSnapshots(CarbonImmutable::now()->subDays(7));

        $this->assertSame(0, $deleted);
    }

    public function testRecordSnapshotsHandlesMultipleConnections(): void
    {
        $rabbitDriver = Mockery::mock(DriverInterface::class);
        $rabbitDriver->shouldReceive('getDriverInfo')
            ->with('default')
            ->andReturn([
                'driver' => 'rabbitmq',
                'size' => 42,
                'memory_used' => 2048,
                'consumers' => 3,
                'publish_rate' => 10.5,
            ]);

        $redisDriver = Mockery::mock(DriverInterface::class);
        $redisDriver->shouldReceive('getDriverInfo')
            ->with('jobs')
            ->andReturn([
                'driver' => 'redis',
                'size' => 100,
                'memory' => 8192,
                'connected_clients' => 5,
                'ops_per_sec' => 50.0,
            ]);

        $queueManager = Mockery::mock(LaravelQueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('rabbitmq')
            ->andReturn($rabbitDriver);
        $queueManager->shouldReceive('connection')
            ->with('redis')
            ->andReturn($redisDriver);

        config(['queue.connections' => [
            'rabbitmq' => ['driver' => 'rabbitmq', 'queue' => 'default'],
            'redis' => ['driver' => 'redis', 'queue' => 'jobs'],
            'sync' => ['driver' => 'sync'], // Should be skipped
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $collector->recordSnapshots();

        $count = $this->app['db']->table('station_driver_snapshots')->count();
        $this->assertSame(2, $count);

        $this->assertDatabaseHas('station_driver_snapshots', [
            'connection' => 'rabbitmq',
            'queue_size' => 42,
        ]);
        $this->assertDatabaseHas('station_driver_snapshots', [
            'connection' => 'redis',
            'queue_size' => 100,
        ]);
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

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    private function createTables(): void
    {
        $db = $this->app['db'];

        $db->statement('CREATE TABLE IF NOT EXISTS station_driver_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection VARCHAR(50) NOT NULL,
            queue_size INTEGER NOT NULL DEFAULT 0,
            memory_bytes INTEGER NOT NULL DEFAULT 0,
            consumers INTEGER NOT NULL DEFAULT 0,
            ops_rate REAL NOT NULL DEFAULT 0,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
