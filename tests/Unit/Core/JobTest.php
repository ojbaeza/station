<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Tests\Fixtures\TestJob;
use Station\Tests\Fixtures\TestJobWithOptions;
use stdClass;

class JobTest extends TestCase
{
    public function testCreateFromDispatchable(): void
    {
        $dispatchable = new TestJobWithOptions('test message');

        $job = Job::create($dispatchable, 'emails');

        $this->assertNotEmpty($job->id);
        $this->assertSame('emails', $job->queue);
        $this->assertSame(5, $job->maxTries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame(['tag1', 'tag2'], $job->tags);
        $this->assertSame(JobStatus::Pending->value, $job->status);
    }

    public function testCreateWithDefaults(): void
    {
        $dispatchable = new TestJob('test message');

        $job = Job::create($dispatchable, 'default');

        $this->assertSame(3, $job->maxTries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([], $job->tags);
    }

    public function testCreateWithDelay(): void
    {
        $dispatchable = new TestJob('test message');

        $delay = CarbonImmutable::now()->addMinutes(5);
        $job = Job::create($dispatchable, 'default', $delay);

        $this->assertNotNull($job->availableAt);
        $this->assertTrue($job->availableAt->isFuture());
    }

    public function testCreateWithBatchId(): void
    {
        $dispatchable = new TestJob('test message');

        $job = Job::create($dispatchable, 'default', null, 'batch-123');

        $this->assertSame('batch-123', $job->batchId);
    }

    public function testFromArray(): void
    {
        $row = [
            'id' => 'test-id',
            'queue' => 'default',
            'job_class' => 'App\\Jobs\\TestJob',
            'payload' => serialize(['data' => 'test']),
            'status' => 'processing',
            'attempts' => 2,
            'max_tries' => 5,
            'timeout' => 120,
            'priority' => 10,
            'batch_id' => 'batch-1',
            'tags' => json_encode(['tag1']),
            'worker_id' => 'worker-1',
            'memory_used' => 1024,
            'processing_time' => 500,
            'available_at' => '2025-01-27 10:00:00',
            'reserved_at' => '2025-01-27 10:01:00',
            'started_at' => '2025-01-27 10:01:05',
            'created_at' => '2025-01-27 10:00:00',
            'updated_at' => '2025-01-27 10:01:05',
        ];

        $job = Job::fromArray($row);

        $this->assertSame('test-id', $job->id);
        $this->assertSame('default', $job->queue);
        $this->assertSame('App\\Jobs\\TestJob', $job->jobClass);
        $this->assertSame('processing', $job->status);
        $this->assertSame(2, $job->attempts);
        $this->assertSame(5, $job->maxTries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame(10, $job->priority);
        $this->assertSame('batch-1', $job->batchId);
        $this->assertSame(['tag1'], $job->tags);
        $this->assertSame('worker-1', $job->workerId);
        $this->assertSame(1024, $job->memoryUsed);
        $this->assertSame(500, $job->processingTime);
    }

    public function testFromArrayWithArrayTags(): void
    {
        $row = [
            'id' => 'test-id',
            'queue' => 'default',
            'job_class' => 'TestJob',
            'payload' => '{}',
            'tags' => ['tag1', 'tag2'],
        ];

        $job = Job::fromArray($row);

        $this->assertSame(['tag1', 'tag2'], $job->tags);
    }

    public function testFromArrayWithNullOptionalFields(): void
    {
        $row = [
            'id' => 'test-id',
            'queue' => 'default',
            'job_class' => 'TestJob',
            'payload' => '{}',
        ];

        $job = Job::fromArray($row);

        $this->assertSame(JobStatus::Pending->value, $job->status);
        $this->assertSame(0, $job->attempts);
        $this->assertNull($job->batchId);
        $this->assertNull($job->workerId);
    }

    public function testGetJobInstance(): void
    {
        $original = new stdClass();
        $original->data = 'test';

        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'stdClass',
            payload: serialize($original),
            status: JobStatus::Pending->value,
        );

        $instance = $job->getJobInstance();

        $this->assertInstanceOf(stdClass::class, $instance);
        $this->assertSame('test', $instance->data);
    }

    public function testIsAvailableForPendingJobWithoutDelay(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
        );

        $this->assertTrue($job->isAvailable());
    }

    public function testIsAvailableForPendingJobWithPastDelay(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
            availableAt: CarbonImmutable::now()->subMinutes(5),
        );

        $this->assertTrue($job->isAvailable());
    }

    public function testIsNotAvailableForPendingJobWithFutureDelay(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
            availableAt: CarbonImmutable::now()->addMinutes(5),
        );

        $this->assertFalse($job->isAvailable());
    }

    public function testIsNotAvailableForNonPendingJob(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
        );

        $this->assertFalse($job->isAvailable());
    }

    public function testHasExceededMaxAttempts(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Failed->value,
            attempts: 3,
            maxTries: 3,
        );

        $this->assertTrue($job->hasExceededMaxAttempts());
    }

    public function testHasNotExceededMaxAttempts(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            attempts: 1,
            maxTries: 3,
        );

        $this->assertFalse($job->hasExceededMaxAttempts());
    }

    public function testGetId(): void
    {
        $job = new Job(
            id: 'my-job-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
        );

        $this->assertSame('my-job-id', $job->getId());
    }

    public function testToArray(): void
    {
        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
            attempts: 1,
            maxTries: 3,
            timeout: 60,
            priority: 5,
            batchId: 'batch-1',
            tags: ['tag1', 'tag2'],
        );

        $array = $job->toArray();

        $this->assertSame('test-id', $array['id']);
        $this->assertSame('default', $array['queue']);
        $this->assertSame('TestJob', $array['job_class']);
        $this->assertSame('{}', $array['payload']);
        $this->assertSame(JobStatus::Pending->value, $array['status']);
        $this->assertSame(1, $array['attempts']);
        $this->assertSame(3, $array['max_tries']);
        $this->assertSame(60, $array['timeout']);
        $this->assertSame(5, $array['priority']);
        $this->assertSame('batch-1', $array['batch_id']);
        $this->assertSame(['tag1', 'tag2'], $array['tags']);
    }

    public function testJsonSerialize(): void
    {
        $now = CarbonImmutable::now();

        $job = new Job(
            id: 'test-id',
            queue: 'default',
            jobClass: 'App\\Jobs\\TestJob',
            payload: '{}',
            status: JobStatus::Completed->value,
            createdAt: $now,
            completedAt: $now,
        );

        $json = $job->jsonSerialize();

        $this->assertSame('test-id', $json['id']);
        $this->assertSame('App\\Jobs\\TestJob', $json['name']);
        $this->assertSame('default', $json['queue']);
        $this->assertSame(JobStatus::Completed->value, $json['status']);
        $this->assertSame($now->toIso8601String(), $json['created_at']);
        $this->assertSame($now->toIso8601String(), $json['completed_at']);
    }
}
