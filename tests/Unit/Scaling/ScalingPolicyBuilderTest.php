<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Scaling;

use PHPUnit\Framework\TestCase;
use Station\Enums\ScalingStrategy;
use Station\Scaling\ScalingPolicy;
use Station\Scaling\ScalingPolicyBuilder;

class ScalingPolicyBuilderTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // build() with defaults
    // ──────────────────────────────────────────────────────────────

    public function testBuildWithDefaultsProducesValidPolicy(): void
    {
        $policy = (new ScalingPolicyBuilder('default-policy'))->build();

        $this->assertInstanceOf(ScalingPolicy::class, $policy);
        $this->assertSame('default-policy', $policy->getName());
        $this->assertSame(ScalingStrategy::QueueSize->value, $policy->getStrategy());
        $this->assertSame(1, $policy->getMinWorkers());
        $this->assertSame(10, $policy->getMaxWorkers());
        $this->assertSame(60, $policy->getCooldownSeconds());
    }

    // ──────────────────────────────────────────────────────────────
    // Fluent setters
    // ──────────────────────────────────────────────────────────────

    public function testStrategySetterReturnsBuilderAndSetsValue(): void
    {
        $builder = new ScalingPolicyBuilder('test');

        $result = $builder->strategy(ScalingStrategy::Throughput->value);

        $this->assertSame($builder, $result);
        $this->assertSame('throughput', $result->build()->getStrategy());
    }

    public function testMinWorkersSetterReturnsBuilderAndSetsValue(): void
    {
        $builder = new ScalingPolicyBuilder('test');

        $result = $builder->minWorkers(3);

        $this->assertSame($builder, $result);
        $this->assertSame(3, $result->build()->getMinWorkers());
    }

    public function testMaxWorkersSetterReturnsBuilderAndSetsValue(): void
    {
        $builder = new ScalingPolicyBuilder('test');

        $result = $builder->maxWorkers(25);

        $this->assertSame($builder, $result);
        $this->assertSame(25, $result->build()->getMaxWorkers());
    }

    public function testWorkersSetsMinAndMax(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->workers(2, 15)
            ->build();

        $this->assertSame(2, $policy->getMinWorkers());
        $this->assertSame(15, $policy->getMaxWorkers());
    }

    public function testCooldownSetsSeconds(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->cooldown(120)
            ->build();

        $this->assertSame(120, $policy->getCooldownSeconds());
    }

    // ──────────────────────────────────────────────────────────────
    // Scale-up / scale-down rules
    // ──────────────────────────────────────────────────────────────

    public function testScaleUpWhenAddsScaleUpRule(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scaleUpWhen('queue_size', '>', 100.0, 2)
            ->build();

        $thresholds = $policy->getThresholds();

        $this->assertCount(1, $thresholds['scale_up']);
        $this->assertSame('queue_size', $thresholds['scale_up'][0]['metric']);
        $this->assertSame('>', $thresholds['scale_up'][0]['operator']);
        $this->assertSame(100.0, $thresholds['scale_up'][0]['value']);
        $this->assertSame(2, $thresholds['scale_up'][0]['increment']);
    }

    public function testScaleDownWhenAddsScaleDownRule(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scaleDownWhen('queue_size', '<', 10.0, 1)
            ->build();

        $thresholds = $policy->getThresholds();

        $this->assertCount(1, $thresholds['scale_down']);
        $this->assertSame('queue_size', $thresholds['scale_down'][0]['metric']);
        $this->assertSame('<', $thresholds['scale_down'][0]['operator']);
        $this->assertSame(10.0, $thresholds['scale_down'][0]['value']);
        $this->assertSame(1, $thresholds['scale_down'][0]['decrement']);
    }

    public function testMultipleScaleRulesAccumulate(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scaleUpWhen('queue_size', '>', 100.0)
            ->scaleUpWhen('average_wait_time', '>', 30.0, 3)
            ->scaleDownWhen('queue_size', '<', 5.0)
            ->build();

        $thresholds = $policy->getThresholds();

        $this->assertCount(2, $thresholds['scale_up']);
        $this->assertCount(1, $thresholds['scale_down']);
    }

    // ──────────────────────────────────────────────────────────────
    // Convenience methods
    // ──────────────────────────────────────────────────────────────

    public function testScaleUpWhenQueueExceedsUsesQueueSizeMetric(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scaleUpWhenQueueExceeds(500, 3)
            ->build();

        $rule = $policy->getThresholds()['scale_up'][0];

        $this->assertSame('queue_size', $rule['metric']);
        $this->assertSame('>', $rule['operator']);
        $this->assertSame(500.0, $rule['value']);
        $this->assertSame(3, $rule['increment']);
    }

    public function testScaleDownWhenQueueBelowUsesQueueSizeMetric(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scaleDownWhenQueueBelow(5, 2)
            ->build();

        $rule = $policy->getThresholds()['scale_down'][0];

        $this->assertSame('queue_size', $rule['metric']);
        $this->assertSame('<', $rule['operator']);
        $this->assertSame(5.0, $rule['value']);
        $this->assertSame(2, $rule['decrement']);
    }

    public function testScaleUpWhenWaitTimeExceedsUsesWaitTimeMetric(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scaleUpWhenWaitTimeExceeds(30.0, 2)
            ->build();

        $rule = $policy->getThresholds()['scale_up'][0];

        $this->assertSame('average_wait_time', $rule['metric']);
        $this->assertSame('>', $rule['operator']);
        $this->assertSame(30.0, $rule['value']);
    }

    public function testScaleDownWhenWaitTimeBelowUsesWaitTimeMetric(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scaleDownWhenWaitTimeBelow(5.0, 1)
            ->build();

        $rule = $policy->getThresholds()['scale_down'][0];

        $this->assertSame('average_wait_time', $rule['metric']);
        $this->assertSame('<', $rule['operator']);
    }

    // ──────────────────────────────────────────────────────────────
    // Schedule methods
    // ──────────────────────────────────────────────────────────────

    public function testScheduleWorkersAddsScheduleRule(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->scheduleWorkers(5, [9, 10, 11], [1, 2, 3, 4, 5])
            ->build();

        $schedule = $policy->getSchedule();

        $this->assertCount(1, $schedule);
        $this->assertSame(5, $schedule[0]['workers']);
        $this->assertSame([9, 10, 11], $schedule[0]['hours']);
        $this->assertSame([1, 2, 3, 4, 5], $schedule[0]['days']);
    }

    public function testPeakHoursUsesScheduleWorkers(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->peakHours([9, 10, 11, 12, 13, 14, 15, 16, 17], 8)
            ->build();

        $schedule = $policy->getSchedule();

        $this->assertSame(8, $schedule[0]['workers']);
        $this->assertSame([9, 10, 11, 12, 13, 14, 15, 16, 17], $schedule[0]['hours']);
        $this->assertSame([], $schedule[0]['days']);
    }

    public function testOffPeakHoursUsesScheduleWorkers(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->offPeakHours([0, 1, 2, 3, 4, 5], 2)
            ->build();

        $schedule = $policy->getSchedule();

        $this->assertSame(2, $schedule[0]['workers']);
        $this->assertSame([0, 1, 2, 3, 4, 5], $schedule[0]['hours']);
    }

    public function testWeekdaysSchedulesForDays1Through5(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->weekdays(6)
            ->build();

        $schedule = $policy->getSchedule();

        $this->assertSame(6, $schedule[0]['workers']);
        $this->assertSame([], $schedule[0]['hours']);
        $this->assertSame([1, 2, 3, 4, 5], $schedule[0]['days']);
    }

    public function testWeekendsSchedulesForDays6And7(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->weekends(2)
            ->build();

        $schedule = $policy->getSchedule();

        $this->assertSame(2, $schedule[0]['workers']);
        $this->assertSame([6, 7], $schedule[0]['days']);
    }

    public function testMultipleScheduleRulesAccumulate(): void
    {
        $policy = (new ScalingPolicyBuilder('test'))
            ->weekdays(8)
            ->weekends(3)
            ->peakHours([9, 10], 12)
            ->build();

        $this->assertCount(3, $policy->getSchedule());
    }

    // ──────────────────────────────────────────────────────────────
    // Full fluent chain
    // ──────────────────────────────────────────────────────────────

    public function testFullFluentChainProducesCorrectPolicy(): void
    {
        $policy = (new ScalingPolicyBuilder('production'))
            ->strategy(ScalingStrategy::Combined->value)
            ->workers(2, 20)
            ->cooldown(90)
            ->scaleUpWhenQueueExceeds(100, 2)
            ->scaleDownWhenQueueBelow(5)
            ->scaleUpWhenWaitTimeExceeds(30.0)
            ->weekdays(5)
            ->weekends(2)
            ->build();

        $this->assertSame('production', $policy->getName());
        $this->assertSame('combined', $policy->getStrategy());
        $this->assertSame(2, $policy->getMinWorkers());
        $this->assertSame(20, $policy->getMaxWorkers());
        $this->assertSame(90, $policy->getCooldownSeconds());
        $this->assertCount(2, $policy->getThresholds()['scale_up']);
        $this->assertCount(1, $policy->getThresholds()['scale_down']);
        $this->assertCount(2, $policy->getSchedule());
    }
}
