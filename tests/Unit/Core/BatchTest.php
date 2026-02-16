<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch as LaravelBatch;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Station\Core\Batch;
use Station\Enums\BatchStatus;

class BatchTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function finishedStatusProvider(): array
    {
        return [
            'completed' => [BatchStatus::Completed->value],
            'failed' => [BatchStatus::Failed->value],
            'cancelled' => [BatchStatus::Cancelled->value],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonFinishedStatusProvider(): array
    {
        return [
            'pending' => [BatchStatus::Pending->value],
            'processing' => [BatchStatus::Processing->value],
        ];
    }

    public function testConstructorSetsAllProperties(): void
    {
        $now = CarbonImmutable::now();

        $batch = new Batch(
            id: 'batch-001',
            name: 'Import Users',
            queue: 'imports',
            status: BatchStatus::Processing->value,
            totalJobs: 100,
            pendingJobs: 50,
            processedJobs: 45,
            failedJobs: 5,
            allowedFailures: 10,
            failedJobIds: ['job-1', 'job-2'],
            options: ['notify' => true],
            startedAt: $now,
            cancelledAt: null,
            finishedAt: null,
            createdAt: $now,
            updatedAt: $now,
            connection: 'rabbitmq',
        );

        $this->assertSame('batch-001', $batch->id);
        $this->assertSame('Import Users', $batch->name);
        $this->assertSame('imports', $batch->queue);
        $this->assertSame(BatchStatus::Processing->value, $batch->status);
        $this->assertSame(100, $batch->totalJobs);
        $this->assertSame(50, $batch->pendingJobs);
        $this->assertSame(45, $batch->processedJobs);
        $this->assertSame(5, $batch->failedJobs);
        $this->assertSame(10, $batch->allowedFailures);
        $this->assertSame(['job-1', 'job-2'], $batch->failedJobIds);
        $this->assertSame(['notify' => true], $batch->options);
        $this->assertSame($now, $batch->startedAt);
        $this->assertNull($batch->cancelledAt);
        $this->assertNull($batch->finishedAt);
        $this->assertSame($now, $batch->createdAt);
        $this->assertSame($now, $batch->updatedAt);
        $this->assertSame('rabbitmq', $batch->connection);
    }

    public function testConstructorDefaultValues(): void
    {
        $batch = new Batch(id: 'batch-minimal');

        $this->assertSame('batch-minimal', $batch->id);
        $this->assertNull($batch->name);
        $this->assertSame('default', $batch->queue);
        $this->assertSame(BatchStatus::Pending->value, $batch->status);
        $this->assertSame(0, $batch->totalJobs);
        $this->assertSame(0, $batch->pendingJobs);
        $this->assertSame(0, $batch->processedJobs);
        $this->assertSame(0, $batch->failedJobs);
        $this->assertSame(0, $batch->allowedFailures);
        $this->assertSame([], $batch->failedJobIds);
        $this->assertNull($batch->options);
        $this->assertNull($batch->startedAt);
        $this->assertNull($batch->cancelledAt);
        $this->assertNull($batch->finishedAt);
        $this->assertNull($batch->createdAt);
        $this->assertNull($batch->updatedAt);
        $this->assertNull($batch->connection);
    }

    public function testCreateSetsPendingJobsEqualToTotalJobs(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-06-15 12:00:00'));

        $batch = Batch::create(
            id: 'batch-create-1',
            totalJobs: 25,
            name: 'Email Batch',
            queue: 'emails',
            allowedFailures: 3,
            options: ['retry' => true],
            connection: 'redis',
        );

        $this->assertSame('batch-create-1', $batch->id);
        $this->assertSame('Email Batch', $batch->name);
        $this->assertSame('emails', $batch->queue);
        $this->assertSame(25, $batch->totalJobs);
        $this->assertSame(25, $batch->pendingJobs);
        $this->assertSame(0, $batch->processedJobs);
        $this->assertSame(0, $batch->failedJobs);
        $this->assertSame(3, $batch->allowedFailures);
        $this->assertSame(['retry' => true], $batch->options);
        $this->assertSame('redis', $batch->connection);
        $this->assertNotNull($batch->createdAt);
        $this->assertNotNull($batch->updatedAt);
        $this->assertSame(BatchStatus::Pending->value, $batch->status);

        CarbonImmutable::setTestNow();
    }

    public function testFromLaravelBatchMapsAllFields(): void
    {
        $createdAt = CarbonImmutable::parse('2025-06-10 08:00:00');
        $finishedAt = CarbonImmutable::parse('2025-06-10 09:30:00');

        $laravelBatch = Mockery::mock(LaravelBatch::class);
        $laravelBatch->id = 'laravel-batch-99';
        $laravelBatch->name = 'Laravel Batch';
        $laravelBatch->totalJobs = 50;
        $laravelBatch->pendingJobs = 10;
        $laravelBatch->failedJobs = 2;
        $laravelBatch->failedJobIds = ['failed-1', 'failed-2'];
        $laravelBatch->createdAt = $createdAt;
        $laravelBatch->finishedAt = $finishedAt;
        $laravelBatch->cancelledAt = null;

        $batch = Batch::fromLaravelBatch($laravelBatch);

        $this->assertSame('laravel-batch-99', $batch->id);
        $this->assertSame('Laravel Batch', $batch->name);
        $this->assertSame(50, $batch->totalJobs);
        $this->assertSame(10, $batch->pendingJobs);
        $this->assertSame(40, $batch->processedJobs);
        $this->assertSame(2, $batch->failedJobs);
        $this->assertSame(['failed-1', 'failed-2'], $batch->failedJobIds);
        $this->assertSame($createdAt->toDateTimeString(), $batch->createdAt->toDateTimeString());
        $this->assertSame($finishedAt->toDateTimeString(), $batch->finishedAt->toDateTimeString());
        $this->assertNull($batch->cancelledAt);
    }

    public function testFromLaravelBatchWithNullCreatedAtUsesNow(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-06-15 12:00:00'));

        $laravelBatch = Mockery::mock(LaravelBatch::class);
        $laravelBatch->id = 'batch-null-dates';
        $laravelBatch->name = null;
        $laravelBatch->totalJobs = 5;
        $laravelBatch->pendingJobs = 5;
        $laravelBatch->failedJobs = 0;
        $laravelBatch->failedJobIds = [];
        $laravelBatch->createdAt = null;
        $laravelBatch->finishedAt = null;
        $laravelBatch->cancelledAt = null;

        $batch = Batch::fromLaravelBatch($laravelBatch);

        $this->assertSame('2025-06-15 12:00:00', $batch->createdAt->toDateTimeString());
        $this->assertNull($batch->finishedAt);
        $this->assertNull($batch->cancelledAt);

        CarbonImmutable::setTestNow();
    }

    public function testFromArrayParsesFullDatabaseRow(): void
    {
        $row = [
            'id' => 'batch-from-db',
            'name' => 'DB Batch',
            'queue' => 'high',
            'status' => BatchStatus::Completed->value,
            'total_jobs' => 20,
            'pending_jobs' => 0,
            'processed_jobs' => 18,
            'failed_jobs' => 2,
            'allowed_failures' => 5,
            'failed_job_ids' => json_encode(['j-1', 'j-2']),
            'options' => json_encode(['timeout' => 300]),
            'started_at' => '2025-06-10 08:00:00',
            'cancelled_at' => null,
            'finished_at' => '2025-06-10 09:00:00',
            'created_at' => '2025-06-10 07:55:00',
            'updated_at' => '2025-06-10 09:00:00',
            'connection' => 'rabbitmq',
        ];

        $batch = Batch::fromArray($row);

        $this->assertSame('batch-from-db', $batch->id);
        $this->assertSame('DB Batch', $batch->name);
        $this->assertSame('high', $batch->queue);
        $this->assertSame(BatchStatus::Completed->value, $batch->status);
        $this->assertSame(20, $batch->totalJobs);
        $this->assertSame(0, $batch->pendingJobs);
        $this->assertSame(18, $batch->processedJobs);
        $this->assertSame(2, $batch->failedJobs);
        $this->assertSame(5, $batch->allowedFailures);
        $this->assertSame(['j-1', 'j-2'], $batch->failedJobIds);
        $this->assertSame(['timeout' => 300], $batch->options);
        $this->assertSame('2025-06-10 08:00:00', $batch->startedAt->toDateTimeString());
        $this->assertNull($batch->cancelledAt);
        $this->assertSame('2025-06-10 09:00:00', $batch->finishedAt->toDateTimeString());
        $this->assertSame('2025-06-10 07:55:00', $batch->createdAt->toDateTimeString());
        $this->assertSame('2025-06-10 09:00:00', $batch->updatedAt->toDateTimeString());
        $this->assertSame('rabbitmq', $batch->connection);
    }

    public function testFromArrayWithMinimalRowUsesDefaults(): void
    {
        $row = ['id' => 'batch-minimal'];

        $batch = Batch::fromArray($row);

        $this->assertSame('batch-minimal', $batch->id);
        $this->assertNull($batch->name);
        $this->assertSame('default', $batch->queue);
        $this->assertSame(BatchStatus::Pending->value, $batch->status);
        $this->assertSame(0, $batch->totalJobs);
        $this->assertSame(0, $batch->pendingJobs);
        $this->assertSame(0, $batch->processedJobs);
        $this->assertSame(0, $batch->failedJobs);
        $this->assertSame(0, $batch->allowedFailures);
        $this->assertSame([], $batch->failedJobIds);
        $this->assertNull($batch->options);
        $this->assertNull($batch->startedAt);
        $this->assertNull($batch->cancelledAt);
        $this->assertNull($batch->finishedAt);
        $this->assertNull($batch->createdAt);
        $this->assertNull($batch->updatedAt);
        $this->assertNull($batch->connection);
    }

    public function testFromArrayWithAlreadyDecodedArrayFields(): void
    {
        $row = [
            'id' => 'batch-array-fields',
            'failed_job_ids' => ['j-a', 'j-b'],
            'options' => ['key' => 'value'],
        ];

        $batch = Batch::fromArray($row);

        $this->assertSame(['j-a', 'j-b'], $batch->failedJobIds);
        $this->assertSame(['key' => 'value'], $batch->options);
    }

    public function testProgressReturnsZeroWhenTotalJobsIsZero(): void
    {
        $batch = new Batch(id: 'batch-empty', totalJobs: 0, processedJobs: 0);

        $this->assertSame(0.0, $batch->progress());
    }

    public function testProgressReturnsCorrectPercentage(): void
    {
        $batch = new Batch(id: 'batch-progress', totalJobs: 200, processedJobs: 150);

        $this->assertSame(75.0, $batch->progress());
    }

    public function testProgressReturnsRoundedValue(): void
    {
        $batch = new Batch(id: 'batch-round', totalJobs: 3, processedJobs: 1);

        // 1/3 * 100 = 33.333... rounded to 33.33
        $this->assertSame(33.33, $batch->progress());
    }

    #[DataProvider('finishedStatusProvider')]
    public function testIsFinishedReturnsTrueForTerminalStatuses(string $status): void
    {
        $batch = new Batch(id: 'batch-finished', status: $status);

        $this->assertTrue($batch->isFinished());
        $this->assertTrue($batch->finished());
    }

    #[DataProvider('nonFinishedStatusProvider')]
    public function testIsFinishedReturnsFalseForActiveStatuses(string $status): void
    {
        $batch = new Batch(id: 'batch-active', status: $status);

        $this->assertFalse($batch->isFinished());
        $this->assertFalse($batch->finished());
    }

    public function testIsCancelledReturnsTrueOnlyForCancelledStatus(): void
    {
        $cancelled = new Batch(id: 'b1', status: BatchStatus::Cancelled->value);
        $this->assertTrue($cancelled->isCancelled());

        $pending = new Batch(id: 'b2', status: BatchStatus::Pending->value);
        $this->assertFalse($pending->isCancelled());

        $failed = new Batch(id: 'b3', status: BatchStatus::Failed->value);
        $this->assertFalse($failed->isCancelled());
    }

    public function testHasExceededAllowedFailuresComparison(): void
    {
        // failedJobs > allowedFailures => exceeded
        $exceeded = new Batch(id: 'b1', failedJobs: 6, allowedFailures: 5);
        $this->assertTrue($exceeded->hasExceededAllowedFailures());

        // failedJobs == allowedFailures => NOT exceeded
        $atLimit = new Batch(id: 'b2', failedJobs: 5, allowedFailures: 5);
        $this->assertFalse($atLimit->hasExceededAllowedFailures());

        // failedJobs < allowedFailures => NOT exceeded
        $belowLimit = new Batch(id: 'b3', failedJobs: 2, allowedFailures: 5);
        $this->assertFalse($belowLimit->hasExceededAllowedFailures());

        // zero allowed failures, zero failed => NOT exceeded
        $zeroZero = new Batch(id: 'b4', failedJobs: 0, allowedFailures: 0);
        $this->assertFalse($zeroZero->hasExceededAllowedFailures());

        // one failure with zero allowed => exceeded
        $oneZero = new Batch(id: 'b5', failedJobs: 1, allowedFailures: 0);
        $this->assertTrue($oneZero->hasExceededAllowedFailures());
    }

    public function testToArrayConvertsToDbRowFormat(): void
    {
        $now = CarbonImmutable::parse('2025-06-15 14:30:00');
        $started = CarbonImmutable::parse('2025-06-15 14:00:00');

        $batch = new Batch(
            id: 'batch-to-array',
            name: 'Export',
            queue: 'exports',
            status: BatchStatus::Processing->value,
            totalJobs: 10,
            pendingJobs: 3,
            processedJobs: 6,
            failedJobs: 1,
            allowedFailures: 2,
            failedJobIds: ['f-1'],
            options: ['format' => 'csv'],
            startedAt: $started,
            cancelledAt: null,
            finishedAt: null,
            createdAt: $now,
            updatedAt: $now,
            connection: 'sqs',
        );

        $array = $batch->toArray();

        $this->assertSame('batch-to-array', $array['id']);
        $this->assertSame('Export', $array['name']);
        $this->assertSame('exports', $array['queue']);
        $this->assertSame('sqs', $array['connection']);
        $this->assertSame(BatchStatus::Processing->value, $array['status']);
        $this->assertSame(10, $array['total_jobs']);
        $this->assertSame(3, $array['pending_jobs']);
        $this->assertSame(6, $array['processed_jobs']);
        $this->assertSame(1, $array['failed_jobs']);
        $this->assertSame(2, $array['allowed_failures']);
        $this->assertSame('["f-1"]', $array['failed_job_ids']);
        $this->assertSame('{"format":"csv"}', $array['options']);
        $this->assertSame('2025-06-15 14:00:00', $array['started_at']);
        $this->assertNull($array['cancelled_at']);
        $this->assertNull($array['finished_at']);
        $this->assertSame('2025-06-15 14:30:00', $array['created_at']);
        $this->assertSame('2025-06-15 14:30:00', $array['updated_at']);
    }

    public function testToArrayEncodesNullOptionsAsNull(): void
    {
        $batch = new Batch(id: 'batch-null-opt', options: null);

        $array = $batch->toArray();

        $this->assertNull($array['options']);
        $this->assertSame('[]', $array['failed_job_ids']);
    }

    public function testJsonSerializeConvertsToApiFormat(): void
    {
        $created = CarbonImmutable::parse('2025-06-15 14:30:00');
        $finished = CarbonImmutable::parse('2025-06-15 15:00:00');

        $batch = new Batch(
            id: 'batch-json',
            name: 'API Batch',
            queue: 'default',
            status: BatchStatus::Completed->value,
            totalJobs: 40,
            pendingJobs: 0,
            processedJobs: 40,
            failedJobs: 0,
            allowedFailures: 5,
            createdAt: $created,
            finishedAt: $finished,
            connection: 'redis',
        );

        $json = $batch->jsonSerialize();

        $this->assertSame('batch-json', $json['id']);
        $this->assertSame('API Batch', $json['name']);
        $this->assertSame('default', $json['queue']);
        $this->assertSame('redis', $json['connection']);
        $this->assertSame(BatchStatus::Completed->value, $json['status']);
        $this->assertSame(40, $json['total_jobs']);
        $this->assertSame(0, $json['pending_jobs']);
        $this->assertSame(40, $json['processed_jobs']);
        $this->assertSame(0, $json['failed_jobs']);
        $this->assertSame(5, $json['allowed_failures']);
        $this->assertSame(100.0, $json['progress_percent']);
        $this->assertSame($created->toIso8601String(), $json['created_at']);
        $this->assertSame($finished->toIso8601String(), $json['finished_at']);
        $this->assertNull($json['started_at']);
        $this->assertNull($json['cancelled_at']);
        $this->assertNull($json['updated_at']);
    }

    public function testJsonSerializeIncludesProgressPercent(): void
    {
        $batch = new Batch(
            id: 'batch-progress-json',
            totalJobs: 8,
            processedJobs: 3,
        );

        $json = $batch->jsonSerialize();

        $this->assertSame(37.5, $json['progress_percent']);
    }
}
