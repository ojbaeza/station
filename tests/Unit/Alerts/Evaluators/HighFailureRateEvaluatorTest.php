<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Alerts\Evaluators;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Alerts\Evaluators\HighFailureRateEvaluator;
use Station\Contracts\MetricsCollectorInterface;
use Station\DTOs\AlertRule;
use Station\DTOs\MetricsAggregation;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;

class HighFailureRateEvaluatorTest extends TestCase
{
    private MockInterface&MetricsCollectorInterface $metrics;

    private HighFailureRateEvaluator $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = Mockery::mock(MetricsCollectorInterface::class);
        $this->sut = new HighFailureRateEvaluator($this->metrics);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testEvaluateReturnsNullWhenFailureRateBelowThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 10]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->with('5m')
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 5,
                avg_processing_time: 1.5,
                avg_wait_time: 0.5,
                failure_rate: 0.05, // 5%
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateReturnsWarningWhenAtThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 10]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->with('5m')
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 10,
                avg_processing_time: 1.5,
                avg_wait_time: 0.5,
                failure_rate: 0.10, // 10%
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Warning, $result->severity);
    }

    public function testEvaluateReturnsWarningWhenAboveThresholdButBelowCritical(): void
    {
        $rule = $this->makeRule(['threshold' => 10, 'critical_threshold' => 50]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->with('5m')
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 20,
                avg_processing_time: 1.5,
                avg_wait_time: 0.5,
                failure_rate: 0.20, // 20%
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Warning, $result->severity);
    }

    public function testEvaluateReturnsCriticalWhenAboveCriticalThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 10, 'critical_threshold' => 50]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->with('5m')
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 60,
                avg_processing_time: 1.5,
                avg_wait_time: 0.5,
                failure_rate: 0.60, // 60%
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateMessageIncludesRelevantData(): void
    {
        $rule = $this->makeRule(['threshold' => 10]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->andReturn(new MetricsAggregation(
                jobs_processed: 200,
                jobs_failed: 30,
                avg_processing_time: 1.5,
                avg_wait_time: 0.5,
                failure_rate: 0.15, // 15%
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('15.0%', $result->message);
        $this->assertStringContainsString('10.0%', $result->message);
        $this->assertStringContainsString('5 minutes', $result->message);
        $this->assertStringContainsString('200 jobs processed', $result->message);
        $this->assertStringContainsString('30 failed', $result->message);
    }

    public function testEvaluateContextHasCorrectKeys(): void
    {
        $rule = $this->makeRule(['threshold' => 10]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 15,
                avg_processing_time: 1.5,
                avg_wait_time: 0.5,
                failure_rate: 0.15,
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('failure_rate', $result->context);
        $this->assertArrayHasKey('threshold', $result->context);
        $this->assertArrayHasKey('jobs_processed', $result->context);
        $this->assertArrayHasKey('jobs_failed', $result->context);
        $this->assertArrayHasKey('window_minutes', $result->context);

        $this->assertSame(15.0, $result->context['failure_rate']);
        $this->assertSame(10.0, $result->context['threshold']);
        $this->assertSame(100, $result->context['jobs_processed']);
        $this->assertSame(15, $result->context['jobs_failed']);
        $this->assertSame(5, $result->context['window_minutes']);
    }

    public function testEvaluateUsesRuleWindowToCalculatePeriodString(): void
    {
        // window = 600 seconds => 10 minutes => period string "10m"
        $rule = $this->makeRule(['threshold' => 5], window: 600);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->with('10m')
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 10,
                avg_processing_time: 1.0,
                avg_wait_time: 0.5,
                failure_rate: 0.10,
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(10, $result->context['window_minutes']);
    }

    public function testEvaluateUsesDefaultThresholdOf10WhenConditionEmpty(): void
    {
        $rule = $this->makeRule([]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 9,
                avg_processing_time: 1.0,
                avg_wait_time: 0.5,
                failure_rate: 0.09, // 9% < 10% default
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateUsesDefaultCriticalThresholdOf50(): void
    {
        // No critical_threshold in condition => default is 50
        $rule = $this->makeRule(['threshold' => 10]);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->andReturn(new MetricsAggregation(
                jobs_processed: 100,
                jobs_failed: 50,
                avg_processing_time: 1.0,
                avg_wait_time: 0.5,
                failure_rate: 0.50, // 50% == default critical threshold
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateRoundsWindowUpWithCeil(): void
    {
        // window = 90 seconds => ceil(90/60) = 2 minutes
        $rule = $this->makeRule(['threshold' => 5], window: 90);

        $this->metrics
            ->shouldReceive('getAggregatedForPeriod')
            ->once()
            ->with('2m')
            ->andReturn(new MetricsAggregation(
                jobs_processed: 50,
                jobs_failed: 5,
                avg_processing_time: 1.0,
                avg_wait_time: 0.5,
                failure_rate: 0.10,
            ));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function makeRule(array $condition, int $window = 300): AlertRule
    {
        return new AlertRule(
            id: 'rule-hfr-1',
            name: 'High Failure Rate',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: $condition,
            window: $window,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );
    }
}
