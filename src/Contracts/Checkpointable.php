<?php

declare(strict_types=1);

namespace Station\Contracts;

/**
 * Interface for jobs that support checkpointing.
 *
 * Implement this interface on long-running jobs to enable
 * progress saving and resumption after failures.
 */
interface Checkpointable
{
    /**
     * Get the current checkpoint data.
     *
     * This method is called periodically during job execution
     * to save progress. Return an array of data needed to
     * resume processing from this point.
     *
     * @return array<string, mixed>
     */
    public function checkpoint(): array;

    /**
     * Restore state from a checkpoint.
     *
     * This method is called before handle() when resuming
     * a job from a checkpoint. Use it to restore internal
     * state from the saved checkpoint data.
     *
     * @param array<string, mixed> $data
     */
    public function restore(array $data): void;

    /**
     * Check if the job has more work to do.
     *
     * Return false when the job has completed all work,
     * or true if there's more processing remaining.
     */
    public function hasMoreWork(): bool;
}
