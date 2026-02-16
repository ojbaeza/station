<?php

declare(strict_types=1);

namespace Station\Alerts\Evaluators;

use Station\Contracts\WorkerRepositoryInterface;
use Station\DTOs\AlertEvaluation;
use Station\DTOs\AlertRule;
use Station\Enums\AlertSeverity;

final class WorkerDownEvaluator implements AlertEvaluatorInterface
{
    public function __construct(
        private readonly WorkerRepositoryInterface $workerRepository,
    ) {}

    public function evaluate(AlertRule $rule): ?AlertEvaluation
    {
        $minWorkers = (int) ($rule->condition['min_workers'] ?? 1);

        $activeWorkers = $this->workerRepository->getActive();
        $count = $activeWorkers->count();

        if ($count >= $minWorkers) {
            return null;
        }

        $severity = $count === 0 ? AlertSeverity::Critical : AlertSeverity::Warning;

        return new AlertEvaluation(
            message: \sprintf(
                'Only %d active worker(s) detected (minimum: %d).',
                $count,
                $minWorkers,
            ),
            severity: $severity,
            context: [
                'active_workers' => $count,
                'min_workers' => $minWorkers,
            ],
        );
    }
}
