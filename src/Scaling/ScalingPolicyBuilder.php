<?php

declare(strict_types=1);

namespace Station\Scaling;

use Station\Enums\ScalingStrategy;

/**
 * Fluent builder for creating scaling policies.
 */
final class ScalingPolicyBuilder
{
    private string $strategy = ScalingStrategy::QueueSize->value;

    private int $minWorkers = 1;

    private int $maxWorkers = 10;

    private int $cooldownSeconds = 60;

    /** @var array<string, mixed> */
    private array $thresholds = [
        'scale_up' => [],
        'scale_down' => [],
    ];

    /** @var array<int, array<string, mixed>> */
    private array $schedule = [];

    public function __construct(
        private readonly string $name,
    ) {}

    /**
     * Set the scaling strategy.
     */
    public function strategy(string $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Set the minimum workers.
     */
    public function minWorkers(int $count): self
    {
        $this->minWorkers = $count;

        return $this;
    }

    /**
     * Set the maximum workers.
     */
    public function maxWorkers(int $count): self
    {
        $this->maxWorkers = $count;

        return $this;
    }

    /**
     * Set min and max workers.
     */
    public function workers(int $min, int $max): self
    {
        $this->minWorkers = $min;
        $this->maxWorkers = $max;

        return $this;
    }

    /**
     * Set the cooldown period.
     */
    public function cooldown(int $seconds): self
    {
        $this->cooldownSeconds = $seconds;

        return $this;
    }

    /**
     * Add a scale-up rule.
     */
    public function scaleUpWhen(string $metric, string $operator, float $value, int $increment = 1): self
    {
        $this->thresholds['scale_up'][] = [
            'metric' => $metric,
            'operator' => $operator,
            'value' => $value,
            'increment' => $increment,
        ];

        return $this;
    }

    /**
     * Add a scale-down rule.
     */
    public function scaleDownWhen(string $metric, string $operator, float $value, int $decrement = 1): self
    {
        $this->thresholds['scale_down'][] = [
            'metric' => $metric,
            'operator' => $operator,
            'value' => $value,
            'decrement' => $decrement,
        ];

        return $this;
    }

    /**
     * Scale up when queue size exceeds threshold.
     */
    public function scaleUpWhenQueueExceeds(int $size, int $increment = 1): self
    {
        return $this->scaleUpWhen('queue_size', '>', $size, $increment);
    }

    /**
     * Scale down when queue size falls below threshold.
     */
    public function scaleDownWhenQueueBelow(int $size, int $decrement = 1): self
    {
        return $this->scaleDownWhen('queue_size', '<', $size, $decrement);
    }

    /**
     * Scale up when wait time exceeds threshold.
     */
    public function scaleUpWhenWaitTimeExceeds(float $seconds, int $increment = 1): self
    {
        return $this->scaleUpWhen('average_wait_time', '>', $seconds, $increment);
    }

    /**
     * Scale down when wait time is below threshold.
     */
    public function scaleDownWhenWaitTimeBelow(float $seconds, int $decrement = 1): self
    {
        return $this->scaleDownWhen('average_wait_time', '<', $seconds, $decrement);
    }

    /**
     * Add a scheduled scaling rule.
     *
     * @param array<int> $hours Hours of the day (0-23)
     * @param array<int> $days Days of the week (1-7, Monday-Sunday)
     */
    public function scheduleWorkers(int $workers, array $hours = [], array $days = []): self
    {
        $this->schedule[] = [
            'workers' => $workers,
            'hours' => $hours,
            'days' => $days,
        ];

        return $this;
    }

    /**
     * Scale up during peak hours.
     *
     * @param array<int> $peakHours
     */
    public function peakHours(array $peakHours, int $workers): self
    {
        return $this->scheduleWorkers($workers, $peakHours);
    }

    /**
     * Scale down during off-peak hours.
     *
     * @param array<int> $offPeakHours
     */
    public function offPeakHours(array $offPeakHours, int $workers): self
    {
        return $this->scheduleWorkers($workers, $offPeakHours);
    }

    /**
     * Scale for weekdays.
     */
    public function weekdays(int $workers): self
    {
        return $this->scheduleWorkers($workers, [], [1, 2, 3, 4, 5]);
    }

    /**
     * Scale for weekends.
     */
    public function weekends(int $workers): self
    {
        return $this->scheduleWorkers($workers, [], [6, 7]);
    }

    /**
     * Build the policy.
     */
    public function build(): ScalingPolicy
    {
        return new ScalingPolicy(
            name: $this->name,
            strategy: $this->strategy,
            minWorkers: $this->minWorkers,
            maxWorkers: $this->maxWorkers,
            cooldownSeconds: $this->cooldownSeconds,
            thresholds: $this->thresholds,
            schedule: $this->schedule,
        );
    }
}
