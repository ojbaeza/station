<?php

declare(strict_types=1);

namespace Station\Recovery;

use Illuminate\Contracts\Events\Dispatcher;
use Station\Contracts\CheckpointManagerInterface;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\JobResumerInterface;
use Station\Core\Job;
use Station\Events\JobRecovered;

final class JobResumer implements JobResumerInterface
{
    public function __construct(
        private readonly JobManagerInterface $jobManager,
        private readonly JobRepositoryInterface $repository,
        private readonly CheckpointManagerInterface $checkpointManager,
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config,
        private readonly ?HealthCheckerInterface $healthChecker = null,
    ) {}

    /**
     * Get the health checker instance.
     */
    public function health(): HealthCheckerInterface
    {
        if ($this->healthChecker === null) {
            return app(HealthCheckerInterface::class);
        }

        return $this->healthChecker;
    }

    /**
     * Check if recovery is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    /**
     * Resume a stuck job by ID.
     *
     * @param string $strategy Recovery strategy: 'graceful', 'restart', or 'checkpoint'
     */
    public function resume(string $jobId, string $strategy = 'graceful'): bool
    {
        $job = $this->repository->find($jobId);

        if ($job === null) {
            return false;
        }

        return $this->resumeJob($job, $strategy);
    }

    /**
     * Resume a stuck job object.
     */
    public function resumeJob(Job $job, string $strategy = 'graceful'): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return match ($strategy) {
            'graceful' => $this->tryGracefulRestart($job) || $this->tryForcedRestart($job),
            'restart' => $this->tryForcedRestart($job),
            'checkpoint' => $this->tryPartialRecovery($job) || $this->tryGracefulRestart($job) || $this->tryForcedRestart($job),
            default => $this->tryGracefulRestart($job) || $this->tryForcedRestart($job),
        };
    }

    /**
     * Recover all stuck jobs.
     */
    public function recoverAll(string $strategy = 'graceful'): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $timeout = $this->config['thresholds']['heartbeat_timeout'] ?? 90;
        $stuckJobs = $this->repository->getStuckJobs($timeout);
        $recovered = 0;

        foreach ($stuckJobs as $job) {
            if ($this->resumeJob($job, $strategy)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    /**
     * Try graceful restart (resume from checkpoint).
     */
    private function tryGracefulRestart(Job $job): bool
    {
        $checkpoint = $this->checkpointManager->get($job->id);

        if ($checkpoint === null) {
            return false;
        }

        // Release job back to queue with checkpoint data
        $this->jobManager->retry($job->id);

        $this->events->dispatch(new JobRecovered($job, 'graceful_restart', true));

        return true;
    }

    /**
     * Try forced restart (restart from beginning).
     */
    private function tryForcedRestart(Job $job): bool
    {
        // Delete any checkpoint
        $this->checkpointManager->delete($job->id);

        // Retry the job
        $this->jobManager->retry($job->id);

        $this->events->dispatch(new JobRecovered($job, 'forced_restart', false));

        return true;
    }

    /**
     * Try partial recovery (skip problematic items).
     */
    private function tryPartialRecovery(Job $job): bool
    {
        $checkpoint = $this->checkpointManager->get($job->id);

        if ($checkpoint === null) {
            return false;
        }

        // Mark last processed item as skipped in checkpoint
        if (isset($checkpoint['last_processed_id'])) {
            $checkpoint['skipped_ids'] = $checkpoint['skipped_ids'] ?? [];
            $checkpoint['skipped_ids'][] = $checkpoint['last_processed_id'];

            $this->checkpointManager->save($job->id, $checkpoint);
        }

        // Retry the job
        $this->jobManager->retry($job->id);

        $this->events->dispatch(new JobRecovered($job, 'partial_recovery', true));

        return true;
    }
}
