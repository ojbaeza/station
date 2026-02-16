<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Orchestra\Testbench\TestCase;
use Station\DTOs\MetricsSnapshot;
use Station\DTOs\QueueStats;
use Station\DTOs\TimeSeriesPoint;
use Station\Repositories\DatabaseMetricsRepository;
use Station\StationServiceProvider;

class DatabaseMetricsRepositoryTest extends TestCase
{
    private DatabaseMetricsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->repository = new DatabaseMetricsRepository(
            $this->app['db']->connection(),
            'station_',
        );
    }

    public function testRecordStoresMetrics(): void
    {
        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 2,
            'jobs_pending' => 5,
            'avg_processing_time' => 150,
            'avg_wait_time' => 25,
            'peak_memory' => 1024,
            'active_workers' => 3,
        ]);

        $this->assertDatabaseHas('station_metrics', [
            'queue' => 'default',
            'jobs_processed' => 10,
            'jobs_failed' => 2,
        ]);
    }

    public function testGetForRangeReturnsMetricsInRange(): void
    {
        // Record metrics at different times
        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 0,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ]);

        $start = CarbonImmutable::now()->subMinutes(5);
        $end = CarbonImmutable::now()->addMinute();

        $result = $this->repository->getForRange('default', $start, $end);

        $this->assertCount(1, $result);
        $this->assertSame(10, $result->first()['jobs_processed']);
    }

    public function testGetForRangeFiltersOutOfRange(): void
    {
        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 0,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ]);

        // Request range in the past
        $start = CarbonImmutable::now()->subHours(2);
        $end = CarbonImmutable::now()->subHour();

        $result = $this->repository->getForRange('default', $start, $end);

        $this->assertCount(0, $result);
    }

    public function testGetAggregatedReturnsCorrectData(): void
    {
        // Record multiple metrics
        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 2,
            'jobs_pending' => 5,
            'avg_processing_time' => 100,
            'avg_wait_time' => 20,
            'peak_memory' => 1024,
            'active_workers' => 2,
        ]);

        $this->repository->record('default', [
            'jobs_processed' => 20,
            'jobs_failed' => 3,
            'jobs_pending' => 8,
            'avg_processing_time' => 200,
            'avg_wait_time' => 30,
            'peak_memory' => 2048,
            'active_workers' => 3,
        ]);

        $result = $this->repository->getAggregated('default', 60);

        $this->assertSame(30, $result->jobs_processed);
        $this->assertSame(5, $result->jobs_failed);
        $this->assertSame(150.0, $result->avg_processing_time);
        $this->assertSame(25.0, $result->avg_wait_time);
    }

    public function testGetAggregatedCalculatesFailureRate(): void
    {
        $this->repository->record('default', [
            'jobs_processed' => 80,
            'jobs_failed' => 20,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ]);

        $result = $this->repository->getAggregated('default', 60);

        // 20 failed out of 100 total = 20%
        $this->assertSame(20.0, $result->failure_rate);
    }

    public function testGetAggregatedReturnsZeroForNoData(): void
    {
        $result = $this->repository->getAggregated('nonexistent', 60);

        $this->assertSame(0, $result->jobs_processed);
        $this->assertSame(0, $result->jobs_failed);
        $this->assertSame(0.0, $result->failure_rate);
    }

    public function testGetSnapshotReturnsCurrentState(): void
    {
        $this->repository->record('default', [
            'jobs_processed' => 50,
            'jobs_failed' => 5,
            'jobs_pending' => 10,
            'avg_processing_time' => 200,
            'avg_wait_time' => 30,
            'peak_memory' => 2048,
            'active_workers' => 4,
        ]);

        $result = $this->repository->getSnapshot();

        $this->assertInstanceOf(MetricsSnapshot::class, $result);
        $this->assertIsFloat($result->jobs_per_minute);
        $this->assertIsInt($result->jobs_processed_last_hour);
        $this->assertIsInt($result->failed_jobs);
        $this->assertIsFloat($result->failed_rate_percent);
        $this->assertIsInt($result->average_processing_time_ms);
        $this->assertIsInt($result->active_workers);
        $this->assertIsInt($result->pending_jobs);
    }

    public function testGetQueueStatsReturnsStatsPerQueue(): void
    {
        // Create a job to get queues
        $this->app['db']->table('station_jobs')->insert([
            'id' => 'job-1',
            'queue' => 'default',
            'job_class' => 'TestJob',
            'payload' => '{}',
            'status' => 'pending',
            'attempts' => 0,
            'max_tries' => 3,
            'timeout' => 60,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 0,
            'jobs_pending' => 1,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 2,
        ]);

        $result = $this->repository->getQueueStats();

        $this->assertArrayHasKey('default', $result);
        $this->assertInstanceOf(QueueStats::class, $result['default']);
        $this->assertIsInt($result['default']->size);
        $this->assertIsBool($result['default']->paused);
        $this->assertIsInt($result['default']->workers);
        $this->assertIsFloat($result['default']->throughput);
    }

    public function testGetRecentReturnsRecentMetrics(): void
    {
        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 0,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ]);

        $result = $this->repository->getRecent('default', 5);

        $this->assertCount(1, $result);
        $this->assertSame(10, $result[0]['jobs_processed']);
    }

    public function testPruneRemovesOldMetrics(): void
    {
        // Insert old metric directly
        $this->app['db']->table('station_metrics')->insert([
            'queue' => 'default',
            'jobs_processed' => 10,
            'jobs_failed' => 0,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
            'recorded_at' => CarbonImmutable::now()->subHours(48)->toDateTimeString(),
        ]);

        // Insert recent metric
        $this->repository->record('default', [
            'jobs_processed' => 20,
            'jobs_failed' => 0,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ]);

        $deleted = $this->repository->prune(24);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('station_metrics', ['jobs_processed' => 20]);
        $this->assertDatabaseMissing('station_metrics', ['jobs_processed' => 10]);
    }

    public function testGetForRangeFiltersByQueue(): void
    {
        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 0,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ]);

        $this->repository->record('emails', [
            'jobs_processed' => 5,
            'jobs_failed' => 1,
            'jobs_pending' => 0,
            'avg_processing_time' => 50,
            'avg_wait_time' => 5,
            'peak_memory' => 512,
            'active_workers' => 1,
        ]);

        $start = CarbonImmutable::now()->subMinutes(5);
        $end = CarbonImmutable::now()->addMinute();

        $defaultResult = $this->repository->getForRange('default', $start, $end);
        $emailsResult = $this->repository->getForRange('emails', $start, $end);

        $this->assertCount(1, $defaultResult);
        $this->assertSame(10, $defaultResult->first()['jobs_processed']);

        $this->assertCount(1, $emailsResult);
        $this->assertSame(5, $emailsResult->first()['jobs_processed']);
    }

    public function testGetTimeSeriesReturnsBucketedData(): void
    {
        // Insert metrics at different times within the last hour
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_metrics')->insert([
            [
                'queue' => 'default',
                'jobs_processed' => 10,
                'jobs_failed' => 1,
                'jobs_pending' => 5,
                'avg_processing_time' => 100,
                'avg_wait_time' => 20,
                'peak_memory' => 1024,
                'active_workers' => 2,
                'recorded_at' => $now->subMinutes(30)->toDateTimeString(),
            ],
            [
                'queue' => 'default',
                'jobs_processed' => 15,
                'jobs_failed' => 2,
                'jobs_pending' => 3,
                'avg_processing_time' => 200,
                'avg_wait_time' => 30,
                'peak_memory' => 2048,
                'active_workers' => 3,
                'recorded_at' => $now->subMinutes(29)->toDateTimeString(),
            ],
            [
                'queue' => 'default',
                'jobs_processed' => 8,
                'jobs_failed' => 0,
                'jobs_pending' => 1,
                'avg_processing_time' => 80,
                'avg_wait_time' => 10,
                'peak_memory' => 512,
                'active_workers' => 1,
                'recorded_at' => $now->subMinutes(5)->toDateTimeString(),
            ],
        ]);

        $result = $this->repository->getTimeSeries(60, 10);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Each entry should be a TimeSeriesPoint DTO
        $first = $result[0];
        $this->assertInstanceOf(TimeSeriesPoint::class, $first);

        // Values should be integers/floats
        $this->assertIsInt($first->jobs_processed);
        $this->assertIsInt($first->jobs_failed);
        $this->assertIsFloat($first->avg_wait_time);
        $this->assertIsFloat($first->avg_processing_time);
    }

    public function testGetTimeSeriesReturnsZeroBucketsForNoData(): void
    {
        $result = $this->repository->getTimeSeries(60, 10);

        $this->assertIsArray($result);
        // Backfilled buckets should all have zero values
        foreach ($result as $point) {
            $this->assertInstanceOf(TimeSeriesPoint::class, $point);
            $this->assertSame(0, $point->jobs_processed);
            $this->assertSame(0, $point->jobs_failed);
        }
    }

    public function testGetTimeSeriesAggregatesAcrossQueues(): void
    {
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_metrics')->insert([
            [
                'queue' => 'default',
                'jobs_processed' => 10,
                'jobs_failed' => 1,
                'jobs_pending' => 0,
                'avg_processing_time' => 100,
                'avg_wait_time' => 20,
                'peak_memory' => 1024,
                'active_workers' => 1,
                'recorded_at' => $now->subMinutes(2)->toDateTimeString(),
            ],
            [
                'queue' => 'emails',
                'jobs_processed' => 5,
                'jobs_failed' => 0,
                'jobs_pending' => 0,
                'avg_processing_time' => 50,
                'avg_wait_time' => 10,
                'peak_memory' => 512,
                'active_workers' => 1,
                'recorded_at' => $now->subMinutes(2)->toDateTimeString(),
            ],
        ]);

        // With 1 bucket for 5 minutes, both should be in the same bucket
        $result = $this->repository->getTimeSeries(5, 1);

        $this->assertNotEmpty($result);
        // Find the bucket that contains actual data (backfill may add zero-value buckets)
        $nonZero = array_values(array_filter($result, static fn($p) => $p->jobs_processed > 0));
        $this->assertNotEmpty($nonZero, 'Should have at least one bucket with data');
        // Both queues' data should be summed
        $total = $nonZero[0];
        $this->assertSame(15, $total->jobs_processed);
        $this->assertSame(1, $total->jobs_failed);
    }

    public function testGetTimeSeriesOrdersByTimestamp(): void
    {
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_metrics')->insert([
            [
                'queue' => 'default',
                'jobs_processed' => 5,
                'jobs_failed' => 0,
                'jobs_pending' => 0,
                'avg_processing_time' => 50,
                'avg_wait_time' => 10,
                'peak_memory' => 512,
                'active_workers' => 1,
                'recorded_at' => $now->subMinutes(50)->toDateTimeString(),
            ],
            [
                'queue' => 'default',
                'jobs_processed' => 10,
                'jobs_failed' => 1,
                'jobs_pending' => 0,
                'avg_processing_time' => 100,
                'avg_wait_time' => 20,
                'peak_memory' => 1024,
                'active_workers' => 2,
                'recorded_at' => $now->subMinutes(10)->toDateTimeString(),
            ],
        ]);

        $result = $this->repository->getTimeSeries(60, 30);

        $this->assertGreaterThanOrEqual(2, \count($result));

        // Verify chronological order
        $timestamps = array_map(static fn($point) => $point->timestamp, $result);
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
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

        $db->statement('CREATE TABLE IF NOT EXISTS station_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(50) NULL,
            jobs_processed INTEGER NOT NULL DEFAULT 0,
            jobs_failed INTEGER NOT NULL DEFAULT 0,
            jobs_pending INTEGER NOT NULL DEFAULT 0,
            avg_processing_time REAL NOT NULL DEFAULT 0,
            avg_wait_time REAL NOT NULL DEFAULT 0,
            peak_memory INTEGER NOT NULL DEFAULT 0,
            active_workers INTEGER NOT NULL DEFAULT 0,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_jobs (
            id VARCHAR(255) PRIMARY KEY,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(50) NULL,
            job_class VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT "pending",
            attempts INTEGER NOT NULL DEFAULT 0,
            max_tries INTEGER NOT NULL DEFAULT 3,
            timeout INTEGER NOT NULL DEFAULT 60,
            priority INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_workers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue VARCHAR(255) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT "idle",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_queue_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(50) NULL,
            paused INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
