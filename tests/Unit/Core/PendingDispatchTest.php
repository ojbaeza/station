<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Carbon\CarbonImmutable;
use DateInterval;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Contracts\JobManagerInterface;
use Station\Core\PendingDispatch;
use Station\Tests\Fixtures\TestJob;

class PendingDispatchTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private JobManagerInterface&MockInterface $jobManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobManager = Mockery::mock(JobManagerInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testDispatchWithDefaultOptions(): void
    {
        $job = new TestJob('test');

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->with($job, null, null, null, [], null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testOnQueueSetsQueue(): void
    {
        $job = new TestJob('test');

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->with($job, 'emails', null, null, [], null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending->onQueue('emails')->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testDelayWithDateTimeInterface(): void
    {
        $job = new TestJob('test');
        $delayDate = CarbonImmutable::now()->addMinutes(5);

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn($j, $q, $d, $b, $t, $c) => $j === $job
                    && $q === null
                    && $d instanceof CarbonImmutable
                    && abs($d->diffInSeconds($delayDate)) < 2
                    && $b === null
                    && $t === []
                    && $c === null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending->delay($delayDate)->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testDelayWithDateInterval(): void
    {
        $job = new TestJob('test');
        $interval = new DateInterval('PT10M'); // 10 minutes

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn($j, $q, $d, $b, $t, $c) => $j === $job
                    && $q === null
                    && $d instanceof CarbonImmutable
                    && $d->isFuture()
                    && $b === null
                    && $t === []
                    && $c === null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending->delay($interval)->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testDelayWithSeconds(): void
    {
        $job = new TestJob('test');

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn($j, $q, $d, $b, $t, $c) => $j === $job
                    && $q === null
                    && $d instanceof CarbonImmutable
                    && $d->isFuture()
                    && $b === null
                    && $t === []
                    && $c === null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending->delay(300)->dispatch(); // 300 seconds = 5 minutes

        $this->assertSame('job-123', $result);
    }

    public function testTagsAddsTags(): void
    {
        $job = new TestJob('test');

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->with($job, null, null, null, ['tag1', 'tag2'], null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending->tags(['tag1', 'tag2'])->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testTagsMergesMultipleCalls(): void
    {
        $job = new TestJob('test');

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->with($job, null, null, null, ['tag1', 'tag2', 'tag3'], null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending
            ->tags(['tag1', 'tag2'])
            ->tags(['tag3'])
            ->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testWithBatchIdSetsBatchId(): void
    {
        $job = new TestJob('test');

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->with($job, null, null, 'batch-abc', [], null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending->withBatchId('batch-abc')->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testChainingMultipleOptions(): void
    {
        $job = new TestJob('test');
        $delay = CarbonImmutable::now()->addMinutes(5);

        $this->jobManager
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn($j, $q, $d, $b, $t, $c) => $j === $job
                    && $q === 'high-priority'
                    && $d instanceof CarbonImmutable
                    && $b === 'batch-xyz'
                    && $t === ['important', 'urgent']
                    && $c === null)
            ->andReturn('job-123');

        $pending = new PendingDispatch($job, $this->jobManager);

        $result = $pending
            ->onQueue('high-priority')
            ->delay($delay)
            ->withBatchId('batch-xyz')
            ->tags(['important', 'urgent'])
            ->dispatch();

        $this->assertSame('job-123', $result);
    }

    public function testDispatchSyncWithJobManager(): void
    {
        $job = new TestJob('test');

        $this->jobManager
            ->shouldReceive('dispatchSync')
            ->once()
            ->with($job);

        $pending = new PendingDispatch($job, $this->jobManager);

        $pending->dispatchSync();
    }

    public function testDispatchWithClosureManager(): void
    {
        $job = new TestJob('test');
        $receivedJob = null;
        $receivedQueue = null;
        $receivedDelay = null;

        $closure = static function ($j, $q, $d) use (&$receivedJob, &$receivedQueue, &$receivedDelay) {
            $receivedJob = $j;
            $receivedQueue = $q;
            $receivedDelay = $d;

            return 'closure-job-id';
        };

        $pending = new PendingDispatch($job, $closure);

        $result = $pending->onQueue('test-queue')->dispatch();

        $this->assertSame('closure-job-id', $result);
        $this->assertSame($job, $receivedJob);
        $this->assertSame('test-queue', $receivedQueue);
        $this->assertSame(0, $receivedDelay);
    }

    public function testDispatchSyncWithClosureManager(): void
    {
        $job = new TestJob('test');
        $called = false;

        $closure = static function ($j, $q, $d) use (&$called) {
            $called = true;

            return 'id';
        };

        $pending = new PendingDispatch($job, $closure);

        $pending->dispatchSync();

        $this->assertTrue($called);
    }
}
