<?php

declare(strict_types=1);

namespace Station\Contracts;

use Illuminate\Support\Collection;
use Station\Core\Job;

interface StuckJobDetectorInterface
{
    /**
     * Check if stuck detection is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Detect stuck jobs.
     *
     * @param array{queue?: string|null, threshold?: int} $options
     * @return Collection<int, Job>
     */
    public function detect(array $options = []): Collection;

    /**
     * Calculate a "stuck score" for a job.
     *
     * Higher score means more likely to be stuck.
     */
    public function calculateStuckScore(Job $job): float;

    /**
     * Check if a job should be flagged as stuck.
     */
    public function isStuck(Job $job): bool;
}
