<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Station\Enums\JobStatus;
use Station\Repositories\DatabaseJobRepository;
use Station\StationServiceProvider;

/**
 * Tests for DatabaseJobRepository tracking methods and uncovered methods:
 * trackQueued, trackProcessing, trackCompleted, trackFailed,
 * paginate, paginateFailed, findFailed, deleteFailed,
 * getDistinctTags, addTag, removeTag, getStatsByQueue, getQueues, getByBatch, getEvents,
 * search with exclude_classes/only_classes, count with exclude_classes/only_classes,
 * getFailed, flushFailed.
 */
class DatabaseJobRepositoryTrackingTest extends TestCase
{
    private DatabaseJobRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->repository = new DatabaseJobRepository(DB::connection(), 'station_');
    }

    public function testTrackQueued(): void
    {
        $this->repository->trackQueued(
            'trk-job-1',
            'App\\Jobs\\TestJob',
            'default',
            'rabbitmq',
            ['data' => 'test', 'maxTries' => 5, 'timeout' => 120],
            'batch-1',
            ['important', 'email'],
        );

        $job = $this->repository->find('trk-job-1');

        $this->assertNotNull($job);
        $this->assertSame('trk-job-1', $job->id);
        $this->assertSame('App\\Jobs\\TestJob', $job->jobClass);
        $this->assertSame('default', $job->queue);
        $this->assertSame('rabbitmq', $job->connection);
        $this->assertSame(JobStatus::Pending->value, $job->status);
        $this->assertSame(0, $job->attempts);
        $this->assertSame(5, $job->maxTries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame('batch-1', $job->batchId);
        $this->assertSame(['important', 'email'], $job->tags);
    }

    public function testTrackProcessing(): void
    {
        $this->repository->trackQueued('trk-proc', 'App\\Jobs\\TestJob', 'default', 'redis', []);

        $this->repository->trackProcessing('trk-proc', 'default');

        $job = $this->repository->find('trk-proc');

        $this->assertSame(JobStatus::Processing->value, $job->status);
        $this->assertNotNull($job->startedAt);
        $this->assertSame(1, $job->attempts);
    }

    public function testTrackCompleted(): void
    {
        $this->repository->trackQueued('trk-comp', 'App\\Jobs\\TestJob', 'default', 'redis', []);

        $this->repository->trackCompleted('trk-comp');

        $job = $this->repository->find('trk-comp');

        $this->assertSame(JobStatus::Completed->value, $job->status);
        $this->assertNotNull($job->completedAt);
    }

    public function testTrackFailedWithContext(): void
    {
        $this->repository->trackQueued('trk-fail-ctx', 'App\\Jobs\\TestJob', 'emails', 'redis', []);

        $this->repository->trackFailed('trk-fail-ctx', 'RuntimeException: Something broke', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'emails',
            'connection' => 'redis',
            'payload' => '{"data":"test"}',
            'attempts' => 2,
            'batch_id' => 'batch-x',
            'tags' => ['critical'],
        ]);

        $job = $this->repository->find('trk-fail-ctx');
        $this->assertSame(JobStatus::Failed->value, $job->status);

        $failed = DB::table('station_failed_jobs')->where('original_id', 'trk-fail-ctx')->first();
        $this->assertNotNull($failed);
        $this->assertSame('App\\Jobs\\TestJob', $failed->job_class);
        $this->assertSame('emails', $failed->queue);
        $this->assertSame('redis', $failed->connection);
        $this->assertSame(2, $failed->attempts);
        $this->assertSame('batch-x', $failed->batch_id);
        $this->assertStringContains('RuntimeException', $failed->exception);
    }

    public function testTrackFailedWithoutContextFallsBackToJobsTable(): void
    {
        $this->repository->trackQueued('trk-fail-no-ctx', 'App\\Jobs\\TestJob', 'default', 'redis', []);

        $this->repository->trackFailed('trk-fail-no-ctx', 'Error message');

        $failed = DB::table('station_failed_jobs')->where('original_id', 'trk-fail-no-ctx')->first();
        $this->assertNotNull($failed);
        $this->assertSame('App\\Jobs\\TestJob', $failed->job_class);
    }

    public function testPaginate(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->repository->trackQueued("pag-{$i}", 'App\\Jobs\\TestJob', 'default', 'redis', []);
        }

        $result = $this->repository->paginate([], 1, 3);

        $this->assertSame(10, $result->total);
        $this->assertSame(3, $result->per_page);
        $this->assertSame(1, $result->current_page);
        $this->assertSame(4, $result->last_page);
        $this->assertCount(3, $result->data);
    }

    public function testPaginateWithFilters(): void
    {
        $this->repository->trackQueued('pag-f1', 'App\\Jobs\\TestJob', 'high', 'redis', []);
        $this->repository->trackQueued('pag-f2', 'App\\Jobs\\TestJob', 'low', 'redis', []);
        $this->repository->trackQueued('pag-f3', 'App\\Jobs\\TestJob', 'high', 'redis', []);

        $result = $this->repository->paginate(['queue' => 'high'], 1, 25);

        $this->assertSame(2, $result->total);
    }

    public function testPaginateFailed(): void
    {
        // Create some failed jobs
        $this->repository->trackQueued('pf-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackFailed('pf-1', 'Error 1', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'default',
            'payload' => '{}',
        ]);

        $this->repository->trackQueued('pf-2', 'App\\Jobs\\OtherJob', 'default', 'redis', []);
        $this->repository->trackFailed('pf-2', 'Error 2', [
            'job_class' => 'App\\Jobs\\OtherJob',
            'queue' => 'default',
            'payload' => '{}',
        ]);

        $result = $this->repository->paginateFailed([], 1, 25);

        $this->assertSame(2, $result->total);
        $this->assertCount(2, $result->data);
    }

    public function testPaginateFailedWithQueueFilter(): void
    {
        $this->repository->trackQueued('pff-1', 'App\\Jobs\\TestJob', 'high', 'redis', []);
        $this->repository->trackFailed('pff-1', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'high',
            'payload' => '{}',
        ]);

        $this->repository->trackQueued('pff-2', 'App\\Jobs\\TestJob', 'low', 'redis', []);
        $this->repository->trackFailed('pff-2', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'low',
            'payload' => '{}',
        ]);

        $result = $this->repository->paginateFailed(['queue' => 'high'], 1, 25);

        $this->assertSame(1, $result->total);
    }

    public function testFindFailed(): void
    {
        $this->repository->trackQueued('ff-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackFailed('ff-1', 'Error message', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'default',
            'payload' => '{}',
        ]);

        $found = $this->repository->findFailed('ff-1');

        $this->assertNotNull($found);
        $this->assertSame('ff-1', $found->id);
        $this->assertSame(JobStatus::Failed->value, $found->status);
    }

    public function testFindFailedReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->repository->findFailed('nonexistent'));
    }

    public function testDeleteFailed(): void
    {
        $this->repository->trackQueued('df-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackFailed('df-1', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'default',
            'payload' => '{}',
        ]);

        $this->repository->deleteFailed('df-1');

        $this->assertNull($this->repository->findFailed('df-1'));
    }

    public function testGetDistinctTags(): void
    {
        $this->repository->trackQueued('tag-1', 'App\\Jobs\\TestJob', 'default', 'redis', [], null, ['alpha', 'beta']);
        $this->repository->trackQueued('tag-2', 'App\\Jobs\\TestJob', 'default', 'redis', [], null, ['beta', 'gamma']);
        $this->repository->trackQueued('tag-3', 'App\\Jobs\\TestJob', 'default', 'redis', [], null, ['alpha']);

        $tags = $this->repository->getDistinctTags();

        $this->assertCount(3, $tags);
        $this->assertContains('alpha', $tags);
        $this->assertContains('beta', $tags);
        $this->assertContains('gamma', $tags);
        // Tags should be sorted
        $this->assertSame(['alpha', 'beta', 'gamma'], $tags);
    }

    public function testGetDistinctTagsReturnsEmptyWhenNoTags(): void
    {
        $this->repository->trackQueued('no-tag', 'App\\Jobs\\TestJob', 'default', 'redis', []);

        $tags = $this->repository->getDistinctTags();

        $this->assertSame([], $tags);
    }

    public function testAddTag(): void
    {
        $this->repository->trackQueued('add-tag-1', 'App\\Jobs\\TestJob', 'default', 'redis', [], null, ['existing']);

        $this->repository->addTag('add-tag-1', 'new-tag');

        $job = $this->repository->find('add-tag-1');
        $this->assertContains('existing', $job->tags);
        $this->assertContains('new-tag', $job->tags);
    }

    public function testAddTagDoesNotDuplicate(): void
    {
        $this->repository->trackQueued('add-tag-dup', 'App\\Jobs\\TestJob', 'default', 'redis', [], null, ['existing']);

        $this->repository->addTag('add-tag-dup', 'existing');

        $job = $this->repository->find('add-tag-dup');
        $this->assertCount(1, $job->tags);
    }

    public function testAddTagToNonExistentJobDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $this->repository->addTag('nonexistent', 'tag');
    }

    public function testRemoveTag(): void
    {
        $this->repository->trackQueued('rm-tag-1', 'App\\Jobs\\TestJob', 'default', 'redis', [], null, ['keep', 'remove']);

        $this->repository->removeTag('rm-tag-1', 'remove');

        $job = $this->repository->find('rm-tag-1');
        $this->assertSame(['keep'], $job->tags);
    }

    public function testRemoveTagFromNonExistentJobDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $this->repository->removeTag('nonexistent', 'tag');
    }

    public function testGetStatsByQueue(): void
    {
        $this->repository->trackQueued('sq-1', 'App\\Jobs\\TestJob', 'high', 'redis', []);
        $this->repository->trackQueued('sq-2', 'App\\Jobs\\TestJob', 'high', 'redis', []);
        $this->repository->trackQueued('sq-3', 'App\\Jobs\\TestJob', 'low', 'redis', []);

        $stats = $this->repository->getStatsByQueue();

        $this->assertArrayHasKey('high', $stats);
        $this->assertArrayHasKey('low', $stats);
        $this->assertSame(2, $stats['high']->pending);
        $this->assertSame(1, $stats['low']->pending);
    }

    public function testGetQueues(): void
    {
        $this->repository->trackQueued('q-1', 'App\\Jobs\\TestJob', 'emails', 'redis', []);
        $this->repository->trackQueued('q-2', 'App\\Jobs\\TestJob', 'reports', 'redis', []);
        $this->repository->trackQueued('q-3', 'App\\Jobs\\TestJob', 'emails', 'redis', []);

        $queues = $this->repository->getQueues();

        $this->assertCount(2, $queues);
        $this->assertContains('emails', $queues);
        $this->assertContains('reports', $queues);
    }

    public function testGetByBatch(): void
    {
        $this->repository->trackQueued('bb-1', 'App\\Jobs\\TestJob', 'default', 'redis', [], 'batch-a');
        $this->repository->trackQueued('bb-2', 'App\\Jobs\\TestJob', 'default', 'redis', [], 'batch-a');
        $this->repository->trackQueued('bb-3', 'App\\Jobs\\TestJob', 'default', 'redis', [], 'batch-b');

        $result = $this->repository->getByBatch('batch-a');

        $this->assertCount(2, $result);
    }

    public function testGetEvents(): void
    {
        // Insert an event directly
        DB::table('station_job_events')->insert([
            'id' => 1,
            'job_id' => 'evt-job-1',
            'type' => 'dispatched',
            'occurred_at' => now()->toDateTimeString(),
        ]);

        DB::table('station_job_events')->insert([
            'id' => 2,
            'job_id' => 'evt-job-1',
            'type' => 'completed',
            'occurred_at' => now()->addSecond()->toDateTimeString(),
        ]);

        $events = $this->repository->getEvents('evt-job-1');

        $this->assertCount(2, $events);
        $this->assertSame('dispatched', $events[0]['type']);
        $this->assertSame('completed', $events[1]['type']);
    }

    public function testGetFailed(): void
    {
        $this->repository->trackQueued('gf-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackFailed('gf-1', 'Error 1', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'default',
            'payload' => '{}',
        ]);

        $failed = $this->repository->getFailed();

        $this->assertCount(1, $failed);
        $this->assertSame(JobStatus::Failed->value, $failed->first()->status);
    }

    public function testGetFailedWithQueueFilter(): void
    {
        $this->repository->trackQueued('gfq-1', 'App\\Jobs\\TestJob', 'high', 'redis', []);
        $this->repository->trackFailed('gfq-1', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'high',
            'payload' => '{}',
        ]);

        $this->repository->trackQueued('gfq-2', 'App\\Jobs\\TestJob', 'low', 'redis', []);
        $this->repository->trackFailed('gfq-2', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'low',
            'payload' => '{}',
        ]);

        $failed = $this->repository->getFailed('high');

        $this->assertCount(1, $failed);
    }

    public function testFlushFailed(): void
    {
        $this->repository->trackQueued('fl-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackFailed('fl-1', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'default',
            'payload' => '{}',
        ]);

        $this->repository->trackQueued('fl-2', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackFailed('fl-2', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'default',
            'payload' => '{}',
        ]);

        $deleted = $this->repository->flushFailed();

        $this->assertSame(2, $deleted);
        $this->assertNull($this->repository->findFailed('fl-1'));
        $this->assertNull($this->repository->findFailed('fl-2'));
    }

    public function testFlushFailedWithQueueFilter(): void
    {
        $this->repository->trackQueued('flq-1', 'App\\Jobs\\TestJob', 'high', 'redis', []);
        $this->repository->trackFailed('flq-1', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'high',
            'payload' => '{}',
        ]);

        $this->repository->trackQueued('flq-2', 'App\\Jobs\\TestJob', 'low', 'redis', []);
        $this->repository->trackFailed('flq-2', 'Error', [
            'job_class' => 'App\\Jobs\\TestJob',
            'queue' => 'low',
            'payload' => '{}',
        ]);

        $deleted = $this->repository->flushFailed('high');

        $this->assertSame(1, $deleted);
        $this->assertNull($this->repository->findFailed('flq-1'));
        $this->assertNotNull($this->repository->findFailed('flq-2'));
    }

    public function testSearchWithExcludeClasses(): void
    {
        $this->repository->trackQueued('exc-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackQueued('exc-2', 'App\\Jobs\\SilencedJob', 'default', 'redis', []);
        $this->repository->trackQueued('exc-3', 'App\\Jobs\\TestJob', 'default', 'redis', []);

        $result = $this->repository->search(['exclude_classes' => ['App\\Jobs\\SilencedJob']]);

        $this->assertCount(2, $result);
    }

    public function testSearchWithOnlyClasses(): void
    {
        $this->repository->trackQueued('only-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackQueued('only-2', 'App\\Jobs\\SilencedJob', 'default', 'redis', []);
        $this->repository->trackQueued('only-3', 'App\\Jobs\\TestJob', 'default', 'redis', []);

        $result = $this->repository->search(['only_classes' => ['App\\Jobs\\SilencedJob']]);

        $this->assertCount(1, $result);
        $this->assertSame('only-2', $result->first()->id);
    }

    public function testCountWithExcludeClasses(): void
    {
        $this->repository->trackQueued('cexc-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackQueued('cexc-2', 'App\\Jobs\\SilencedJob', 'default', 'redis', []);

        $count = $this->repository->count(['exclude_classes' => ['App\\Jobs\\SilencedJob']]);

        $this->assertSame(1, $count);
    }

    public function testCountWithOnlyClasses(): void
    {
        $this->repository->trackQueued('conly-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackQueued('conly-2', 'App\\Jobs\\SilencedJob', 'default', 'redis', []);

        $count = $this->repository->count(['only_classes' => ['App\\Jobs\\SilencedJob']]);

        $this->assertSame(1, $count);
    }

    public function testSearchWithConnectionFilter(): void
    {
        $this->repository->trackQueued('conn-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackQueued('conn-2', 'App\\Jobs\\TestJob', 'default', 'rabbitmq', []);

        $result = $this->repository->search(['connection' => 'redis']);

        $this->assertCount(1, $result);
        $this->assertSame('conn-1', $result->first()->id);
    }

    public function testCountWithConnectionFilter(): void
    {
        $this->repository->trackQueued('cconn-1', 'App\\Jobs\\TestJob', 'default', 'redis', []);
        $this->repository->trackQueued('cconn-2', 'App\\Jobs\\TestJob', 'default', 'rabbitmq', []);

        $count = $this->repository->count(['connection' => 'redis']);

        $this->assertSame(1, $count);
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

    /**
     * Helper assertion for string containment (PHPUnit 11 compat).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
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

        DB::statement('CREATE TABLE IF NOT EXISTS station_job_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            data TEXT NULL,
            occurred_at TIMESTAMP NOT NULL
        )');
    }
}
