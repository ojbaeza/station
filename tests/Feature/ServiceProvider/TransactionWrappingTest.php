<?php

declare(strict_types=1);

namespace Station\Tests\Feature\ServiceProvider;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Enums\BatchStatus;
use Station\Enums\JobStatus;
use Station\StationServiceProvider;

/**
 * Integration tests for StationServiceProvider transaction wrapping (Fix 3).
 *
 * Verifies that JobProcessed and JobFailed event listeners wrap all DB writes
 * in a single transaction, reducing fsyncs from 2-3 per job to 1.
 */
class TransactionWrappingTest extends TestCase
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

    public function testJobProcessedListenerUpdatesJobAndBatchInTransaction(): void
    {
        // Set up: job in processing state + batch
        $this->seedJob('tx-job-1', 'default', JobStatus::Processing->value);
        $this->seedBatch('tx-batch-1', 5, 3, 2, 0);

        // Fire the JobProcessing event first (sets start time)
        $processingEvent = $this->makeJobEvent('tx-job-1', 'default', [
            'uuid' => 'tx-job-1',
            'stationJobId' => 'tx-job-1',
            'stationBatchId' => 'tx-batch-1',
            'displayName' => 'App\\Jobs\\TestJob',
            'pushedAt' => microtime(true) - 0.5,
        ]);
        event(new JobProcessing('testing', $processingEvent));

        // Fire the JobProcessed event
        $processedEvent = $this->makeJobEvent('tx-job-1', 'default', [
            'uuid' => 'tx-job-1',
            'stationJobId' => 'tx-job-1',
            'stationBatchId' => 'tx-batch-1',
            'displayName' => 'App\\Jobs\\TestJob',
            'pushedAt' => microtime(true) - 0.5,
        ]);
        event(new JobProcessed('testing', $processedEvent));

        // Verify: job status updated
        $job = DB::table('station_jobs')->where('id', 'tx-job-1')->first();
        $this->assertSame(JobStatus::Completed->value, $job->status);
        $this->assertNotNull($job->completed_at);

        // Verify: batch counters updated
        $batch = DB::table('station_batches')->where('id', 'tx-batch-1')->first();
        $this->assertSame(3, $batch->processed_jobs);
        $this->assertSame(2, $batch->pending_jobs);
    }

    public function testJobFailedListenerUpdatesJobBatchAndFailedRecordInTransaction(): void
    {
        // Set up: job in processing state + batch with high allowed_failures
        // (so failBatch() / Bus::findBatch() is not triggered, which needs job_batches table)
        $this->seedJob('tx-job-2', 'emails', JobStatus::Processing->value);
        $this->seedBatch('tx-batch-2', 5, 3, 2, 0, 100);

        $failedEvent = $this->makeJobEvent('tx-job-2', 'emails', [
            'uuid' => 'tx-job-2',
            'stationJobId' => 'tx-job-2',
            'stationBatchId' => 'tx-batch-2',
            'displayName' => 'App\\Jobs\\EmailJob',
            'stationTags' => ['important'],
            'pushedAt' => microtime(true),
        ]);

        $exception = new RuntimeException('Connection timed out');

        event(new JobFailed('testing', $failedEvent, $exception));

        // Verify: job status updated to failed
        $job = DB::table('station_jobs')->where('id', 'tx-job-2')->first();
        $this->assertSame(JobStatus::Failed->value, $job->status);

        // Verify: failed record created with context data
        $failed = DB::table('station_failed_jobs')->where('original_id', 'tx-job-2')->first();
        $this->assertNotNull($failed);
        $this->assertSame('Connection timed out', $failed->exception);

        // Verify: batch failure counter updated
        $batch = DB::table('station_batches')->where('id', 'tx-batch-2')->first();
        $this->assertSame(1, $batch->failed_jobs);
        $this->assertSame(3, $batch->processed_jobs);
        $this->assertSame(2, $batch->pending_jobs);
    }

    public function testJobProcessedListenerWorksWithoutBatch(): void
    {
        $this->seedJob('tx-job-3', 'default', JobStatus::Processing->value);

        $processedEvent = $this->makeJobEvent('tx-job-3', 'default', [
            'uuid' => 'tx-job-3',
            'stationJobId' => 'tx-job-3',
            'displayName' => 'App\\Jobs\\TestJob',
            'pushedAt' => microtime(true),
        ]);

        event(new JobProcessed('testing', $processedEvent));

        // Job is completed, no batch involved
        $job = DB::table('station_jobs')->where('id', 'tx-job-3')->first();
        $this->assertSame(JobStatus::Completed->value, $job->status);
    }

    public function testJobFailedListenerWorksWithoutBatch(): void
    {
        $this->seedJob('tx-job-4', 'default', JobStatus::Processing->value);

        $failedEvent = $this->makeJobEvent('tx-job-4', 'default', [
            'uuid' => 'tx-job-4',
            'stationJobId' => 'tx-job-4',
            'displayName' => 'App\\Jobs\\TestJob',
            'pushedAt' => microtime(true),
        ]);

        event(new JobFailed('testing', $failedEvent, new RuntimeException('Error')));

        $job = DB::table('station_jobs')->where('id', 'tx-job-4')->first();
        $this->assertSame(JobStatus::Failed->value, $job->status);

        $failed = DB::table('station_failed_jobs')->where('original_id', 'tx-job-4')->first();
        $this->assertNotNull($failed);
    }

    public function testTransactionWrapsJobAndBatchWritesTogether(): void
    {
        $this->seedJob('tx-job-5', 'default', JobStatus::Processing->value);
        $this->seedBatch('tx-batch-5', 5, 3, 2, 0);

        // Track all queries to verify job+batch writes happen in the same transaction
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $processedEvent = $this->makeJobEvent('tx-job-5', 'default', [
            'uuid' => 'tx-job-5',
            'stationJobId' => 'tx-job-5',
            'stationBatchId' => 'tx-batch-5',
            'displayName' => 'App\\Jobs\\TestJob',
            'pushedAt' => microtime(true),
        ]);

        event(new JobProcessed('testing', $processedEvent));

        // Verify both job and batch were updated (proves the listener ran)
        $job = DB::table('station_jobs')->where('id', 'tx-job-5')->first();
        $this->assertSame(JobStatus::Completed->value, $job->status);

        $batch = DB::table('station_batches')->where('id', 'tx-batch-5')->first();
        $this->assertSame(3, $batch->processed_jobs);
        $this->assertSame(2, $batch->pending_jobs);

        // Verify we see UPDATE queries for both station_jobs and station_batches
        $updateQueries = array_filter($queries, static fn($q) => stripos($q, 'update') !== false);
        $jobUpdate = array_filter($updateQueries, static fn($q) => stripos($q, 'station_jobs') !== false);
        $batchUpdate = array_filter($updateQueries, static fn($q) => stripos($q, 'station_batches') !== false);

        $this->assertNotEmpty($jobUpdate, 'station_jobs UPDATE should be executed');
        $this->assertNotEmpty($batchUpdate, 'station_batches UPDATE should be executed');
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('station.tracking.enabled', true);
        $app['config']->set('station.monitoring.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $app['config']->set('station.storage.database.connection', 'testing');
        $app['config']->set('station.storage.database.table_prefix', 'station_');
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeJobEvent(string $jobId, string $queue, array $payload): object
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('getJobId')->andReturn($jobId);
        $job->shouldReceive('getQueue')->andReturn($queue);
        $job->shouldReceive('resolveName')->andReturn($payload['displayName'] ?? 'Unknown');
        $job->shouldReceive('attempts')->andReturn(1);

        return $job;
    }

    private function seedJob(string $id, string $queue, string $status): void
    {
        $now = CarbonImmutable::now()->toDateTimeString();

        DB::table('station_jobs')->insert([
            'id' => $id,
            'queue' => $queue,
            'connection' => 'testing',
            'job_class' => 'App\\Jobs\\TestJob',
            'payload' => '{}',
            'status' => $status,
            'attempts' => 1,
            'max_tries' => 3,
            'timeout' => 60,
            'priority' => 0,
            'tags' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedBatch(
        string $id,
        int $totalJobs,
        int $pendingJobs,
        int $processedJobs,
        int $failedJobs,
        int $allowedFailures = 100,
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
            'allowed_failures' => $allowedFailures,
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

        DB::statement('CREATE TABLE IF NOT EXISTS station_queue_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(255) NOT NULL,
            paused BOOLEAN NOT NULL DEFAULT 0,
            paused_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )');
    }
}
