<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Alerts\Evaluators;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Alerts\Evaluators\QueueBackupEvaluator;
use Station\Contracts\MetricsCollectorInterface;
use Station\DTOs\AlertRule;
use Station\DTOs\QueueStats;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;

class QueueBackupEvaluatorTest extends TestCase
{
    private MockInterface&MetricsCollectorInterface $metrics;

    private QueueBackupEvaluator $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = Mockery::mock(MetricsCollectorInterface::class);
        $this->sut = new QueueBackupEvaluator($this->metrics);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testEvaluateReturnsNullWhenNoQueueExceedsThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 1000]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 500, paused: false, workers: 2, throughput: 10.0),
                'emails' => new QueueStats(size: 200, paused: false, workers: 1, throughput: 5.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateReturnsAlertWhenQueueSizeAtThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 1000]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 1000, paused: false, workers: 2, throughput: 10.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Warning, $result->severity);
    }

    public function testEvaluateReturnsAlertWhenQueueSizeAboveThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 500]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 800, paused: false, workers: 2, throughput: 10.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('default (800)', $result->message);
    }

    public function testEvaluateFiltersBySpecificQueueWhenConditionQueueIsSet(): void
    {
        $rule = $this->makeRule(['threshold' => 500, 'queue' => 'emails']);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 9999, paused: false, workers: 2, throughput: 10.0),
                'emails' => new QueueStats(size: 100, paused: false, workers: 1, throughput: 5.0),
            ]);

        // default is above threshold but should be filtered out
        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateIncludesSpecificQueueWhenItExceedsThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 500, 'queue' => 'emails']);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 100, paused: false, workers: 2, throughput: 10.0),
                'emails' => new QueueStats(size: 600, paused: false, workers: 1, throughput: 5.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('emails (600)', $result->message);
    }

    public function testEvaluateListsMultipleBackedUpQueuesInMessage(): void
    {
        $rule = $this->makeRule(['threshold' => 500]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 700, paused: false, workers: 2, throughput: 10.0),
                'emails' => new QueueStats(size: 800, paused: false, workers: 1, throughput: 5.0),
                'notifications' => new QueueStats(size: 100, paused: false, workers: 1, throughput: 3.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('default (700)', $result->message);
        $this->assertStringContainsString('emails (800)', $result->message);
        $this->assertStringNotContainsString('notifications', $result->message);
    }

    public function testEvaluateReturnsCriticalWhenMaxSizeExceedsCriticalThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 500, 'critical_threshold' => 2000]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 2500, paused: false, workers: 2, throughput: 10.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateUsesDefaultCriticalThresholdOfFiveTimesThreshold(): void
    {
        // No critical_threshold set => default is threshold * 5 = 1000 * 5 = 5000
        $rule = $this->makeRule(['threshold' => 1000]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 5000, paused: false, workers: 2, throughput: 10.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateUsesDefaultThresholdOf1000WhenConditionEmpty(): void
    {
        $rule = $this->makeRule([]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 999, paused: false, workers: 2, throughput: 10.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateContextHasCorrectKeys(): void
    {
        $rule = $this->makeRule(['threshold' => 500]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 800, paused: false, workers: 2, throughput: 10.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('threshold', $result->context);
        $this->assertArrayHasKey('backed_up_queues', $result->context);
        $this->assertSame(500, $result->context['threshold']);
        $this->assertSame(['default' => 800], $result->context['backed_up_queues']);
    }

    public function testEvaluateMessageIncludesThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 750]);

        $this->metrics
            ->shouldReceive('getQueueStats')
            ->once()
            ->andReturn([
                'default' => new QueueStats(size: 800, paused: false, workers: 2, throughput: 10.0),
            ]);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('threshold: 750', $result->message);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function makeRule(array $condition): AlertRule
    {
        return new AlertRule(
            id: 'rule-qb-1',
            name: 'Queue Backup',
            type: AlertType::QueueBackup,
            enabled: true,
            condition: $condition,
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );
    }
}
