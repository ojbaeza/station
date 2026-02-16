<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Alerts\Evaluators;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Alerts\Evaluators\StuckJobsEvaluator;
use Station\Contracts\StuckJobDetectorInterface;
use Station\DTOs\AlertRule;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;

class StuckJobsEvaluatorTest extends TestCase
{
    private MockInterface&StuckJobDetectorInterface $detector;

    private StuckJobsEvaluator $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = Mockery::mock(StuckJobDetectorInterface::class);
        $this->sut = new StuckJobsEvaluator($this->detector);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testEvaluateReturnsNullWhenStuckCountBelowThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 3]);

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn(collect([
                ['id' => 'job-1'],
                ['id' => 'job-2'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateReturnsNullWhenNoStuckJobs(): void
    {
        $rule = $this->makeRule(['threshold' => 1]);

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn(collect([]));

        $result = $this->sut->evaluate($rule);

        $this->assertNull($result);
    }

    public function testEvaluateReturnsAlertWhenStuckCountAtThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 2]);

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn(collect([
                ['id' => 'job-1'],
                ['id' => 'job-2'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Warning, $result->severity);
    }

    public function testEvaluateReturnsAlertWhenStuckCountAboveThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 1]);

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn(collect([
                ['id' => 'job-1'],
                ['id' => 'job-2'],
                ['id' => 'job-3'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('3 stuck job(s)', $result->message);
    }

    public function testEvaluateReturnsCriticalWhenCountExceedsCriticalThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 1, 'critical_threshold' => 5]);

        $stuckJobs = collect(array_map(
            static fn(int $i) => ['id' => "job-{$i}"],
            range(1, 6),
        ));

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateUsesDefaultCriticalThresholdOf10(): void
    {
        // No critical_threshold => default is 10
        $rule = $this->makeRule(['threshold' => 1]);

        $stuckJobs = collect(array_map(
            static fn(int $i) => ['id' => "job-{$i}"],
            range(1, 10),
        ));

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Critical, $result->severity);
    }

    public function testEvaluateReturnsWarningWhenBelowDefaultCriticalThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 1]);

        $stuckJobs = collect(array_map(
            static fn(int $i) => ['id' => "job-{$i}"],
            range(1, 9),
        ));

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Warning, $result->severity);
    }

    public function testEvaluateUsesDefaultThresholdOf1WhenConditionEmpty(): void
    {
        $rule = $this->makeRule([]);

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn(collect([
                ['id' => 'job-1'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
    }

    public function testEvaluateContextIncludesStuckCountAndThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 2]);

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn(collect([
                ['id' => 'job-1'],
                ['id' => 'job-2'],
                ['id' => 'job-3'],
            ]));

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('stuck_count', $result->context);
        $this->assertArrayHasKey('threshold', $result->context);
        $this->assertArrayHasKey('job_ids', $result->context);
        $this->assertSame(3, $result->context['stuck_count']);
        $this->assertSame(2, $result->context['threshold']);
    }

    public function testEvaluateContextJobIdsLimitedToTen(): void
    {
        $rule = $this->makeRule(['threshold' => 1]);

        $stuckJobs = collect(array_map(
            static fn(int $i) => ['id' => "job-{$i}"],
            range(1, 15),
        ));

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertCount(10, $result->context['job_ids']);
    }

    public function testEvaluateMessageIncludesCountAndThreshold(): void
    {
        $rule = $this->makeRule(['threshold' => 3]);

        $stuckJobs = collect(array_map(
            static fn(int $i) => ['id' => "job-{$i}"],
            range(1, 5),
        ));

        $this->detector
            ->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $result = $this->sut->evaluate($rule);

        $this->assertNotNull($result);
        $this->assertStringContainsString('5 stuck job(s)', $result->message);
        $this->assertStringContainsString('threshold: 3', $result->message);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function makeRule(array $condition): AlertRule
    {
        return new AlertRule(
            id: 'rule-sj-1',
            name: 'Stuck Jobs',
            type: AlertType::StuckJobs,
            enabled: true,
            condition: $condition,
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );
    }
}
