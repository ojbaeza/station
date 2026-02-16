<?php

declare(strict_types=1);

namespace Station\Recovery;

use Station\Contracts\CheckpointManagerInterface;
use Station\Contracts\CheckpointRepositoryInterface;

final class CheckpointManager implements CheckpointManagerInterface
{
    public function __construct(
        private readonly CheckpointRepositoryInterface $repository,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Check if checkpointing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    /**
     * Save a checkpoint for a job.
     *
     * @param array<string, mixed> $data
     */
    public function save(string $jobId, array $data): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->repository->save($jobId, $data);
    }

    /**
     * Get a checkpoint for a job.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $jobId): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return $this->repository->get($jobId);
    }

    /**
     * Delete a checkpoint.
     */
    public function delete(string $jobId): void
    {
        $this->repository->delete($jobId);
    }

    /**
     * Check if a checkpoint exists.
     */
    public function exists(string $jobId): bool
    {
        return $this->repository->exists($jobId);
    }

    /**
     * Prune old checkpoints.
     */
    public function prune(): int
    {
        $hours = $this->config['retention'] ?? 24;

        return $this->repository->prune($hours);
    }
}
