<?php

declare(strict_types=1);

namespace Station\Contracts;

interface CheckpointRepositoryInterface
{
    /**
     * Save a checkpoint for a job.
     *
     * @param array<string, mixed> $data
     */
    public function save(string $jobId, array $data): void;

    /**
     * Get a checkpoint for a job.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $jobId): ?array;

    /**
     * Delete a checkpoint.
     */
    public function delete(string $jobId): void;

    /**
     * Check if a checkpoint exists.
     */
    public function exists(string $jobId): bool;

    /**
     * Prune old checkpoints.
     */
    public function prune(int $hours): int;
}
