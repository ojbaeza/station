<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Scaling;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Station\Enums\ScalingStrategy;
use Station\Scaling\ScalingPolicy;
use Station\Scaling\ScalingPolicyBuilder;

class ScalingPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string, float, float, bool}>
     */
    public static function operatorProvider(): array
    {
        return [
            'greater_than_true' => ['>', 100.0, 50.0, true],
            'greater_than_false' => ['>', 50.0, 100.0, false],
            'greater_equal_true_equal' => ['>=', 100.0, 100.0, true],
            'greater_equal_true_greater' => ['>=', 101.0, 100.0, true],
            'greater_equal_false' => ['>=', 99.0, 100.0, false],
            'less_than_true' => ['<', 10.0, 50.0, true],
            'less_than_false' => ['<', 50.0, 10.0, false],
            'less_equal_true_equal' => ['<=', 50.0, 50.0, true],
            'less_equal_true_less' => ['<=', 49.0, 50.0, true],
            'less_equal_false' => ['<=', 51.0, 50.0, false],
            'equal_true' => ['==', 50.0, 50.0, true],
            'equal_false' => ['==', 50.0, 51.0, false],
            'not_equal_true' => ['!=', 50.0, 51.0, true],
            'not_equal_false' => ['!=', 50.0, 50.0, false],
            'invalid_operator' => ['~', 50.0, 50.0, false],
        ];
    }
    // ──────────────────────────────────────────────────────────────
    // create() — factory method returns builder
    // ──────────────────────────────────────────────────────────────

    public function testCreateReturnsScalingPolicyBuilder(): void
    {
        $builder = ScalingPolicy::create('test-policy');

        $this->assertInstanceOf(ScalingPolicyBuilder::class, $builder);
    }

    // ──────────────────────────────────────────────────────────────
    // Getters
    // ──────────────────────────────────────────────────────────────

    public function testGettersReturnConstructorValues(): void
    {
        $policy = new ScalingPolicy(
            name: 'my-policy',
            strategy: ScalingStrategy::Throughput->value,
            minWorkers: 2,
            maxWorkers: 20,
            cooldownSeconds: 120,
            thresholds: ['scale_up' => [['metric' => 'queue_size', 'operator' => '>', 'value' => 100]]],
            schedule: [['workers' => 5, 'hours' => [9, 10]]],
        );

        $this->assertSame('my-policy', $policy->getName());
        $this->assertSame('throughput', $policy->getStrategy());
        $this->assertSame(2, $policy->getMinWorkers());
        $this->assertSame(20, $policy->getMaxWorkers());
        $this->assertSame(120, $policy->getCooldownSeconds());
        $this->assertNotEmpty($policy->getThresholds());
        $this->assertNotEmpty($policy->getSchedule());
    }

    public function testDefaultValues(): void
    {
        $policy = new ScalingPolicy(name: 'default-test');

        $this->assertSame(ScalingStrategy::QueueSize->value, $policy->getStrategy());
        $this->assertSame(1, $policy->getMinWorkers());
        $this->assertSame(10, $policy->getMaxWorkers());
        $this->assertSame(60, $policy->getCooldownSeconds());
        $this->assertSame([], $policy->getThresholds());
        $this->assertSame([], $policy->getSchedule());
    }

    // ──────────────────────────────────────────────────────────────
    // toArray
    // ──────────────────────────────────────────────────────────────

    public function testToArrayReturnsCorrectStructure(): void
    {
        $policy = new ScalingPolicy(
            name: 'test',
            strategy: 'queue_size',
            minWorkers: 1,
            maxWorkers: 10,
            cooldownSeconds: 60,
        );

        $array = $policy->toArray();

        $this->assertSame('test', $array['name']);
        $this->assertSame('queue_size', $array['strategy']);
        $this->assertSame(1, $array['min_workers']);
        $this->assertSame(10, $array['max_workers']);
        $this->assertSame(60, $array['cooldown_seconds']);
        $this->assertArrayHasKey('thresholds', $array);
        $this->assertArrayHasKey('schedule', $array);
    }

    // ──────────────────────────────────────────────────────────────
    // evaluate — metrics-based (default strategy)
    // ──────────────────────────────────────────────────────────────

    public function testEvaluateScalesUpWhenConditionMet(): void
    {
        $policy = new ScalingPolicy(
            name: 'scale-up-test',
            minWorkers: 1,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [
                    ['metric' => 'queue_size', 'operator' => '>', 'value' => 100, 'increment' => 2],
                ],
                'scale_down' => [],
            ],
        );

        $result = $policy->evaluate([
            'queue_size' => 150,
            'current_workers' => 3,
        ]);

        $this->assertSame(5, $result); // 3 + 2 = 5
    }

    public function testEvaluateScalesDownWhenConditionMet(): void
    {
        $policy = new ScalingPolicy(
            name: 'scale-down-test',
            minWorkers: 1,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [],
                'scale_down' => [
                    ['metric' => 'queue_size', 'operator' => '<', 'value' => 10, 'decrement' => 1],
                ],
            ],
        );

        $result = $policy->evaluate([
            'queue_size' => 5,
            'current_workers' => 4,
        ]);

        $this->assertSame(3, $result); // 4 - 1 = 3
    }

    public function testEvaluateReturnsCurrentWorkersWhenNoConditionsMet(): void
    {
        $policy = new ScalingPolicy(
            name: 'no-change',
            minWorkers: 1,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [
                    ['metric' => 'queue_size', 'operator' => '>', 'value' => 100],
                ],
                'scale_down' => [
                    ['metric' => 'queue_size', 'operator' => '<', 'value' => 10],
                ],
            ],
        );

        $result = $policy->evaluate([
            'queue_size' => 50, // Between 10 and 100
            'current_workers' => 5,
        ]);

        $this->assertSame(5, $result);
    }

    public function testEvaluateDoesNotExceedMaxWorkers(): void
    {
        $policy = new ScalingPolicy(
            name: 'max-cap',
            minWorkers: 1,
            maxWorkers: 5,
            thresholds: [
                'scale_up' => [
                    ['metric' => 'queue_size', 'operator' => '>', 'value' => 10, 'increment' => 10],
                ],
                'scale_down' => [],
            ],
        );

        $result = $policy->evaluate([
            'queue_size' => 200,
            'current_workers' => 3,
        ]);

        $this->assertSame(5, $result); // min(5, 3 + 10) = 5
    }

    public function testEvaluateDoesNotGoBelowMinWorkers(): void
    {
        $policy = new ScalingPolicy(
            name: 'min-floor',
            minWorkers: 2,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [],
                'scale_down' => [
                    ['metric' => 'queue_size', 'operator' => '<', 'value' => 5, 'decrement' => 10],
                ],
            ],
        );

        $result = $policy->evaluate([
            'queue_size' => 0,
            'current_workers' => 3,
        ]);

        $this->assertSame(2, $result); // max(2, 3 - 10) = 2
    }

    public function testEvaluateUsesMinWorkersWhenCurrentNotProvided(): void
    {
        $policy = new ScalingPolicy(
            name: 'default-current',
            minWorkers: 3,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [],
                'scale_down' => [],
            ],
        );

        $result = $policy->evaluate(['queue_size' => 50]);

        $this->assertSame(3, $result);
    }

    // ──────────────────────────────────────────────────────────────
    // evaluate — all operators
    // ──────────────────────────────────────────────────────────────

    /**
     */
    #[DataProvider('operatorProvider')]
    public function testEvaluateWithOperator(string $operator, float $metricValue, float $thresholdValue, bool $shouldMatch): void
    {
        $policy = new ScalingPolicy(
            name: 'operator-test',
            minWorkers: 1,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [
                    ['metric' => 'queue_size', 'operator' => $operator, 'value' => $thresholdValue, 'increment' => 1],
                ],
                'scale_down' => [],
            ],
        );

        $result = $policy->evaluate([
            'queue_size' => $metricValue,
            'current_workers' => 5,
        ]);

        if ($shouldMatch) {
            $this->assertSame(6, $result); // 5 + 1
        } else {
            $this->assertSame(5, $result); // unchanged
        }
    }

    public function testEvaluateIgnoresConditionWithMissingMetric(): void
    {
        $policy = new ScalingPolicy(
            name: 'missing-metric',
            minWorkers: 1,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [
                    ['metric' => 'nonexistent_metric', 'operator' => '>', 'value' => 10],
                ],
                'scale_down' => [],
            ],
        );

        $result = $policy->evaluate([
            'queue_size' => 100,
            'current_workers' => 3,
        ]);

        $this->assertSame(3, $result);
    }

    public function testEvaluateIgnoresConditionWithNullMetric(): void
    {
        $policy = new ScalingPolicy(
            name: 'null-metric',
            minWorkers: 1,
            maxWorkers: 10,
            thresholds: [
                'scale_up' => [
                    ['operator' => '>', 'value' => 10], // no 'metric' key
                ],
                'scale_down' => [],
            ],
        );

        $result = $policy->evaluate(['current_workers' => 3]);

        $this->assertSame(3, $result);
    }

    // ──────────────────────────────────────────────────────────────
    // evaluate — schedule strategy
    // ──────────────────────────────────────────────────────────────

    public function testEvaluateScheduleMatchesCurrentHour(): void
    {
        $now = Carbon::now();
        $currentHour = (int) $now->format('H');

        $policy = new ScalingPolicy(
            name: 'schedule-test',
            strategy: ScalingStrategy::Schedule->value,
            minWorkers: 1,
            maxWorkers: 20,
            schedule: [
                ['workers' => 8, 'hours' => [$currentHour], 'days' => []],
            ],
        );

        $result = $policy->evaluate([]);

        $this->assertSame(8, $result);
    }

    public function testEvaluateScheduleReturnsMinWorkersWhenNoMatch(): void
    {
        $policy = new ScalingPolicy(
            name: 'schedule-no-match',
            strategy: ScalingStrategy::Schedule->value,
            minWorkers: 2,
            maxWorkers: 20,
            schedule: [
                ['workers' => 8, 'hours' => [99], 'days' => []], // hour 99 never matches
            ],
        );

        $result = $policy->evaluate([]);

        $this->assertSame(2, $result);
    }

    public function testEvaluateScheduleMatchesCurrentDayOfWeek(): void
    {
        $now = Carbon::now();
        $currentDay = (int) $now->format('N');

        $policy = new ScalingPolicy(
            name: 'schedule-day',
            strategy: ScalingStrategy::Schedule->value,
            minWorkers: 1,
            maxWorkers: 20,
            schedule: [
                ['workers' => 10, 'hours' => [], 'days' => [$currentDay]],
            ],
        );

        $result = $policy->evaluate([]);

        $this->assertSame(10, $result);
    }

    public function testEvaluateScheduleDoesNotMatchWrongDay(): void
    {
        $now = Carbon::now();
        $wrongDay = (((int) $now->format('N')) % 7) + 1; // next day

        $policy = new ScalingPolicy(
            name: 'schedule-wrong-day',
            strategy: ScalingStrategy::Schedule->value,
            minWorkers: 1,
            maxWorkers: 20,
            schedule: [
                ['workers' => 10, 'hours' => [], 'days' => [$wrongDay]],
            ],
        );

        $result = $policy->evaluate([]);

        $this->assertSame(1, $result); // falls back to minWorkers
    }

    public function testEvaluateScheduleMatchesHourAndDay(): void
    {
        $now = Carbon::now();
        $currentHour = (int) $now->format('H');
        $currentDay = (int) $now->format('N');

        $policy = new ScalingPolicy(
            name: 'schedule-both',
            strategy: ScalingStrategy::Schedule->value,
            minWorkers: 1,
            maxWorkers: 20,
            schedule: [
                ['workers' => 15, 'hours' => [$currentHour], 'days' => [$currentDay]],
            ],
        );

        $result = $policy->evaluate([]);

        $this->assertSame(15, $result);
    }

    public function testEvaluateScheduleFirstMatchWins(): void
    {
        $now = Carbon::now();
        $currentHour = (int) $now->format('H');

        $policy = new ScalingPolicy(
            name: 'schedule-first-wins',
            strategy: ScalingStrategy::Schedule->value,
            minWorkers: 1,
            maxWorkers: 20,
            schedule: [
                ['workers' => 5, 'hours' => [$currentHour], 'days' => []],
                ['workers' => 10, 'hours' => [$currentHour], 'days' => []], // would also match
            ],
        );

        $result = $policy->evaluate([]);

        $this->assertSame(5, $result); // first rule wins
    }
}
