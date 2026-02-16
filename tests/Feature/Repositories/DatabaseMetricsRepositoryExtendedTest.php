<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Orchestra\Testbench\TestCase;
use Station\Repositories\DatabaseMetricsRepository;
use Station\StationServiceProvider;

/**
 * Extended tests for DatabaseMetricsRepository covering:
 * - recordBatch
 * - getForRange with connection filter
 * - getAggregated with connection filter
 * - getGlobalAggregated
 * - getTimeSeries with connection filter
 * - getAllRecent
 * - paginateAllRecent
 * - getRecent with connection filter
 * - getQueueStats with additional queues
 */
class DatabaseMetricsRepositoryExtendedTest extends TestCase
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

    public function testRecordBatchInsertsMultipleRows(): void
    {
        $entries = [
            [
                'queue' => 'default',
                'connection' => 'redis',
                'metrics' => [
                    'jobs_processed' => 10,
                    'jobs_failed' => 1,
                    'jobs_pending' => 5,
                    'avg_processing_time' => 100,
                    'avg_wait_time' => 20,
                    'peak_memory' => 1024,
                    'active_workers' => 2,
                ],
            ],
            [
                'queue' => 'emails',
                'connection' => 'rabbitmq',
                'metrics' => [
                    'jobs_processed' => 5,
                    'jobs_failed' => 0,
                    'jobs_pending' => 3,
                    'avg_processing_time' => 50,
                    'avg_wait_time' => 10,
                    'peak_memory' => 512,
                    'active_workers' => 1,
                ],
            ],
        ];

        $this->repository->recordBatch($entries);

        $this->assertDatabaseHas('station_metrics', ['queue' => 'default', 'jobs_processed' => 10]);
        $this->assertDatabaseHas('station_metrics', ['queue' => 'emails', 'jobs_processed' => 5]);
    }

    public function testRecordBatchWithEmptyArrayDoesNothing(): void
    {
        $this->repository->recordBatch([]);

        $count = $this->app['db']->table('station_metrics')->count();
        $this->assertSame(0, $count);
    }

    public function testRecordWithConnection(): void
    {
        $this->repository->record('default', [
            'jobs_processed' => 10,
            'jobs_failed' => 0,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ], 'redis');

        $this->assertDatabaseHas('station_metrics', [
            'queue' => 'default',
            'connection' => 'redis',
            'jobs_processed' => 10,
        ]);
    }

    public function testGetForRangeWithConnectionFilter(): void
    {
        $this->repository->record('default', $this->makeMetrics(10), 'redis');
        $this->repository->record('default', $this->makeMetrics(20), 'rabbitmq');

        $start = CarbonImmutable::now()->subMinutes(5);
        $end = CarbonImmutable::now()->addMinute();

        $result = $this->repository->getForRange('default', $start, $end, 'redis');

        $this->assertCount(1, $result);
        $this->assertSame(10, $result->first()['jobs_processed']);
    }

    public function testGetAggregatedWithConnectionFilter(): void
    {
        $this->repository->record('default', $this->makeMetrics(10, 1), 'redis');
        $this->repository->record('default', $this->makeMetrics(20, 3), 'rabbitmq');

        $result = $this->repository->getAggregated('default', 60, 'redis');

        $this->assertSame(10, $result->jobs_processed);
        $this->assertSame(1, $result->jobs_failed);
    }

    public function testGetGlobalAggregated(): void
    {
        $this->repository->record('default', $this->makeMetrics(10, 1));
        $this->repository->record('emails', $this->makeMetrics(20, 3));

        $result = $this->repository->getGlobalAggregated(60);

        $this->assertSame(30, $result->jobs_processed);
        $this->assertSame(4, $result->jobs_failed);
        $this->assertIsFloat($result->failure_rate);
        $this->assertNotNull($result->throughput);
    }

    public function testGetGlobalAggregatedWithConnectionFilter(): void
    {
        $this->repository->record('default', $this->makeMetrics(10, 1), 'redis');
        $this->repository->record('default', $this->makeMetrics(20, 3), 'rabbitmq');

        $result = $this->repository->getGlobalAggregated(60, 'redis');

        $this->assertSame(10, $result->jobs_processed);
        $this->assertSame(1, $result->jobs_failed);
    }

    public function testGetGlobalAggregatedCalculatesThroughput(): void
    {
        $this->repository->record('default', $this->makeMetrics(120, 0));

        $result = $this->repository->getGlobalAggregated(60);

        // 120 jobs over 60 minutes = 2 jobs/min
        $this->assertSame(2.0, $result->throughput);
    }

    public function testGetGlobalAggregatedWithZeroMinutes(): void
    {
        $result = $this->repository->getGlobalAggregated(0);

        $this->assertSame(0.0, $result->throughput);
    }

    public function testGetAllRecent(): void
    {
        $this->repository->record('default', $this->makeMetrics(10));
        $this->repository->record('emails', $this->makeMetrics(5));

        $result = $this->repository->getAllRecent(60);

        $this->assertCount(2, $result);
    }

    public function testGetAllRecentWithConnectionFilter(): void
    {
        $this->repository->record('default', $this->makeMetrics(10), 'redis');
        $this->repository->record('default', $this->makeMetrics(20), 'rabbitmq');

        $result = $this->repository->getAllRecent(60, 100, 'redis');

        $this->assertCount(1, $result);
        $this->assertSame(10, $result[0]['jobs_processed']);
    }

    public function testGetAllRecentRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->record('default', $this->makeMetrics($i + 1));
        }

        $result = $this->repository->getAllRecent(60, 3);

        $this->assertCount(3, $result);
    }

    public function testPaginateAllRecent(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->repository->record('default', $this->makeMetrics($i + 1));
        }

        $result = $this->repository->paginateAllRecent(60, 1, 3);

        $this->assertSame(10, $result->total);
        $this->assertSame(3, $result->per_page);
        $this->assertSame(1, $result->current_page);
        $this->assertSame(4, $result->last_page);
        $this->assertCount(3, $result->data);
        $this->assertIsArray($result->links);
        $this->assertNotNull($result->first_page_url);
        $this->assertNotNull($result->last_page_url);
    }

    public function testPaginateAllRecentWithConnectionFilter(): void
    {
        $this->repository->record('default', $this->makeMetrics(10), 'redis');
        $this->repository->record('default', $this->makeMetrics(20), 'rabbitmq');

        $result = $this->repository->paginateAllRecent(60, 1, 25, 'redis');

        $this->assertSame(1, $result->total);
    }

    public function testPaginateAllRecentEmptyResult(): void
    {
        $result = $this->repository->paginateAllRecent(60, 1, 25);

        $this->assertSame(0, $result->total);
        $this->assertSame(1, $result->last_page);
        $this->assertNull($result->from);
        $this->assertNull($result->to);
    }

    public function testGetRecentWithConnectionFilter(): void
    {
        $this->repository->record('default', $this->makeMetrics(10), 'redis');
        $this->repository->record('default', $this->makeMetrics(20), 'rabbitmq');

        $result = $this->repository->getRecent('default', 60, 'redis');

        $this->assertCount(1, $result);
        $this->assertSame(10, $result[0]['jobs_processed']);
    }

    public function testGetTimeSeriesWithConnectionFilter(): void
    {
        $now = CarbonImmutable::now();

        $this->app['db']->table('station_metrics')->insert([
            [
                'queue' => 'default',
                'connection' => 'redis',
                'jobs_processed' => 10,
                'jobs_failed' => 1,
                'jobs_pending' => 0,
                'avg_processing_time' => 100,
                'avg_wait_time' => 20,
                'peak_memory' => 1024,
                'active_workers' => 1,
                'recorded_at' => $now->subMinutes(5)->toDateTimeString(),
            ],
            [
                'queue' => 'default',
                'connection' => 'rabbitmq',
                'jobs_processed' => 20,
                'jobs_failed' => 2,
                'jobs_pending' => 0,
                'avg_processing_time' => 200,
                'avg_wait_time' => 30,
                'peak_memory' => 2048,
                'active_workers' => 2,
                'recorded_at' => $now->subMinutes(5)->toDateTimeString(),
            ],
        ]);

        $result = $this->repository->getTimeSeries(60, 10, 'redis');

        $this->assertNotEmpty($result);
        // Find the bucket that contains actual data (backfill may add zero-value buckets)
        $nonZero = array_values(array_filter($result, static fn($p) => $p->jobs_processed > 0));
        $this->assertNotEmpty($nonZero, 'Should have at least one bucket with redis data');
        // Should only include redis data
        $total = $nonZero[0];
        $this->assertSame(10, $total->jobs_processed);
        $this->assertSame(1, $total->jobs_failed);
    }

    public function testGetQueueStatsWithAdditionalQueues(): void
    {
        // No jobs exist for 'notifications' queue, but it should appear in stats via additionalQueues
        $this->repository->record('default', $this->makeMetrics(10));

        $result = $this->repository->getQueueStats(['notifications']);

        // Should include both discovered queues and additional queues
        $this->assertArrayHasKey('notifications', $result);
        $this->assertSame(0, $result['notifications']->size);
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

    private function makeMetrics(int $processed = 10, int $failed = 0): array
    {
        return [
            'jobs_processed' => $processed,
            'jobs_failed' => $failed,
            'jobs_pending' => 0,
            'avg_processing_time' => 100,
            'avg_wait_time' => 10,
            'peak_memory' => 1024,
            'active_workers' => 1,
        ];
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
