<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Station\Core\Job;
use Station\Enums\BatchStatus;
use Station\Enums\JobStatus;
use Station\Repositories\DatabaseBatchRepository;
use Station\Repositories\DatabaseJobRepository;
use Station\Repositories\DatabaseMetricsRepository;
use Station\StationServiceProvider;

/**
 * Tests for the 5 performance optimizations:
 *
 * Fix 1: trackFailed() context param eliminates SELECT round-trip
 * Fix 2: MetricsRepository::recordBatch() uses single bulk INSERT
 * Fix 4: BatchRepository::incrementProcessed/incrementFailed return pending count
 */
class PerformanceOptimizationsTest extends TestCase
{
    private DatabaseJobRepository $jobRepository;

    private DatabaseBatchRepository $batchRepository;

    private DatabaseMetricsRepository $metricsRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        $connection = DB::connection();
        $this->jobRepository = new DatabaseJobRepository($connection, 'station_');
        $this->batchRepository = new DatabaseBatchRepository($connection, 'station_');
        $this->metricsRepository = new DatabaseMetricsRepository($connection, 'station_');
    }

    // ──────────────────────────────────────────────────────────────
    // Fix 1: trackFailed() with context — eliminates SELECT
    // ──────────────────────────────────────────────────────────────

    public function testTrackFailedWithContextSkipsSelectAndRecordsFailure(): void
    {
        $this->createJob('ctx-job-1', 'high', 'App\\Jobs\\EmailJob');

        $context = [
            'job_class' => 'App\\Jobs\\EmailJob',
            'queue' => 'high',
            'connection' => 'redis',
            'payload' => '{"data":"test"}',
            'attempts' => 3,
            'batch_id' => 'batch-abc',
            'tags' => ['important', 'email'],
        ];

        $this->jobRepository->trackFailed('ctx-job-1', 'RuntimeException: timeout', $context);

        // Job status updated
        $job = $this->jobRepository->find('ctx-job-1');
        $this->assertSame(JobStatus::Failed->value, $job->status);
        $this->assertNotNull($job->completedAt);

        // Failed record uses context data (not re-read from station_jobs)
        $failed = DB::table('station_failed_jobs')->where('original_id', 'ctx-job-1')->first();
        $this->assertNotNull($failed);
        $this->assertSame('App\\Jobs\\EmailJob', $failed->job_class);
        $this->assertSame('high', $failed->queue);
        $this->assertSame('redis', $failed->connection);
        $this->assertSame('{"data":"test"}', $failed->payload);
        $this->assertSame(3, $failed->attempts);
        $this->assertSame('batch-abc', $failed->batch_id);
        $this->assertSame('RuntimeException: timeout', $failed->exception);
        $this->assertSame('["important","email"]', $failed->tags);
    }

    public function testTrackFailedWithContextUsesDefaults(): void
    {
        $this->createJob('ctx-job-2', 'default', 'App\\Jobs\\TestJob');

        // Partial context — missing fields should use defaults
        $context = [
            'job_class' => 'App\\Jobs\\TestJob',
        ];

        $this->jobRepository->trackFailed('ctx-job-2', 'Error', $context);

        $failed = DB::table('station_failed_jobs')->where('original_id', 'ctx-job-2')->first();
        $this->assertNotNull($failed);
        $this->assertSame('App\\Jobs\\TestJob', $failed->job_class);
        $this->assertSame('default', $failed->queue);  // default fallback
        $this->assertNull($failed->connection);          // default null
        $this->assertSame('{}', $failed->payload);       // default empty JSON
        $this->assertSame(1, $failed->attempts);          // default 1
        $this->assertNull($failed->batch_id);            // default null
        $this->assertSame('[]', $failed->tags);           // default empty array
    }

    public function testTrackFailedWithoutContextFallsBackToSelect(): void
    {
        $this->createJob('fallback-job', 'emails', 'App\\Jobs\\NotificationJob');

        // No context = old behavior (SELECT from station_jobs)
        $this->jobRepository->trackFailed('fallback-job', 'Database error');

        $failed = DB::table('station_failed_jobs')->where('original_id', 'fallback-job')->first();
        $this->assertNotNull($failed);
        $this->assertSame('App\\Jobs\\NotificationJob', $failed->job_class);
        $this->assertSame('emails', $failed->queue);
        $this->assertSame('Database error', $failed->exception);
    }

    public function testTrackFailedWithEmptyContextFallsBackToSelect(): void
    {
        $this->createJob('empty-ctx-job', 'default', 'App\\Jobs\\TestJob');

        // Empty array = fallback behavior (same as no context)
        $this->jobRepository->trackFailed('empty-ctx-job', 'Error', []);

        $failed = DB::table('station_failed_jobs')->where('original_id', 'empty-ctx-job')->first();
        $this->assertNotNull($failed);
        $this->assertSame('App\\Jobs\\TestJob', $failed->job_class);
    }

    public function testTrackFailedWithContextForNonExistentJobStillRecordsFailure(): void
    {
        // Job doesn't exist in station_jobs, but context provides all data
        $context = [
            'job_class' => 'App\\Jobs\\MissingJob',
            'queue' => 'high',
            'connection' => 'redis',
            'payload' => '{"gone":"true"}',
            'attempts' => 1,
            'batch_id' => null,
            'tags' => [],
        ];

        $this->jobRepository->trackFailed('ghost-job-1', 'Job vanished', $context);

        // The UPDATE on station_jobs affects 0 rows (no error)
        $job = $this->jobRepository->find('ghost-job-1');
        $this->assertNull($job);

        // But the failed record IS created using context data
        $failed = DB::table('station_failed_jobs')->where('original_id', 'ghost-job-1')->first();
        $this->assertNotNull($failed);
        $this->assertSame('App\\Jobs\\MissingJob', $failed->job_class);
        $this->assertSame('high', $failed->queue);
        $this->assertSame('Job vanished', $failed->exception);
    }

    public function testTrackFailedWithoutContextForNonExistentJobSkipsFailedRecord(): void
    {
        // No context + no job in station_jobs → no failed record
        $this->jobRepository->trackFailed('ghost-job-2', 'Disappeared');

        // The UPDATE on station_jobs affects 0 rows (no error)
        $job = $this->jobRepository->find('ghost-job-2');
        $this->assertNull($job);

        // No failed record created (SELECT returned null)
        $failed = DB::table('station_failed_jobs')->where('original_id', 'ghost-job-2')->first();
        $this->assertNull($failed);
    }

    // ──────────────────────────────────────────────────────────────
    // Fix 2: MetricsRepository::recordBatch() — single bulk INSERT
    // ──────────────────────────────────────────────────────────────

    public function testRecordBatchInsertsMultipleEntriesAtOnce(): void
    {
        $entries = [
            [
                'queue' => 'default',
                'connection' => null,
                'metrics' => [
                    'jobs_processed' => 10,
                    'jobs_failed' => 1,
                    'jobs_pending' => 5,
                    'avg_processing_time' => 200,
                    'avg_wait_time' => 50,
                    'peak_memory' => 1024,
                    'active_workers' => 2,
                ],
            ],
            [
                'queue' => 'emails',
                'connection' => 'redis',
                'metrics' => [
                    'jobs_processed' => 5,
                    'jobs_failed' => 0,
                    'jobs_pending' => 3,
                    'avg_processing_time' => 100,
                    'avg_wait_time' => 30,
                    'peak_memory' => 512,
                    'active_workers' => 1,
                ],
            ],
            [
                'queue' => 'high',
                'connection' => 'rabbitmq',
                'metrics' => [
                    'jobs_processed' => 20,
                    'jobs_failed' => 2,
                    'jobs_pending' => 0,
                    'avg_processing_time' => 300,
                    'avg_wait_time' => 10,
                    'peak_memory' => 2048,
                    'active_workers' => 4,
                ],
            ],
        ];

        $this->metricsRepository->recordBatch($entries);

        $count = DB::table('station_metrics')->count();
        $this->assertSame(3, $count);

        // Verify individual entries
        $this->assertDatabaseHas('station_metrics', [
            'queue' => 'default',
            'jobs_processed' => 10,
            'jobs_failed' => 1,
        ]);
        $this->assertDatabaseHas('station_metrics', [
            'queue' => 'emails',
            'connection' => 'redis',
            'jobs_processed' => 5,
        ]);
        $this->assertDatabaseHas('station_metrics', [
            'queue' => 'high',
            'connection' => 'rabbitmq',
            'jobs_processed' => 20,
            'jobs_failed' => 2,
        ]);
    }

    public function testRecordBatchWithEmptyArrayDoesNothing(): void
    {
        $this->metricsRepository->recordBatch([]);

        $count = DB::table('station_metrics')->count();
        $this->assertSame(0, $count);
    }

    public function testRecordBatchWithSingleEntry(): void
    {
        $entries = [
            [
                'queue' => 'default',
                'connection' => null,
                'metrics' => [
                    'jobs_processed' => 1,
                    'jobs_failed' => 0,
                    'jobs_pending' => 0,
                    'avg_processing_time' => 50,
                    'avg_wait_time' => 10,
                    'peak_memory' => 256,
                    'active_workers' => 1,
                ],
            ],
        ];

        $this->metricsRepository->recordBatch($entries);

        $count = DB::table('station_metrics')->count();
        $this->assertSame(1, $count);
    }

    public function testRecordBatchSetsRecordedAtForAllEntries(): void
    {
        $entries = [];
        for ($i = 0; $i < 5; $i++) {
            $entries[] = [
                'queue' => 'q' . $i,
                'connection' => null,
                'metrics' => [
                    'jobs_processed' => $i,
                    'jobs_failed' => 0,
                    'jobs_pending' => 0,
                    'avg_processing_time' => 0,
                    'avg_wait_time' => 0,
                    'peak_memory' => 0,
                    'active_workers' => 0,
                ],
            ];
        }

        $this->metricsRepository->recordBatch($entries);

        $rows = DB::table('station_metrics')->get();
        $this->assertCount(5, $rows);

        // All entries should have the same recorded_at timestamp (set in one call)
        $timestamps = $rows->pluck('recorded_at')->unique();
        $this->assertCount(1, $timestamps);
    }

    // ──────────────────────────────────────────────────────────────
    // Fix 4: incrementProcessed/incrementFailed return pending count
    // ──────────────────────────────────────────────────────────────

    public function testIncrementProcessedReturnsPendingCount(): void
    {
        $this->createBatch('batch-inc-1', 10, 10, 0, 0);

        $pending = $this->batchRepository->incrementProcessed('batch-inc-1');

        $this->assertSame(9, $pending);

        // Verify the DB state
        $batch = $this->batchRepository->find('batch-inc-1');
        $this->assertSame(1, $batch->processedJobs);
        $this->assertSame(9, $batch->pendingJobs);
    }

    public function testIncrementProcessedDecrementsPendingToZero(): void
    {
        $this->createBatch('batch-inc-2', 3, 1, 2, 0);

        $pending = $this->batchRepository->incrementProcessed('batch-inc-2');

        $this->assertSame(0, $pending);

        $batch = $this->batchRepository->find('batch-inc-2');
        $this->assertSame(3, $batch->processedJobs);
        $this->assertSame(0, $batch->pendingJobs);
    }

    public function testIncrementProcessedDoesNotGoBelowZero(): void
    {
        // Edge case: pending already at 0 (shouldn't happen in practice)
        $this->createBatch('batch-inc-3', 5, 0, 5, 0);

        $pending = $this->batchRepository->incrementProcessed('batch-inc-3');

        $this->assertSame(0, $pending);
    }

    public function testIncrementFailedReturnsPendingCount(): void
    {
        $this->createBatch('batch-fail-1', 10, 10, 0, 0);

        $pending = $this->batchRepository->incrementFailed('batch-fail-1', 'job-xyz');

        $this->assertSame(9, $pending);

        // Verify both failed and processed are incremented
        $batch = $this->batchRepository->find('batch-fail-1');
        $this->assertSame(1, $batch->failedJobs);
        $this->assertSame(1, $batch->processedJobs);
        $this->assertSame(9, $batch->pendingJobs);
    }

    public function testIncrementFailedDecrementsPendingToZero(): void
    {
        $this->createBatch('batch-fail-2', 5, 1, 4, 0);

        $pending = $this->batchRepository->incrementFailed('batch-fail-2', 'job-abc');

        $this->assertSame(0, $pending);
    }

    public function testMultipleIncrementsTrackCorrectly(): void
    {
        $this->createBatch('batch-multi', 5, 5, 0, 0);

        // Process 3 jobs, fail 1
        $p1 = $this->batchRepository->incrementProcessed('batch-multi');
        $this->assertSame(4, $p1);

        $p2 = $this->batchRepository->incrementProcessed('batch-multi');
        $this->assertSame(3, $p2);

        $p3 = $this->batchRepository->incrementFailed('batch-multi', 'fail-1');
        $this->assertSame(2, $p3);

        $p4 = $this->batchRepository->incrementProcessed('batch-multi');
        $this->assertSame(1, $p4);

        $p5 = $this->batchRepository->incrementProcessed('batch-multi');
        $this->assertSame(0, $p5);

        // Final state
        $batch = $this->batchRepository->find('batch-multi');
        $this->assertSame(5, $batch->totalJobs);
        $this->assertSame(0, $batch->pendingJobs);
        $this->assertSame(5, $batch->processedJobs);  // 4 success + 1 fail
        $this->assertSame(1, $batch->failedJobs);
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

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function createJob(string $id, string $queue = 'default', string $jobClass = 'App\\Jobs\\TestJob'): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        DB::table('station_jobs')->insert([
            'id' => $id,
            'queue' => $queue,
            'job_class' => $jobClass,
            'payload' => serialize(['data' => 'test']),
            'status' => JobStatus::Processing->value,
            'attempts' => 1,
            'max_tries' => 3,
            'timeout' => 60,
            'priority' => 0,
            'tags' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createBatch(
        string $id,
        int $totalJobs,
        int $pendingJobs,
        int $processedJobs,
        int $failedJobs,
    ): void {
        $now = CarbonImmutable::now()->toDateTimeString();

        DB::table('station_batches')->insert([
            'id' => $id,
            'name' => 'Test Batch',
            'queue' => 'default',
            'status' => BatchStatus::Processing->value,
            'total_jobs' => $totalJobs,
            'pending_jobs' => $pendingJobs,
            'processed_jobs' => $processedJobs,
            'failed_jobs' => $failedJobs,
            'allowed_failures' => 0,
            'failed_job_ids' => json_encode([]),
            'options' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS station_jobs (
            id VARCHAR(255) PRIMARY KEY,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(255) NULL,
            job_class VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT "pending",
            attempts INTEGER NOT NULL DEFAULT 0,
            max_tries INTEGER NOT NULL DEFAULT 3,
            timeout INTEGER NOT NULL DEFAULT 60,
            priority INTEGER NOT NULL DEFAULT 0,
            batch_id VARCHAR(255) NULL,
            tags TEXT NULL,
            worker_id VARCHAR(255) NULL,
            memory_used INTEGER NULL,
            processing_time INTEGER NULL,
            available_at TIMESTAMP NULL,
            reserved_at TIMESTAMP NULL,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_failed_jobs (
            id VARCHAR(255) PRIMARY KEY,
            original_id VARCHAR(255) NOT NULL,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(255) NULL,
            job_class VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            exception TEXT NOT NULL,
            context TEXT NULL,
            batch_id VARCHAR(255) NULL,
            tags TEXT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            failed_at TIMESTAMP NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_batches (
            id VARCHAR(255) PRIMARY KEY,
            name VARCHAR(255) NULL,
            queue VARCHAR(255) NOT NULL DEFAULT "default",
            connection VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT "pending",
            total_jobs INTEGER NOT NULL DEFAULT 0,
            pending_jobs INTEGER NOT NULL DEFAULT 0,
            processed_jobs INTEGER NOT NULL DEFAULT 0,
            failed_jobs INTEGER NOT NULL DEFAULT 0,
            allowed_failures INTEGER NOT NULL DEFAULT 0,
            failed_job_ids TEXT NULL,
            options TEXT NULL,
            started_at TIMESTAMP NULL,
            cancelled_at TIMESTAMP NULL,
            finished_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_metrics (
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
    }
}
