<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Scaling;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Events\WorkersScaledDown;
use Station\Events\WorkersScaledUp;
use Station\Scaling\AutoScaler;

class AutoScalerTest extends TestCase
{
    private MockInterface&MetricsRepositoryInterface $metrics;

    private MockInterface&Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = Mockery::mock(MetricsRepositoryInterface::class);
        $this->events = Mockery::mock(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testScaleReturnsEmptyWhenDisabled(): void
    {
        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => false,
        ]);

        $result = $scaler->scale();

        $this->assertEmpty($result);
    }

    public function testScaleUpWhenQueueSizeExceedsThreshold(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(WorkersScaledUp::class));

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        $this->assertNotNull($result);
        $this->assertSame('scale_up', $result['action']);
        $this->assertSame(1, $result['from']);
        $this->assertSame(5, $result['to']);
    }

    public function testScaleDownWhenQueueIsEmpty(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 0, 'jobs_processed' => 10, 'average_wait_time' => 0],
            ]);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(WorkersScaledDown::class));

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 5);

        $result = $scaler->scaleQueue('default');

        $this->assertNotNull($result);
        $this->assertSame('scale_down', $result['action']);
        $this->assertSame(5, $result['from']);
    }

    public function testDoesNotScaleWhenInCooldown(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 60,
                ],
            ],
        ]);

        // First scale
        $this->events->shouldReceive('dispatch')->once();
        $scaler->setCurrentWorkers('default', 1);
        $result1 = $scaler->scaleQueue('default');

        // Second scale (should be blocked by cooldown)
        $result2 = $scaler->scaleQueue('default');

        $this->assertNotNull($result1);
        $this->assertNull($result2);
    }

    public function testRespectsMinWorkers(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 0, 'jobs_processed' => 10, 'average_wait_time' => 0],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 2,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 2);

        $result = $scaler->scaleQueue('default');

        // Should not scale below min_workers
        $this->assertNull($result);
    }

    public function testRespectsMaxWorkers(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 10000, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $this->events->shouldReceive('dispatch')->once();

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 5,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        // Should scale to max_workers (5), not calculated value (100)
        $this->assertNotNull($result);
        $this->assertSame(5, $result['to']);
    }

    public function testGetRecommendationReturnsExpectedValues(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $recommendation = $scaler->getRecommendation('default');

        $this->assertSame(1, $recommendation['current']);
        $this->assertSame(5, $recommendation['recommended']);
        $this->assertSame('Queue backlog detected', $recommendation['reason']);
    }

    public function testScaleWithThroughputStrategy(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 100, 'jobs_processed' => 5, 'average_wait_time' => 5],
            ]);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(WorkersScaledUp::class));

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'throughput',
                    'target_throughput' => 100,
                    'throughput_per_worker' => 10,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        $this->assertNotNull($result);
        $this->assertSame('scale_up', $result['action']);
    }

    public function testScaleWithWaitTimeStrategy(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 100, 'jobs_processed' => 10, 'average_wait_time' => 60],
            ]);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(WorkersScaledUp::class));

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'wait_time',
                    'max_wait_time' => 30,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        $this->assertNotNull($result);
        $this->assertSame('scale_up', $result['action']);
    }

    public function testScaleWithWaitTimeStrategyScalesDown(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 10, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(WorkersScaledDown::class));

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'wait_time',
                    'max_wait_time' => 30,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 5);

        $result = $scaler->scaleQueue('default');

        $this->assertNotNull($result);
        $this->assertSame('scale_down', $result['action']);
    }

    public function testScaleWithCombinedStrategy(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 10, 'average_wait_time' => 60],
            ]);

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(WorkersScaledUp::class));

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'combined',
                    'jobs_per_worker' => 100,
                    'target_throughput' => 100,
                    'throughput_per_worker' => 10,
                    'max_wait_time' => 30,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        $this->assertNotNull($result);
        $this->assertSame('scale_up', $result['action']);
    }

    public function testIsEnabledReturnsCorrectValue(): void
    {
        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
        ]);

        $this->assertTrue($scaler->isEnabled());

        $scaler2 = new AutoScaler($this->metrics, $this->events, [
            'enabled' => false,
        ]);

        $this->assertFalse($scaler2->isEnabled());
    }

    public function testScaleSkipsDisabledQueues(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => false,
                ],
            ],
        ]);

        $result = $scaler->scaleQueue('default');

        $this->assertNull($result);
    }

    public function testGetRecommendationForUnderutilizedQueue(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 0, 'jobs_processed' => 10, 'average_wait_time' => 0],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 5);

        $recommendation = $scaler->getRecommendation('default');

        $this->assertSame(5, $recommendation['current']);
        $this->assertSame(1, $recommendation['recommended']);
        $this->assertSame('Queue is underutilized', $recommendation['reason']);
    }

    public function testGetRecommendationForOptimalCapacity(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 100, 'jobs_processed' => 10, 'average_wait_time' => 0],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $recommendation = $scaler->getRecommendation('default');

        $this->assertSame('Current capacity is optimal', $recommendation['reason']);
    }

    public function testScaleAllQueues(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $this->events->shouldReceive('dispatch')->times(2);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['high', 'low'],
            'queue_config' => [
                'high' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
                'low' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('high', 1);
        $scaler->setCurrentWorkers('low', 1);

        $results = $scaler->scale();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('high', $results);
        $this->assertArrayHasKey('low', $results);
    }

    public function testHandlesEmptyMetrics(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        // Should not scale when no metrics are available
        $this->assertNull($result);
    }

    public function testGetCurrentWorkersReturnsMinWorkersWhenNotSet(): void
    {
        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queue_config' => [
                'default' => [
                    'min_workers' => 3,
                ],
            ],
        ]);

        $this->assertSame(3, $scaler->getCurrentWorkers('default'));
    }

    public function testIsEnabledReturnsFalseByDefault(): void
    {
        $scaler = new AutoScaler($this->metrics, $this->events, []);

        $this->assertFalse($scaler->isEnabled());
    }

    public function testScaleWithUnknownStrategyUsesMinWorkers(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 10, 'average_wait_time' => 5],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 2,
                    'max_workers' => 10,
                    'strategy' => 'unknown_strategy',
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 2);

        $result = $scaler->scaleQueue('default');

        // Unknown strategy defaults to min_workers, same as current
        $this->assertNull($result);
    }

    public function testThroughputStrategyMaintainsWorkersWhenTargetMet(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 50, 'jobs_processed' => 100, 'average_wait_time' => 5, 'queue' => 'default'],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'throughput',
                    'target_throughput' => 100,
                    'throughput_per_worker' => 10,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 5);

        $result = $scaler->scaleQueue('default');

        // Target throughput met, no scaling needed
        $this->assertNull($result);
    }

    public function testWaitTimeStrategyMaintainsWorkersWhenBelowMax(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 50, 'jobs_processed' => 10, 'average_wait_time' => 20, 'queue' => 'default'],
            ]);

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'wait_time',
                    'max_wait_time' => 30,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 3);

        $result = $scaler->scaleQueue('default');

        // Wait time below max but above 50% threshold, no scaling needed
        $this->assertNull($result);
    }

    public function testAveragesMultipleMetricRecords(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 100, 'jobs_processed' => 10, 'average_wait_time' => 10],
                ['queue_size' => 200, 'jobs_processed' => 20, 'average_wait_time' => 20],
                ['queue_size' => 300, 'jobs_processed' => 30, 'average_wait_time' => 30],
            ]);

        $this->events->shouldReceive('dispatch')->once();

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'queue_size',
                    'jobs_per_worker' => 100,
                    'cooldown' => 0,
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        // Average queue size: (100+200+300)/3 = 200
        // Workers needed: 200/100 = 2
        $this->assertNotNull($result);
        $this->assertSame(2, $result['to']);
    }

    public function testCombinedStrategyWithCustomWeights(): void
    {
        $this->metrics->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 300, 'jobs_processed' => 10, 'average_wait_time' => 60, 'queue' => 'default'],
            ]);

        $this->events->shouldReceive('dispatch')->once();

        $scaler = new AutoScaler($this->metrics, $this->events, [
            'enabled' => true,
            'queues' => ['default'],
            'queue_config' => [
                'default' => [
                    'enabled' => true,
                    'min_workers' => 1,
                    'max_workers' => 10,
                    'strategy' => 'combined',
                    'jobs_per_worker' => 100,
                    'target_throughput' => 100,
                    'throughput_per_worker' => 10,
                    'max_wait_time' => 30,
                    'cooldown' => 0,
                    'weights' => [
                        'queue_size' => 0.6,
                        'throughput' => 0.2,
                        'wait_time' => 0.2,
                    ],
                ],
            ],
        ]);

        $scaler->setCurrentWorkers('default', 1);

        $result = $scaler->scaleQueue('default');

        $this->assertNotNull($result);
        $this->assertSame('scale_up', $result['action']);
    }
}
