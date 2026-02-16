<?php

declare(strict_types=1);

namespace Station\Alerts\Evaluators;

use Station\Contracts\MetricsCollectorInterface;
use Station\DTOs\AlertEvaluation;
use Station\DTOs\AlertRule;
use Station\Enums\AlertSeverity;

final class QueueBackupEvaluator implements AlertEvaluatorInterface
{
    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function evaluate(AlertRule $rule): ?AlertEvaluation
    {
        $threshold = (int) ($rule->condition['threshold'] ?? 1000);
        $queue = $rule->condition['queue'] ?? null;

        $queueStats = $this->metrics->getQueueStats();

        $backedUpQueues = [];

        foreach ($queueStats as $name => $stats) {
            if ($queue !== null && $name !== $queue) {
                continue;
            }

            if ($stats->size >= $threshold) {
                $backedUpQueues[$name] = $stats->size;
            }
        }

        if ($backedUpQueues === []) {
            return null;
        }

        $maxSize = max($backedUpQueues);
        $severity = $maxSize >= ($rule->condition['critical_threshold'] ?? $threshold * 5)
            ? AlertSeverity::Critical
            : AlertSeverity::Warning;

        $queueList = implode(', ', array_map(
            static fn(string $name, int $size): string => "{$name} ({$size})",
            array_keys($backedUpQueues),
            array_values($backedUpQueues),
        ));

        return new AlertEvaluation(
            message: \sprintf(
                'Queue backup detected (threshold: %d). Backed up queues: %s',
                $threshold,
                $queueList,
            ),
            severity: $severity,
            context: [
                'threshold' => $threshold,
                'backed_up_queues' => $backedUpQueues,
            ],
        );
    }
}
