<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Testing;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Station\Testing\Fakes\StationFake;
use Station\Tests\Fixtures\TestJob;

class StationFakeTest extends TestCase
{
    private StationFake $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new StationFake();
    }

    public function testDispatchRecordsJob(): void
    {
        $job = new TestJob('test-data');

        $this->fake->dispatch($job);

        $dispatched = $this->fake->getDispatched();
        $this->assertCount(1, $dispatched);
        $this->assertSame($job, $dispatched[0]);
    }

    public function testDispatchReturnsJobId(): void
    {
        $job = new TestJob('test-data');

        $pending = $this->fake->dispatch($job);
        $id = $pending->dispatch();

        $this->assertSame('fake-job-id-1', $id);
    }

    public function testDispatchNowRecordsJob(): void
    {
        $job = new TestJob('test-data');

        $id = $this->fake->dispatchNow($job);

        $this->assertSame('fake-job-id-1', $id);
        $this->assertCount(1, $this->fake->getDispatched());
    }

    public function testJobReturnsDispatch(): void
    {
        $job = new TestJob('test-data');

        $pending = $this->fake->job($job);

        $this->assertCount(1, $this->fake->getDispatched());
    }

    public function testRecordBatchRecords(): void
    {
        $jobs = [new TestJob('1'), new TestJob('2')];

        $this->fake->recordBatch($jobs, ['name' => 'test-batch']);

        $stats = $this->fake->getStats();
        $this->assertSame(1, $stats['batches']);
    }

    public function testRecordChainRecords(): void
    {
        $jobs = [new TestJob('1'), new TestJob('2')];

        $this->fake->recordChain($jobs);

        $stats = $this->fake->getStats();
        $this->assertSame(1, $stats['chains']);
    }

    public function testAssertDispatchedPassesForDispatchedJob(): void
    {
        $job = new TestJob('test-data');
        $this->fake->dispatch($job);

        // Should not throw
        $this->fake->assertDispatched(TestJob::class);
    }

    public function testAssertDispatchedFailsForNonDispatchedJob(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->fake->assertDispatched(TestJob::class);
    }

    public function testAssertDispatchedWithCallback(): void
    {
        $job = new TestJob('specific-data');
        $this->fake->dispatch($job);

        $this->fake->assertDispatched(
            TestJob::class,
            static fn(TestJob $j) => $j->message === 'specific-data',
        );
    }

    public function testAssertDispatchedWithCallableFilter(): void
    {
        $job = new TestJob('test-data');
        $this->fake->dispatch($job);

        $this->fake->assertDispatched(
            static fn(array $item) => $item['job'] instanceof TestJob,
        );
    }

    public function testAssertNotDispatchedPassesForNonDispatchedJob(): void
    {
        // Don't dispatch anything
        $this->fake->assertNotDispatched(TestJob::class);
    }

    public function testAssertNotDispatchedFailsForDispatchedJob(): void
    {
        $job = new TestJob('test-data');
        $this->fake->dispatch($job);

        $this->expectException(AssertionFailedError::class);
        $this->fake->assertNotDispatched(TestJob::class);
    }

    public function testAssertNotDispatchedWithCallback(): void
    {
        $job = new TestJob('specific-data');
        $this->fake->dispatch($job);

        // Should pass because we're checking for different data
        $this->fake->assertNotDispatched(
            TestJob::class,
            static fn(TestJob $j) => $j->message === 'other-data',
        );
    }

    public function testAssertNotDispatchedWithCallableFilter(): void
    {
        // Don't dispatch anything
        $this->fake->assertNotDispatched(
            static fn(array $item) => $item['job'] instanceof TestJob,
        );
    }

    public function testAssertNothingDispatchedPasses(): void
    {
        $this->fake->assertNothingDispatched();
    }

    public function testAssertNothingDispatchedFails(): void
    {
        $this->fake->dispatch(new TestJob('test'));

        $this->expectException(AssertionFailedError::class);
        $this->fake->assertNothingDispatched();
    }

    public function testAssertDispatchedTimes(): void
    {
        $this->fake->dispatch(new TestJob('1'));
        $this->fake->dispatch(new TestJob('2'));
        $this->fake->dispatch(new TestJob('3'));

        $this->fake->assertDispatchedTimes(TestJob::class, 3);
    }

    public function testAssertDispatchedTimesFailsWithWrongCount(): void
    {
        $this->fake->dispatch(new TestJob('1'));
        $this->fake->dispatch(new TestJob('2'));

        $this->expectException(AssertionFailedError::class);
        $this->fake->assertDispatchedTimes(TestJob::class, 5);
    }

    public function testAssertBatchDispatched(): void
    {
        $this->fake->recordBatch([new TestJob('1'), new TestJob('2')]);

        $this->fake->assertBatchDispatched();
    }

    public function testAssertBatchDispatchedWithCallback(): void
    {
        $this->fake->recordBatch([new TestJob('1')], ['name' => 'my-batch']);

        $this->fake->assertBatchDispatched(
            static fn(array $batch) => $batch['options']['name'] === 'my-batch',
        );
    }

    public function testAssertBatchDispatchedFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->fake->assertBatchDispatched();
    }

    public function testAssertChainDispatched(): void
    {
        $this->fake->recordChain([new TestJob('1'), new TestJob('2')]);

        $this->fake->assertChainDispatched([TestJob::class, TestJob::class]);
    }

    public function testAssertChainDispatchedFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->fake->assertChainDispatched([TestJob::class]);
    }

    public function testGetDispatchedFiltersByType(): void
    {
        $this->fake->dispatch(new TestJob('1'));
        $this->fake->dispatch(new TestJob('2'));

        $dispatched = $this->fake->getDispatched(TestJob::class);

        $this->assertCount(2, $dispatched);
    }

    public function testClearRemovesAllRecords(): void
    {
        $this->fake->dispatch(new TestJob('1'));
        $this->fake->recordBatch([new TestJob('2')]);
        $this->fake->recordChain([new TestJob('3')]);

        $this->fake->clear();

        $stats = $this->fake->getStats();
        $this->assertSame(0, $stats['dispatched']);
        $this->assertSame(0, $stats['batches']);
        $this->assertSame(0, $stats['chains']);
    }

    public function testGetStatsReturnsCorrectCounts(): void
    {
        $this->fake->dispatch(new TestJob('1'));
        $this->fake->dispatch(new TestJob('2'));
        $this->fake->recordBatch([new TestJob('3')]);
        $this->fake->recordChain([new TestJob('4')]);

        $stats = $this->fake->getStats();

        $this->assertSame(2, $stats['dispatched']);
        $this->assertSame(1, $stats['batches']);
        $this->assertSame(1, $stats['chains']);
    }

    public function testRetryIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();
        $this->fake->retry('job-123');
    }

    public function testCancelIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();
        $this->fake->cancel('job-123');
    }

    public function testFindReturnsNull(): void
    {
        $this->assertNull($this->fake->find('job-123'));
    }

    public function testDispatchWithQueueConfiguration(): void
    {
        $job = new TestJob('test-data');

        $pending = $this->fake->dispatch($job);
        $id = $pending->onQueue('high-priority')->dispatch();

        $this->assertSame('fake-job-id-1', $id);
    }

    public function testMultipleDispatchesIncrementIds(): void
    {
        $this->fake->dispatch(new TestJob('1'))->dispatch();
        $this->fake->dispatch(new TestJob('2'))->dispatch();
        $id3 = $this->fake->dispatch(new TestJob('3'))->dispatch();

        $this->assertSame('fake-job-id-3', $id3);
    }
}
