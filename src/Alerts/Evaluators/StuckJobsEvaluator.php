<?php

declare(strict_types=1);

namespace Station\Alerts\Evaluators;

use Station\Contracts\StuckJobDetectorInterface;
use Station\DTOs\AlertEvaluation;
use Station\DTOs\AlertRule;
use Station\Enums\AlertSeverity;

final class StuckJobsEvaluator implements AlertEvaluatorInterface
{
    public function __construct(
        private readonly StuckJobDetectorInterface $detector,
    ) {}

    public function evaluate(AlertRule $rule): ?AlertEvaluation
    {
        $threshold = (int) ($rule->condition['threshold'] ?? 1);

        $stuckJobs = $this->detector->detect();
        $count = $stuckJobs->count();

        if ($count < $threshold) {
            return null;
        }

        $severity = $count >= ($rule->condition['critical_threshold'] ?? 10)
            ? AlertSeverity::Critical
            : AlertSeverity::Warning;

        return new AlertEvaluation(
            message: \sprintf(
                '%d stuck job(s) detected (threshold: %d).',
                $count,
                $threshold,
            ),
            severity: $severity,
            context: [
                'stuck_count' => $count,
                'threshold' => $threshold,
                'job_ids' => $stuckJobs->take(10)->pluck('id')->all(),
            ],
        );
    }
}
