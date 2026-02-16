<?php

declare(strict_types=1);

namespace Station\Alerts\Evaluators;

use Station\Contracts\MetricsCollectorInterface;
use Station\DTOs\AlertEvaluation;
use Station\DTOs\AlertRule;
use Station\Enums\AlertSeverity;

final class HighFailureRateEvaluator implements AlertEvaluatorInterface
{
    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function evaluate(AlertRule $rule): ?AlertEvaluation
    {
        $threshold = (float) ($rule->condition['threshold'] ?? 10);
        $windowMinutes = (int) ceil($rule->window / 60);

        $aggregated = $this->metrics->getAggregatedForPeriod("{$windowMinutes}m");
        $failureRate = $aggregated->failure_rate * 100;

        if ($failureRate < $threshold) {
            return null;
        }

        $severity = $failureRate >= ($rule->condition['critical_threshold'] ?? 50)
            ? AlertSeverity::Critical
            : AlertSeverity::Warning;

        return new AlertEvaluation(
            message: \sprintf(
                'Failure rate is %.1f%% (threshold: %.1f%%) over the last %d minutes. %d jobs processed, %d failed.',
                $failureRate,
                $threshold,
                $windowMinutes,
                $aggregated->jobs_processed,
                $aggregated->jobs_failed,
            ),
            severity: $severity,
            context: [
                'failure_rate' => $failureRate,
                'threshold' => $threshold,
                'jobs_processed' => $aggregated->jobs_processed,
                'jobs_failed' => $aggregated->jobs_failed,
                'window_minutes' => $windowMinutes,
            ],
        );
    }
}
