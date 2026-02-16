<?php

declare(strict_types=1);

namespace Station\Contracts;

use Station\Core\Job;

interface JobResumerInterface
{
    /**
     * Check if recovery is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Resume a stuck job by ID.
     *
     * @param string $strategy Recovery strategy: 'graceful', 'restart', or 'checkpoint'
     */
    public function resume(string $jobId, string $strategy = 'graceful'): bool;

    /**
     * Resume a stuck job object.
     */
    public function resumeJob(Job $job, string $strategy = 'graceful'): bool;

    /**
     * Recover all stuck jobs.
     */
    public function recoverAll(string $strategy = 'graceful'): int;

    /**
     * Get the health checker instance.
     */
    public function health(): HealthCheckerInterface;
}
