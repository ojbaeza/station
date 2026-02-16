<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Carbon\CarbonImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Core\MetricsCollector;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\MetricsSnapshot;
use Station\DTOs\PaginatedResult;
use Station\DTOs\QueueStats;
use Station\DTOs\TimeSeriesPoint;

class MetricsCollectorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MetricsRepositoryInterface&MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(MetricsRepositoryInterface::class);

        // Drain the static metrics buffer to ensure a clean state between tests.
        // We use a disposable repository mock that accepts any recordBatch() calls.
        $disposable = Mockery::mock(MetricsRepositoryInterface::class);
        $disposable->shouldReceive('recordBatch');
        (new MetricsCollector($disposable, []))->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testIsEnabledWithDefaultConfig(): void
    {
        $collector = new MetricsCollector($this->repository, []);

        $this->assertTrue($collector->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $collector = new MetricsCollector($this->repository, [
            'enabled' => false,
        ]);

        $this->assertFalse($collector->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenMetricsDisabled(): void
    {
        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => false],
        ]);

        $this->assertFalse($collector->isEnabled());
    }

    public function testRecordStoresMetricsInRepository(): void
    {
        $this->repository
            ->shouldReceive('record')
            ->once()
            ->with('default', [
                'jobs_processed' => 10,
                'jobs_failed' => 2,
                'jobs_pending' => 5,
                'avg_processing_time' => 100,
                'avg_wait_time' => 50,
                'peak_memory' => 1024,
                'active_workers' => 3,
            ], null);

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true, 'sample_rate' => 100],
        ]);

        $collector->record('default', 10, 2, 5, 100, 50, 1024, 3);

        // Verify expectations were met
    }

    public function testRecordDoesNothingWhenDisabled(): void
    {
        $this->repository->shouldNotReceive('record');

        $collector = new MetricsCollector($this->repository, [
            'enabled' => false,
        ]);

        $collector->record('default', 10, 2, 5, 100, 50, 1024, 3);

        // Verify expectations were met
    }

    public function testRecordJobCompletionStoresMetrics(): void
    {
        $this->repository
            ->shouldReceive('recordBatch')
            ->once()
            ->with(Mockery::on(static function (array $entries): bool {
                if (\count($entries) !== 1) {
                    return false;
                }
                $entry = $entries[0];

                return $entry['queue'] === 'default'
                    && $entry['metrics']['jobs_processed'] === 1
                    && $entry['metrics']['jobs_failed'] === 0
                    && $entry['metrics']['avg_processing_time'] === 500
                    && $entry['metrics']['avg_wait_time'] === 100
                    && $entry['metrics']['peak_memory'] === 2048
                    && $entry['connection'] === null;
            }));

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        $collector->recordJobCompletion('default', 500, 100, 2048);
        $collector->flush();
    }

    public function testRecordJobCompletionDoesNothingWhenDisabled(): void
    {
        $this->repository->shouldNotReceive('record');

        $collector = new MetricsCollector($this->repository, [
            'enabled' => false,
        ]);

        $collector->recordJobCompletion('default', 500, 100, 2048);
    }

    public function testRecordJobFailureStoresMetrics(): void
    {
        $this->repository
            ->shouldReceive('recordBatch')
            ->once()
            ->with(Mockery::on(static function (array $entries): bool {
                if (\count($entries) !== 1) {
                    return false;
                }
                $entry = $entries[0];

                return $entry['queue'] === 'default'
                    && $entry['metrics']['jobs_processed'] === 0
                    && $entry['metrics']['jobs_failed'] === 1
                    && $entry['connection'] === null;
            }));

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        $collector->recordJobFailure('default');
        $collector->flush();
    }

    public function testRecordJobFailureDoesNothingWhenDisabled(): void
    {
        $this->repository->shouldNotReceive('record');

        $collector = new MetricsCollector($this->repository, [
            'enabled' => false,
        ]);

        $collector->recordJobFailure('default');
    }

    public function testGetForRangeDelegatesToRepository(): void
    {
        $start = CarbonImmutable::now()->subHour();
        $end = CarbonImmutable::now();

        $this->repository
            ->shouldReceive('getForRange')
            ->once()
            ->with('default', $start, $end)
            ->andReturn(collect([
                ['jobs_processed' => 10, 'recorded_at' => now()],
            ]));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getForRange('default', $start, $end);

        $this->assertCount(1, $result);
    }

    public function testGetAggregatedDelegatesToRepository(): void
    {
        $this->repository
            ->shouldReceive('getAggregated')
            ->once()
            ->with('default', 60)
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 5,
                avg_processing_time: 150.5,
                avg_wait_time: 25.0,
                failure_rate: 4.76,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAggregated('default', 60);

        $this->assertSame(100, $result->jobs_processed);
        $this->assertSame(5, $result->jobs_failed);
    }

    public function testGetSnapshotDelegatesToRepository(): void
    {
        $this->repository
            ->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(new MetricsSnapshot(
                jobs_per_minute: 5.0,
                jobs_processed_last_hour: 300,
                failed_jobs: 10,
                failed_rate_percent: 3.23,
                average_processing_time_ms: 200,
                active_workers: 5,
                pending_jobs: 25,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getSnapshot();

        $this->assertSame(5.0, $result->jobs_per_minute);
        $this->assertSame(300, $result->jobs_processed_last_hour);
    }

    public function testGetQueueStatsDelegatesToRepository(): void
    {
        $this->repository
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 10, paused: false, workers: 3, throughput: 2.5),
                'emails' => new QueueStats(size: 5, paused: true, workers: 1, throughput: 0.0),
            ]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getQueueStats();

        $this->assertArrayHasKey('default', $result);
        $this->assertArrayHasKey('emails', $result);
    }

    public function testStatsReturnsCombinedData(): void
    {
        $this->repository
            ->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(new MetricsSnapshot(
                jobs_per_minute: 5.0,
                jobs_processed_last_hour: 300,
                failed_jobs: 10,
                failed_rate_percent: 3.23,
                average_processing_time_ms: 200,
                active_workers: 5,
                pending_jobs: 25,
            ));

        $this->repository
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 10, paused: false, workers: 3, throughput: 2.5),
            ]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->stats();

        $this->assertSame(5.0, $result['jobs_per_minute']);
        $this->assertSame(300, $result['jobs_processed_last_hour']);
        $this->assertArrayHasKey('queues', $result);
    }

    public function testPruneDelegatesToRepository(): void
    {
        $this->repository
            ->shouldReceive('prune')
            ->once()
            ->with(24)
            ->andReturn(50);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->prune(24);

        $this->assertSame(50, $result);
    }

    public function testGetThroughputReturnsJobsPerMinute(): void
    {
        $this->repository
            ->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(new MetricsSnapshot(
                jobs_per_minute: 10.5,
                jobs_processed_last_hour: 0,
                failed_jobs: 0,
                failed_rate_percent: 0.0,
                average_processing_time_ms: 0,
                active_workers: 0,
                pending_jobs: 0,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getThroughput();

        $this->assertSame(10.5, $result);
    }

    public function testGetAverageWaitTimeReturnsWeightedAverageForGlobalScope(): void
    {
        // Global scope now computes a weighted average across all queues
        $this->repository
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'emails' => new QueueStats(size: 0, paused: false, workers: 1, throughput: 5.0),
                'default' => new QueueStats(size: 0, paused: false, workers: 1, throughput: 3.0),
            ]);

        $this->repository
            ->shouldReceive('getAggregated')
            ->with('emails', 60)
            ->andReturn(new MetricsAggregation(jobs_processed: 10, jobs_failed: 0, avg_processing_time: 0.0, avg_wait_time: 2.0, failure_rate: 0.0));

        $this->repository
            ->shouldReceive('getAggregated')
            ->with('default', 60)
            ->andReturn(new MetricsAggregation(jobs_processed: 10, jobs_failed: 0, avg_processing_time: 0.0, avg_wait_time: 4.0, failure_rate: 0.0));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAverageWaitTime();

        // Weighted average: (2.0 * 10 + 4.0 * 10) / 20 = 3.0
        $this->assertSame(3.0, $result);
    }

    public function testGetAverageWaitTimeReturnsZeroWhenNoQueuesHaveData(): void
    {
        $this->repository
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAverageWaitTime();

        $this->assertSame(0.0, $result);
    }

    public function testGetAverageWaitTimeForSpecificQueue(): void
    {
        $this->repository
            ->shouldReceive('getAggregated')
            ->once()
            ->with('emails', 60)
            ->andReturn(new MetricsAggregation(jobs_processed: 0, jobs_failed: 0, avg_processing_time: 0.0, avg_wait_time: 3.5, failure_rate: 0.0));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAverageWaitTime('emails');

        $this->assertSame(3.5, $result);
    }

    public function testGetAverageProcessingTimeReturnsSeconds(): void
    {
        $this->repository
            ->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(new MetricsSnapshot(
                jobs_per_minute: 0.0,
                jobs_processed_last_hour: 0,
                failed_jobs: 0,
                failed_rate_percent: 0.0,
                average_processing_time_ms: 2000, // 2000ms = 2 seconds
                active_workers: 0,
                pending_jobs: 0,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAverageProcessingTime();

        $this->assertSame(2.0, $result);
    }

    public function testGetAverageProcessingTimeForSpecificQueue(): void
    {
        $this->repository
            ->shouldReceive('getAggregated')
            ->once()
            ->with('default', 60)
            ->andReturn(new MetricsAggregation(jobs_processed: 0, jobs_failed: 0, avg_processing_time: 1.5, avg_wait_time: 0.0, failure_rate: 0.0));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAverageProcessingTime('default');

        $this->assertSame(1.5, $result);
    }

    public function testGetFailureRateReturnsRate(): void
    {
        $this->repository
            ->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(new MetricsSnapshot(
                jobs_per_minute: 0.0,
                jobs_processed_last_hour: 0,
                failed_jobs: 0,
                failed_rate_percent: 5.0, // 5% = 0.05 rate
                average_processing_time_ms: 0,
                active_workers: 0,
                pending_jobs: 0,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getFailureRate();

        $this->assertSame(0.05, $result);
    }

    public function testGetFailureRateForSpecificQueue(): void
    {
        $this->repository
            ->shouldReceive('getAggregated')
            ->once()
            ->with('emails', 60)
            ->andReturn(new MetricsAggregation(jobs_processed: 0, jobs_failed: 0, avg_processing_time: 0.0, avg_wait_time: 0.0, failure_rate: 0.03));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getFailureRate('emails');

        $this->assertSame(0.03, $result);
    }

    public function testGetMetricsReturnsPeriodData(): void
    {
        $this->repository
            ->shouldReceive('getSnapshot')
            ->once()
            ->andReturn(new MetricsSnapshot(
                jobs_per_minute: 5.0,
                jobs_processed_last_hour: 300,
                failed_jobs: 10,
                failed_rate_percent: 3.23,
                average_processing_time_ms: 200,
                active_workers: 5,
                pending_jobs: 25,
            ));

        $this->repository
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 10, paused: false, workers: 3, throughput: 2.5),
            ]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getMetrics('1h');

        $this->assertSame('1h', $result['period']);
        $this->assertSame(5.0, $result['jobs_per_minute']);
        $this->assertSame(300, $result['jobs_processed']);
        $this->assertArrayHasKey('queues', $result);
    }

    public function testGetMetricsSupportsDifferentPeriods(): void
    {
        $this->repository
            ->shouldReceive('getSnapshot')
            ->times(4)
            ->andReturn(new MetricsSnapshot(
                jobs_per_minute: 5.0,
                jobs_processed_last_hour: 300,
                failed_jobs: 10,
                failed_rate_percent: 3.23,
                average_processing_time_ms: 200,
                active_workers: 5,
                pending_jobs: 25,
            ));

        $this->repository
            ->shouldReceive('getQueueStats')
            ->times(4)
            ->andReturn([]);

        $collector = new MetricsCollector($this->repository, []);

        $this->assertSame('5m', $collector->getMetrics('5m')['period']);
        $this->assertSame('6h', $collector->getMetrics('6h')['period']);
        $this->assertSame('24h', $collector->getMetrics('24h')['period']);
        $this->assertSame('7d', $collector->getMetrics('7d')['period']);
    }

    public function testGetHistoricalMetricsDelegatesToRepository(): void
    {
        $this->repository
            ->shouldReceive('getAllRecent')
            ->once()
            ->with(60, 100)
            ->andReturn([
                ['id' => 1, 'queue' => 'default', 'jobs_processed' => 10, 'recorded_at' => '2024-01-01 12:00:00'],
                ['id' => 2, 'queue' => 'default', 'jobs_processed' => 15, 'recorded_at' => '2024-01-01 12:01:00'],
            ]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getHistoricalMetrics('1h');

        $this->assertCount(2, $result);
        $this->assertSame(10, $result[0]['jobs_processed']);
        $this->assertSame(15, $result[1]['jobs_processed']);
    }

    public function testGetHistoricalMetricsWithCustomLimit(): void
    {
        $this->repository
            ->shouldReceive('getAllRecent')
            ->once()
            ->with(1440, 50) // 24h = 1440 minutes
            ->andReturn([
                ['id' => 1, 'queue' => 'default', 'jobs_processed' => 10, 'recorded_at' => '2024-01-01 12:00:00'],
            ]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getHistoricalMetrics('24h', 50);

        $this->assertCount(1, $result);
    }

    public function testGetHistoricalMetricsSupports7DayPeriod(): void
    {
        $this->repository
            ->shouldReceive('getAllRecent')
            ->once()
            ->with(10080, 100) // 7d = 10080 minutes
            ->andReturn([]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getHistoricalMetrics('7d');

        $this->assertSame([], $result);
    }

    public function testPaginateHistoricalMetricsDelegatesToRepository(): void
    {
        $this->repository
            ->shouldReceive('paginateAllRecent')
            ->once()
            ->with(60, 1, 25, null) // 1h = 60 minutes, page 1, 25 per page
            ->andReturn(new PaginatedResult(
                data: [
                    ['id' => 1, 'queue' => 'default', 'jobs_processed' => 10],
                ],
                total: 100,
                per_page: 25,
                current_page: 1,
                last_page: 4,
                from: 1,
                to: 25,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->paginateHistoricalMetrics('1h', 1, 25);

        $this->assertSame(100, $result->total);
        $this->assertCount(1, $result->data);
    }

    public function testPaginateHistoricalMetricsWithDifferentPeriod(): void
    {
        $this->repository
            ->shouldReceive('paginateAllRecent')
            ->once()
            ->with(1440, 2, 50, null) // 24h = 1440 minutes, page 2, 50 per page
            ->andReturn(new PaginatedResult(
                data: [],
                total: 0,
                per_page: 50,
                current_page: 2,
                last_page: 1,
                from: null,
                to: null,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->paginateHistoricalMetrics('24h', 2, 50);

        $this->assertSame([], $result->data);
        $this->assertSame(2, $result->current_page);
    }

    public function testGetAggregatedForPeriodDelegatesToRepository(): void
    {
        $this->repository
            ->shouldReceive('getGlobalAggregated')
            ->once()
            ->with(60, null) // 1h = 60 minutes, no connection filter
            ->andReturn(new MetricsAggregation(
                jobs_processed: 1000,
                jobs_failed: 50,
                avg_processing_time: 0.5,
                avg_wait_time: 0.1,
                failure_rate: 0.0476,
                throughput: 16.67,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAggregatedForPeriod('1h');

        $this->assertSame(1000, $result->jobs_processed);
        $this->assertSame(50, $result->jobs_failed);
        $this->assertSame(0.5, $result->avg_processing_time);
        $this->assertSame(16.67, $result->throughput);
    }

    public function testGetAggregatedForPeriodWithDifferentPeriods(): void
    {
        $this->repository
            ->shouldReceive('getGlobalAggregated')
            ->once()
            ->with(10080, null) // 7d = 10080 minutes
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100000,
                jobs_failed: 500,
                avg_processing_time: 0.4,
                avg_wait_time: 0.05,
                failure_rate: 0.005,
                throughput: 9.92,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAggregatedForPeriod('7d');

        $this->assertSame(100000, $result->jobs_processed);
        $this->assertSame(9.92, $result->throughput);
    }

    public function testGetAggregatedForPeriodWithConnectionFilter(): void
    {
        $this->repository
            ->shouldReceive('getGlobalAggregated')
            ->once()
            ->with(60, 'rabbitmq')
            ->andReturn(new MetricsAggregation(
                jobs_processed: 500,
                jobs_failed: 10,
                avg_processing_time: 0.3,
                avg_wait_time: 0.08,
                failure_rate: 0.02,
                throughput: 8.33,
            ));

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getAggregatedForPeriod('1h', 'rabbitmq');

        $this->assertSame(500, $result->jobs_processed);
        $this->assertSame(10, $result->jobs_failed);
        $this->assertSame(8.33, $result->throughput);
    }

    public function testGetTimeSeriesWithConnectionFilter(): void
    {
        $this->repository
            ->shouldReceive('getTimeSeries')
            ->once()
            ->with(60, 30, 'redis', null)
            ->andReturn([
                new TimeSeriesPoint(timestamp: '2024-01-01 12:00:00', jobs_processed: 10, jobs_failed: 0, avg_wait_time: 0.1, avg_processing_time: 0.2),
            ]);

        $collector = new MetricsCollector($this->repository, []);

        $result = $collector->getTimeSeries('1h', 30, 'redis');

        $this->assertCount(1, $result);
        $this->assertSame(10, $result[0]->jobs_processed);
    }

    public function testAutoFlushTriggersWhenBufferReachesThreshold(): void
    {
        // BUFFER_FLUSH_SIZE is 50 — the 50th entry should trigger an automatic flush
        $this->repository
            ->shouldReceive('recordBatch')
            ->once()
            ->with(Mockery::on(static fn(array $entries): bool => \count($entries) === 50));

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        // Add exactly 50 entries — the 50th should trigger the auto-flush
        for ($i = 0; $i < 50; $i++) {
            $collector->recordJobCompletion('default', 100, 10, 1024);
        }

        // No explicit flush() needed — the repository mock verifies it was called
    }

    public function testAutoFlushDoesNotTriggerBelowThreshold(): void
    {
        // 49 entries should NOT trigger a flush
        $this->repository->shouldNotReceive('recordBatch');

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        for ($i = 0; $i < 49; $i++) {
            $collector->recordJobCompletion('default', 100, 10, 1024);
        }

        // Clean up: drain the static buffer so it doesn't leak to other tests
        $this->repository->shouldReceive('recordBatch');
        $collector->flush();
    }

    public function testFlushIsIdempotent(): void
    {
        // recordBatch should only be called once, even with two flush() calls
        $this->repository
            ->shouldReceive('recordBatch')
            ->once()
            ->with(Mockery::on(static fn(array $entries): bool => \count($entries) === 1));

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        $collector->recordJobCompletion('default', 100, 10, 1024);
        $collector->flush();
        $collector->flush(); // Second flush should be a no-op
    }

    public function testBufferedEntriesPreserveConnectionParameter(): void
    {
        $this->repository
            ->shouldReceive('recordBatch')
            ->once()
            ->with(Mockery::on(static fn(array $entries): bool => \count($entries) === 2
                    && $entries[0]['connection'] === 'rabbitmq'
                    && $entries[1]['connection'] === 'redis'));

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        $collector->recordJobCompletion('default', 100, 10, 1024, 'rabbitmq');
        $collector->recordJobFailure('emails', 'redis');
        $collector->flush();
    }

    public function testRecordJobCompletionClampsNegativeValues(): void
    {
        $this->repository
            ->shouldReceive('recordBatch')
            ->once()
            ->with(Mockery::on(static function (array $entries): bool {
                $m = $entries[0]['metrics'];

                return $m['avg_processing_time'] === 0
                    && $m['avg_wait_time'] === 0
                    && $m['peak_memory'] === 0;
            }));

        $collector = new MetricsCollector($this->repository, [
            'enabled' => true,
            'metrics' => ['enabled' => true],
        ]);

        // Negative values should be clamped to 0
        $collector->recordJobCompletion('default', -50, -10, -1024);
        $collector->flush();
    }
}
