<?php

declare(strict_types=1);

namespace Station\Recovery;

use Illuminate\Support\Collection;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Core\Job;

final class StuckJobDetector implements StuckJobDetectorInterface
{
    public function __construct(
        private readonly JobRepositoryInterface $repository,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Check if stuck detection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    /**
     * Detect stuck jobs.
     *
     * @param array{queue?: string|null, threshold?: int} $options
     * @return Collection<int, Job>
     */
    public function detect(array $options = []): Collection
    {
        if (!$this->isEnabled()) {
            return collect();
        }

        $timeout = $options['threshold'] ?? $this->config['thresholds']['heartbeat_timeout'] ?? 90;
        $queue = $options['queue'] ?? null;

        $stuckJobs = $this->repository->getStuckJobs($timeout);

        // Filter by queue if specified
        if ($queue !== null) {
            $stuckJobs = $stuckJobs->filter(static fn(Job $job): bool => $job->queue === $queue);
        }

        return $stuckJobs->values();
    }

    /**
     * Calculate a "stuck score" for a job.
     *
     * Higher score means more likely to be stuck.
     */
    public function calculateStuckScore(Job $job): float
    {
        $weights = $this->config['weights'] ?? [
            'heartbeat' => 0.6,
            'runtime' => 0.4,
        ];

        $score = 0.0;

        if ($job->startedAt === null) {
            return $score;
        }

        $runtime = $job->startedAt->diffInSeconds(now());

        // Heartbeat check
        $heartbeatTimeout = $this->config['thresholds']['heartbeat_timeout'] ?? 90;
        if ($runtime > $heartbeatTimeout) {
            $score += $weights['heartbeat'];
        }

        // Runtime check
        $multiplier = $this->config['thresholds']['runtime_multiplier'] ?? 1.5;
        $expectedMax = $job->timeout * $multiplier;
        if ($runtime > $expectedMax) {
            $score += $weights['runtime'];
        }

        return $score;
    }

    /**
     * Check if a job should be flagged as stuck.
     */
    public function isStuck(Job $job): bool
    {
        $threshold = $this->config['stuck_threshold'] ?? 0.6;
        $score = $this->calculateStuckScore($job);

        return $score >= $threshold;
    }
}
