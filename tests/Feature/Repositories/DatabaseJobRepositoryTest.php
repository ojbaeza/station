<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Repositories\DatabaseJobRepository;
use Station\StationServiceProvider;

class DatabaseJobRepositoryTest extends TestCase
{
    private DatabaseJobRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->repository = new DatabaseJobRepository(DB::connection(), 'station_');
    }

    public function testStoreAndFind(): void
    {
        $job = $this->createJob(['id' => 'test-job-1']);

        $this->repository->store($job);

        $found = $this->repository->find('test-job-1');

        $this->assertNotNull($found);
        $this->assertSame('test-job-1', $found->id);
        $this->assertSame('default', $found->queue);
        $this->assertSame(JobStatus::Pending->value, $found->status);
    }

    public function testFindReturnsNullForNonExistent(): void
    {
        $result = $this->repository->find('nonexistent');

        $this->assertNull($result);
    }

    public function testUpdate(): void
    {
        $job = $this->createJob(['id' => 'update-job']);
        $this->repository->store($job);

        $job->status = JobStatus::Processing->value;
        $job->attempts = 1;
        $this->repository->update($job);

        $updated = $this->repository->find('update-job');

        $this->assertSame(JobStatus::Processing->value, $updated->status);
        $this->assertSame(1, $updated->attempts);
    }

    public function testDelete(): void
    {
        $job = $this->createJob(['id' => 'delete-job']);
        $this->repository->store($job);

        $this->repository->delete('delete-job');

        $this->assertNull($this->repository->find('delete-job'));
    }

    public function testGetByStatus(): void
    {
        $this->repository->store($this->createJob(['id' => 'pending-1', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'pending-2', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'completed-1', 'status' => JobStatus::Completed->value]));

        $pending = $this->repository->getByStatus(JobStatus::Pending->value);

        $this->assertCount(2, $pending);
    }

    public function testGetByStatusWithQueueFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'queue' => 'high', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'job-2', 'queue' => 'low', 'status' => JobStatus::Pending->value]));

        $high = $this->repository->getByStatus(JobStatus::Pending->value, 'high');

        $this->assertCount(1, $high);
        $this->assertSame('job-1', $high->first()->id);
    }

    public function testGetByQueue(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'queue' => 'emails']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'queue' => 'emails']));
        $this->repository->store($this->createJob(['id' => 'job-3', 'queue' => 'default']));

        $emails = $this->repository->getByQueue('emails');

        $this->assertCount(2, $emails);
    }

    public function testGetByBatchId(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'batchId' => 'batch-1']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'batchId' => 'batch-1']));
        $this->repository->store($this->createJob(['id' => 'job-3', 'batchId' => 'batch-2']));

        $batch1 = $this->repository->getByBatchId('batch-1');

        $this->assertCount(2, $batch1);
    }

    public function testReserve(): void
    {
        $this->repository->store($this->createJob([
            'id' => 'reserve-job',
            'status' => JobStatus::Pending->value,
        ]));

        $reserved = $this->repository->reserve('default', 'worker-1');

        $this->assertNotNull($reserved);
        $this->assertSame('reserve-job', $reserved->id);

        // Check the job is now reserved
        $job = $this->repository->find('reserve-job');
        $this->assertSame(JobStatus::Reserved->value, $job->status);
        $this->assertSame('worker-1', $job->workerId);
    }

    public function testReserveReturnsNullWhenNoJobs(): void
    {
        $reserved = $this->repository->reserve('default', 'worker-1');

        $this->assertNull($reserved);
    }

    public function testComplete(): void
    {
        $this->repository->store($this->createJob([
            'id' => 'complete-job',
            'status' => JobStatus::Processing->value,
        ]));

        $this->repository->complete('complete-job', 500, 1024);

        $job = $this->repository->find('complete-job');

        $this->assertSame(JobStatus::Completed->value, $job->status);
        $this->assertSame(500, $job->processingTime);
        $this->assertSame(1024, $job->memoryUsed);
        $this->assertNotNull($job->completedAt);
    }

    public function testFail(): void
    {
        $this->repository->store($this->createJob([
            'id' => 'fail-job',
            'status' => JobStatus::Processing->value,
        ]));

        $this->repository->fail('fail-job', 'RuntimeException: Test error', ['extra' => 'context']);

        $job = $this->repository->find('fail-job');
        $this->assertSame(JobStatus::Failed->value, $job->status);

        // Check failed jobs table
        $failed = DB::table('station_failed_jobs')->where('original_id', 'fail-job')->first();
        $this->assertNotNull($failed);
        $this->assertSame('RuntimeException: Test error', $failed->exception);
    }

    public function testRelease(): void
    {
        $this->repository->store($this->createJob([
            'id' => 'release-job',
            'status' => JobStatus::Processing->value,
            'workerId' => 'worker-1',
        ]));

        $this->repository->release('release-job', 60);

        $job = $this->repository->find('release-job');

        $this->assertSame(JobStatus::Pending->value, $job->status);
        $this->assertNull($job->workerId);
        $this->assertNotNull($job->availableAt);
    }

    public function testGetStuckJobs(): void
    {
        // Create a stuck job (processing for over 5 minutes)
        $stuckJob = $this->createJob([
            'id' => 'stuck-job',
            'status' => JobStatus::Processing->value,
            'startedAt' => CarbonImmutable::now()->subMinutes(10),
        ]);
        $this->repository->store($stuckJob);
        DB::table('station_jobs')->where('id', 'stuck-job')->update([
            'started_at' => CarbonImmutable::now()->subMinutes(10)->toDateTimeString(),
        ]);

        // Create a normal processing job
        $normalJob = $this->createJob([
            'id' => 'normal-job',
            'status' => JobStatus::Processing->value,
            'startedAt' => CarbonImmutable::now()->subSeconds(30),
        ]);
        $this->repository->store($normalJob);
        DB::table('station_jobs')->where('id', 'normal-job')->update([
            'started_at' => CarbonImmutable::now()->subSeconds(30)->toDateTimeString(),
        ]);

        $stuck = $this->repository->getStuckJobs(300); // 5 minute timeout

        $this->assertCount(1, $stuck);
        $this->assertSame('stuck-job', $stuck->first()->id);
    }

    public function testGetStats(): void
    {
        $this->repository->store($this->createJob(['id' => 'p1', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'p2', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'pr1', 'status' => JobStatus::Processing->value]));
        $this->repository->store($this->createJob(['id' => 'c1', 'status' => JobStatus::Completed->value]));
        $this->repository->store($this->createJob(['id' => 'f1', 'status' => JobStatus::Failed->value]));
        $this->repository->store($this->createJob(['id' => 'f2', 'status' => JobStatus::Failed->value]));

        $stats = $this->repository->getStats();

        $this->assertSame(2, $stats->pending);
        $this->assertSame(1, $stats->processing);
        $this->assertSame(1, $stats->completed);
        $this->assertSame(2, $stats->failed);
    }

    public function testGetRecent(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1']));
        $this->repository->store($this->createJob(['id' => 'job-2']));
        $this->repository->store($this->createJob(['id' => 'job-3']));

        $recent = $this->repository->getRecent(2);

        $this->assertCount(2, $recent);
    }

    public function testPruneCompleted(): void
    {
        // Old completed job
        $old = $this->createJob(['id' => 'old-job', 'status' => JobStatus::Completed->value]);
        $this->repository->store($old);
        DB::table('station_jobs')->where('id', 'old-job')->update([
            'completed_at' => CarbonImmutable::now()->subHours(48)->toDateTimeString(),
        ]);

        // Recent completed job
        $recent = $this->createJob(['id' => 'recent-job', 'status' => JobStatus::Completed->value]);
        $this->repository->store($recent);
        DB::table('station_jobs')->where('id', 'recent-job')->update([
            'completed_at' => CarbonImmutable::now()->subHours(12)->toDateTimeString(),
        ]);

        $pruned = $this->repository->pruneCompleted(24);

        $this->assertSame(1, $pruned);
        $this->assertNull($this->repository->find('old-job'));
        $this->assertNotNull($this->repository->find('recent-job'));
    }

    public function testSearch(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'queue' => 'high', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'job-2', 'queue' => 'low', 'status' => JobStatus::Failed->value]));
        $this->repository->store($this->createJob(['id' => 'job-3', 'queue' => 'high', 'status' => JobStatus::Failed->value]));

        $highFailed = $this->repository->search(['queue' => 'high', 'status' => JobStatus::Failed->value]);

        $this->assertCount(1, $highFailed);
        $this->assertSame('job-3', $highFailed->first()->id);
    }

    public function testCount(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'job-2', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'job-3', 'status' => JobStatus::Failed->value]));

        $pendingCount = $this->repository->count(['status' => JobStatus::Pending->value]);
        $totalCount = $this->repository->count([]);

        $this->assertSame(2, $pendingCount);
        $this->assertSame(3, $totalCount);
    }

    public function testGetByTags(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'tags' => ['important', 'email']]));
        $this->repository->store($this->createJob(['id' => 'job-2', 'tags' => ['email']]));
        $this->repository->store($this->createJob(['id' => 'job-3', 'tags' => ['important']]));

        $emailJobs = $this->repository->getByTags(['email']);

        $this->assertCount(2, $emailJobs);
    }

    public function testGetByTagsWithMultipleTags(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'tags' => ['important', 'email']]));
        $this->repository->store($this->createJob(['id' => 'job-2', 'tags' => ['email']]));

        $importantEmail = $this->repository->getByTags(['important', 'email']);

        $this->assertCount(1, $importantEmail);
        $this->assertSame('job-1', $importantEmail->first()->id);
    }

    public function testFailReturnsEarlyForNonExistentJob(): void
    {
        // This should not throw, just return early
        $this->repository->fail('nonexistent-job', 'Error');

        // No failed job should be created
        $failed = DB::table('station_failed_jobs')->where('original_id', 'nonexistent-job')->first();
        $this->assertNull($failed);
    }

    public function testReleaseWithoutDelay(): void
    {
        $this->repository->store($this->createJob([
            'id' => 'release-immediate',
            'status' => JobStatus::Processing->value,
            'workerId' => 'worker-1',
        ]));

        $this->repository->release('release-immediate', 0);

        $job = $this->repository->find('release-immediate');

        $this->assertSame(JobStatus::Pending->value, $job->status);
        $this->assertNull($job->workerId);
        $this->assertNull($job->availableAt);
    }

    public function testGetStatsWithQueueFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'p1', 'queue' => 'high', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'p2', 'queue' => 'low', 'status' => JobStatus::Pending->value]));
        $this->repository->store($this->createJob(['id' => 'c1', 'queue' => 'high', 'status' => JobStatus::Completed->value]));

        $highStats = $this->repository->getStats('high');

        $this->assertSame(1, $highStats->pending);
        $this->assertSame(1, $highStats->completed);
    }

    public function testGetRecentWithQueueFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'queue' => 'high']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'queue' => 'low']));
        $this->repository->store($this->createJob(['id' => 'job-3', 'queue' => 'high']));

        $recentHigh = $this->repository->getRecent(10, 'high');

        $this->assertCount(2, $recentHigh);
    }

    public function testSearchWithJobClassFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'jobClass' => 'App\\Jobs\\EmailJob']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'jobClass' => 'App\\Jobs\\ReportJob']));

        $emailJobs = $this->repository->search(['job_class' => 'Email']);

        $this->assertCount(1, $emailJobs);
        $this->assertSame('job-1', $emailJobs->first()->id);
    }

    public function testSearchWithBatchIdFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'batchId' => 'batch-123']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'batchId' => 'batch-456']));

        $batch123Jobs = $this->repository->search(['batch_id' => 'batch-123']);

        $this->assertCount(1, $batch123Jobs);
    }

    public function testSearchWithTagFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'tags' => ['important']]));
        $this->repository->store($this->createJob(['id' => 'job-2', 'tags' => []]));

        $importantJobs = $this->repository->search(['tag' => 'important']);

        $this->assertCount(1, $importantJobs);
    }

    public function testSearchWithDateFilters(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-old', 'createdAt' => CarbonImmutable::now()->subDays(2)]));
        $this->repository->store($this->createJob(['id' => 'job-new', 'createdAt' => CarbonImmutable::now()]));

        // Fix the created_at to ensure proper dates
        DB::table('station_jobs')->where('id', 'job-old')->update([
            'created_at' => CarbonImmutable::now()->subDays(2)->toDateTimeString(),
        ]);

        $recentJobs = $this->repository->search(['since' => CarbonImmutable::now()->subDay()->toDateTimeString()]);

        $this->assertCount(1, $recentJobs);
        $this->assertSame('job-new', $recentJobs->first()->id);
    }

    public function testSearchWithUntilFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-old', 'createdAt' => CarbonImmutable::now()->subDays(2)]));
        $this->repository->store($this->createJob(['id' => 'job-new', 'createdAt' => CarbonImmutable::now()]));

        DB::table('station_jobs')->where('id', 'job-old')->update([
            'created_at' => CarbonImmutable::now()->subDays(2)->toDateTimeString(),
        ]);

        $oldJobs = $this->repository->search(['until' => CarbonImmutable::now()->subDay()->toDateTimeString()]);

        $this->assertCount(1, $oldJobs);
        $this->assertSame('job-old', $oldJobs->first()->id);
    }

    public function testSearchWithSearchFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-abc-123', 'jobClass' => 'App\\Jobs\\TestJob']));
        $this->repository->store($this->createJob(['id' => 'job-xyz-456', 'jobClass' => 'App\\Jobs\\OtherJob']));

        $abcJobs = $this->repository->search(['search' => 'abc']);

        $this->assertCount(1, $abcJobs);
        $this->assertSame('job-abc-123', $abcJobs->first()->id);
    }

    public function testSearchWithSearchFilterOnJobClass(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'jobClass' => 'App\\Jobs\\EmailSenderJob']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'jobClass' => 'App\\Jobs\\ReportJob']));

        $emailJobs = $this->repository->search(['search' => 'EmailSender']);

        $this->assertCount(1, $emailJobs);
    }

    public function testSearchWithOffset(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1']));
        $this->repository->store($this->createJob(['id' => 'job-2']));
        $this->repository->store($this->createJob(['id' => 'job-3']));

        $offset2 = $this->repository->search([], 10, 2);

        $this->assertCount(1, $offset2);
    }

    public function testCountWithJobClassFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'jobClass' => 'App\\Jobs\\EmailJob']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'jobClass' => 'App\\Jobs\\EmailJob']));
        $this->repository->store($this->createJob(['id' => 'job-3', 'jobClass' => 'App\\Jobs\\OtherJob']));

        $emailCount = $this->repository->count(['job_class' => 'Email']);

        $this->assertSame(2, $emailCount);
    }

    public function testCountWithBatchIdFilter(): void
    {
        $this->repository->store($this->createJob(['id' => 'job-1', 'batchId' => 'batch-1']));
        $this->repository->store($this->createJob(['id' => 'job-2', 'batchId' => 'batch-1']));
        $this->repository->store($this->createJob(['id' => 'job-3', 'batchId' => 'batch-2']));

        $batch1Count = $this->repository->count(['batch_id' => 'batch-1']);

        $this->assertSame(2, $batch1Count);
    }

    public function testReserveWithAvailableAtInFuture(): void
    {
        $this->repository->store($this->createJob([
            'id' => 'future-job',
            'status' => JobStatus::Pending->value,
            'availableAt' => CarbonImmutable::now()->addMinutes(10),
        ]));

        // Update available_at in database
        DB::table('station_jobs')->where('id', 'future-job')->update([
            'available_at' => CarbonImmutable::now()->addMinutes(10)->toDateTimeString(),
        ]);

        $reserved = $this->repository->reserve('default', 'worker-1');

        // Should not reserve future jobs
        $this->assertNull($reserved);
    }

    public function testReserveWithAvailableAtInPast(): void
    {
        $this->repository->store($this->createJob([
            'id' => 'past-job',
            'status' => JobStatus::Pending->value,
        ]));

        // Update available_at in database to past
        DB::table('station_jobs')->where('id', 'past-job')->update([
            'available_at' => CarbonImmutable::now()->subMinutes(5)->toDateTimeString(),
        ]);

        $reserved = $this->repository->reserve('default', 'worker-1');

        $this->assertNotNull($reserved);
        $this->assertSame('past-job', $reserved->id);
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
            job_class VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            exception TEXT NOT NULL,
            context TEXT NULL,
            batch_id VARCHAR(255) NULL,
            tags TEXT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            failed_at TIMESTAMP NOT NULL
        )');
    }

    private function createJob(array $overrides = []): Job
    {
        $defaults = [
            'id' => 'job-' . uniqid(),
            'queue' => 'default',
            'jobClass' => 'App\\Jobs\\TestJob',
            'payload' => serialize(['data' => 'test']),
            'status' => JobStatus::Pending->value,
            'attempts' => 0,
            'maxTries' => 3,
            'timeout' => 60,
            'priority' => 0,
            'tags' => [],
            'createdAt' => CarbonImmutable::now(),
            'updatedAt' => CarbonImmutable::now(),
        ];

        return new Job(...array_merge($defaults, $overrides));
    }
}
