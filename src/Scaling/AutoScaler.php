<?php

declare(strict_types=1);

namespace Station\Scaling;

use Illuminate\Contracts\Events\Dispatcher;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Events\WorkersScaledDown;
use Station\Events\WorkersScaledUp;

/**
 * Automatically scales workers based on queue metrics.
 */
final class AutoScaler
{
    /** @var array<string, int> Current worker counts per queue */
    private array $currentWorkers = [];

    /** @var array<string, float> Last scale times per queue */
    private array $lastScaleTime = [];

    public function __construct(
        private readonly MetricsRepositoryInterface $metrics,
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {}

    /**
     * Evaluate and scale workers for all queues.
     *
     * @return array<string, array{action: string, from: int, to: int}>
     */
    public function scale(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        $queues = $this->getMonitoredQueues();

        foreach ($queues as $queue) {
            $result = $this->scaleQueue($queue);

            if ($result !== null) {
                $results[$queue] = $result;
            }
        }

        return $results;
    }

    /**
     * Scale workers for a specific queue.
     *
     * @return array{action: string, from: int, to: int}|null
     */
    public function scaleQueue(string $queue): ?array
    {
        $queueConfig = $this->getQueueConfig($queue);

        if (!$queueConfig['enabled']) {
            return null;
        }

        // Check cooldown
        if ($this->isInCooldown($queue)) {
            return null;
        }

        $currentWorkers = $this->getCurrentWorkers($queue);
        $targetWorkers = $this->calculateTargetWorkers($queue, $queueConfig);

        if ($targetWorkers === $currentWorkers) {
            return null;
        }

        // Apply min/max bounds
        $targetWorkers = max($queueConfig['min_workers'], $targetWorkers);
        $targetWorkers = min($queueConfig['max_workers'], $targetWorkers);

        if ($targetWorkers === $currentWorkers) {
            return null;
        }

        $action = $targetWorkers > $currentWorkers ? 'scale_up' : 'scale_down';
        $this->setCurrentWorkers($queue, $targetWorkers);
        $this->updateLastScaleTime($queue);

        // Dispatch events
        if ($action === 'scale_up') {
            $this->events->dispatch(new WorkersScaledUp($queue, $currentWorkers, $targetWorkers));
        } else {
            $this->events->dispatch(new WorkersScaledDown($queue, $currentWorkers, $targetWorkers));
        }

        return [
            'action' => $action,
            'from' => $currentWorkers,
            'to' => $targetWorkers,
        ];
    }

    /**
     * Check if scaling is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? false;
    }

    /**
     * Get current worker count for a queue.
     */
    public function getCurrentWorkers(string $queue): int
    {
        return $this->currentWorkers[$queue] ?? $this->getQueueConfig($queue)['min_workers'];
    }

    /**
     * Set current worker count for a queue.
     */
    public function setCurrentWorkers(string $queue, int $count): void
    {
        $this->currentWorkers[$queue] = $count;
    }

    /**
     * Get scaling recommendation for a queue (without applying).
     *
     * @return array{current: int, recommended: int, reason: string}
     */
    public function getRecommendation(string $queue): array
    {
        $queueConfig = $this->getQueueConfig($queue);
        $currentWorkers = $this->getCurrentWorkers($queue);
        $targetWorkers = $this->calculateTargetWorkers($queue, $queueConfig);

        // Apply bounds
        $targetWorkers = max($queueConfig['min_workers'], $targetWorkers);
        $targetWorkers = min($queueConfig['max_workers'], $targetWorkers);

        $reason = match (true) {
            $targetWorkers > $currentWorkers => 'Queue backlog detected',
            $targetWorkers < $currentWorkers => 'Queue is underutilized',
            default => 'Current capacity is optimal',
        };

        return [
            'current' => $currentWorkers,
            'recommended' => $targetWorkers,
            'reason' => $reason,
        ];
    }

    /**
     * Calculate target worker count based on metrics.
     *
     * @param array<string, mixed> $config
     */
    private function calculateTargetWorkers(string $queue, array $config): int
    {
        $metrics = $this->getQueueMetrics($queue);
        $strategy = $config['strategy'] ?? 'queue_size';

        return match ($strategy) {
            'queue_size' => $this->calculateByQueueSize($metrics, $config),
            'throughput' => $this->calculateByThroughput($metrics, $config),
            'wait_time' => $this->calculateByWaitTime($metrics, $config),
            'combined' => $this->calculateCombined($metrics, $config),
            default => $config['min_workers'],
        };
    }

    /**
     * Calculate workers based on queue size.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $config
     */
    private function calculateByQueueSize(array $metrics, array $config): int
    {
        $queueSize = $metrics['queue_size'] ?? 0;
        $jobsPerWorker = $config['jobs_per_worker'] ?? 100;

        return (int) ceil($queueSize / max(1, $jobsPerWorker));
    }

    /**
     * Calculate workers based on throughput requirements.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $config
     */
    private function calculateByThroughput(array $metrics, array $config): int
    {
        $currentThroughput = $metrics['throughput'] ?? 0;
        $targetThroughput = $config['target_throughput'] ?? 100;
        $throughputPerWorker = $config['throughput_per_worker'] ?? 10;
        $currentWorkers = $this->getCurrentWorkers($metrics['queue'] ?? 'default');

        if ($currentThroughput >= $targetThroughput) {
            return $currentWorkers;
        }

        $needed = (int) ceil(($targetThroughput - $currentThroughput) / max(1, $throughputPerWorker));

        return $currentWorkers + $needed;
    }

    /**
     * Calculate workers based on wait time.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $config
     */
    private function calculateByWaitTime(array $metrics, array $config): int
    {
        $averageWaitTime = $metrics['average_wait_time'] ?? 0;
        $maxWaitTime = $config['max_wait_time'] ?? 30;
        $currentWorkers = $this->getCurrentWorkers($metrics['queue'] ?? 'default');

        if ($averageWaitTime <= $maxWaitTime) {
            // Could potentially scale down
            if ($averageWaitTime < $maxWaitTime * 0.5 && $currentWorkers > $config['min_workers']) {
                return $currentWorkers - 1;
            }

            return $currentWorkers;
        }

        // Need to scale up
        $ratio = $averageWaitTime / max(1, $maxWaitTime);

        return (int) ceil($currentWorkers * $ratio);
    }

    /**
     * Calculate workers using combined strategy.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $config
     */
    private function calculateCombined(array $metrics, array $config): int
    {
        $weights = $config['weights'] ?? [
            'queue_size' => 0.4,
            'throughput' => 0.3,
            'wait_time' => 0.3,
        ];

        $queueSizeWorkers = $this->calculateByQueueSize($metrics, $config);
        $throughputWorkers = $this->calculateByThroughput($metrics, $config);
        $waitTimeWorkers = $this->calculateByWaitTime($metrics, $config);

        $weighted = ($queueSizeWorkers * $weights['queue_size'])
            + ($throughputWorkers * $weights['throughput'])
            + ($waitTimeWorkers * $weights['wait_time']);

        return (int) round($weighted);
    }

    /**
     * Get current metrics for a queue.
     *
     * @return array<string, mixed>
     */
    private function getQueueMetrics(string $queue): array
    {
        $recentMetrics = $this->metrics->getRecent($queue, 5);

        if (empty($recentMetrics)) {
            return [
                'queue' => $queue,
                'queue_size' => 0,
                'throughput' => 0,
                'average_wait_time' => 0,
            ];
        }

        $totalSize = 0;
        $totalThroughput = 0;
        $totalWaitTime = 0;

        foreach ($recentMetrics as $metric) {
            $totalSize += $metric['queue_size'] ?? 0;
            $totalThroughput += $metric['jobs_processed'] ?? 0;
            $totalWaitTime += $metric['average_wait_time'] ?? 0;
        }

        $count = \count($recentMetrics);

        return [
            'queue' => $queue,
            'queue_size' => (int) ($totalSize / $count),
            'throughput' => (int) ($totalThroughput / $count),
            'average_wait_time' => $totalWaitTime / $count,
        ];
    }

    /**
     * Get monitored queues.
     *
     * @return array<string>
     */
    private function getMonitoredQueues(): array
    {
        return $this->config['queues'] ?? ['default'];
    }

    /**
     * Get configuration for a specific queue.
     *
     * @return array<string, mixed>
     */
    private function getQueueConfig(string $queue): array
    {
        $defaults = [
            'enabled' => true,
            'min_workers' => 1,
            'max_workers' => 10,
            'strategy' => 'queue_size',
            'jobs_per_worker' => 100,
            'cooldown' => 60,
        ];

        $queueConfig = $this->config['queue_config'][$queue] ?? [];

        return array_merge($defaults, $queueConfig);
    }

    /**
     * Check if queue is in cooldown period.
     */
    private function isInCooldown(string $queue): bool
    {
        $lastScale = $this->lastScaleTime[$queue] ?? 0;
        $cooldown = $this->getQueueConfig($queue)['cooldown'];

        return (microtime(true) - $lastScale) < $cooldown;
    }

    /**
     * Update last scale time.
     */
    private function updateLastScaleTime(string $queue): void
    {
        $this->lastScaleTime[$queue] = microtime(true);
    }
}
