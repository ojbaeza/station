<?php

declare(strict_types=1);

namespace Station\Scaling;

use Station\Enums\ScalingStrategy;

/**
 * Defines a scaling policy for queue workers.
 */
final class ScalingPolicy
{
    public function __construct(
        private readonly string $name,
        private readonly string $strategy = ScalingStrategy::QueueSize->value,
        private readonly int $minWorkers = 1,
        private readonly int $maxWorkers = 10,
        private readonly int $cooldownSeconds = 60,
        /** @var array<string, mixed> */
        private readonly array $thresholds = [],
        /** @var array<int, array<string, mixed>> */
        private readonly array $schedule = [],
    ) {}

    /**
     * Create a new policy builder.
     */
    public static function create(string $name): ScalingPolicyBuilder
    {
        return new ScalingPolicyBuilder($name);
    }

    /**
     * Get the policy name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the scaling strategy.
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Get the minimum workers.
     */
    public function getMinWorkers(): int
    {
        return $this->minWorkers;
    }

    /**
     * Get the maximum workers.
     */
    public function getMaxWorkers(): int
    {
        return $this->maxWorkers;
    }

    /**
     * Get the cooldown period.
     */
    public function getCooldownSeconds(): int
    {
        return $this->cooldownSeconds;
    }

    /**
     * Get the thresholds.
     *
     * @return array<string, mixed>
     */
    public function getThresholds(): array
    {
        return $this->thresholds;
    }

    /**
     * Get the schedule for scheduled scaling.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSchedule(): array
    {
        return $this->schedule;
    }

    /**
     * Evaluate the policy with current metrics.
     *
     * @param array<string, mixed> $metrics
     */
    public function evaluate(array $metrics): int
    {
        return match ($this->strategy) {
            ScalingStrategy::Schedule->value => $this->evaluateSchedule(),
            default => $this->evaluateMetrics($metrics),
        };
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'strategy' => $this->strategy,
            'min_workers' => $this->minWorkers,
            'max_workers' => $this->maxWorkers,
            'cooldown_seconds' => $this->cooldownSeconds,
            'thresholds' => $this->thresholds,
            'schedule' => $this->schedule,
        ];
    }

    /**
     * Evaluate based on schedule.
     */
    private function evaluateSchedule(): int
    {
        $now = now();
        $hour = (int) $now->format('H');
        $dayOfWeek = (int) $now->format('N'); // 1 = Monday, 7 = Sunday

        // Check for specific time rules
        foreach ($this->schedule as $rule) {
            if ($this->matchesScheduleRule($rule, $hour, $dayOfWeek)) {
                return $rule['workers'];
            }
        }

        return $this->minWorkers;
    }

    /**
     * Check if a schedule rule matches.
     *
     * @param array<string, mixed> $rule
     */
    private function matchesScheduleRule(array $rule, int $hour, int $dayOfWeek): bool
    {
        $hours = $rule['hours'] ?? [];
        $days = $rule['days'] ?? [];

        if (!empty($hours) && !\in_array($hour, $hours, true)) {
            return false;
        }

        return !(!empty($days) && !\in_array($dayOfWeek, $days, true))

        ;
    }

    /**
     * Evaluate based on metrics.
     *
     * @param array<string, mixed> $metrics
     */
    private function evaluateMetrics(array $metrics): int
    {
        $scaleUp = $this->thresholds['scale_up'] ?? [];
        $scaleDown = $this->thresholds['scale_down'] ?? [];
        $currentWorkers = $metrics['current_workers'] ?? $this->minWorkers;

        // Check scale-up conditions
        foreach ($scaleUp as $condition) {
            if ($this->meetsCondition($condition, $metrics)) {
                $increment = $condition['increment'] ?? 1;

                return min($this->maxWorkers, $currentWorkers + $increment);
            }
        }

        // Check scale-down conditions
        foreach ($scaleDown as $condition) {
            if ($this->meetsCondition($condition, $metrics)) {
                $decrement = $condition['decrement'] ?? 1;

                return max($this->minWorkers, $currentWorkers - $decrement);
            }
        }

        return $currentWorkers;
    }

    /**
     * Check if a condition is met.
     *
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $metrics
     */
    private function meetsCondition(array $condition, array $metrics): bool
    {
        $metric = $condition['metric'] ?? null;
        $operator = $condition['operator'] ?? '>';
        $value = $condition['value'] ?? 0;

        if ($metric === null || !isset($metrics[$metric])) {
            return false;
        }

        $actual = $metrics[$metric];

        return match ($operator) {
            '>' => $actual > $value,
            '>=' => $actual >= $value,
            '<' => $actual < $value,
            '<=' => $actual <= $value,
            '==' => $actual === $value,
            '!=' => $actual !== $value,
            default => false,
        };
    }
}
