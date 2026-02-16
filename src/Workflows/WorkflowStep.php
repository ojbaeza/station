<?php

declare(strict_types=1);

namespace Station\Workflows;

/**
 * Represents a single step in a workflow definition.
 */
final class WorkflowStep
{
    public function __construct(
        private readonly string $name,
        /** @var class-string|null */
        private readonly ?string $jobClass,
        /** @var array<string> */
        private readonly array $dependencies = [],
        /** @var callable|null */
        private readonly mixed $condition = null,
        /** @var array<string, class-string>|null */
        private readonly ?array $branches = null,
        /** @var callable|null */
        private readonly mixed $branchSelector = null,
        private readonly ?int $timeout = null,
        private readonly int $retries = 3,
        private readonly int $retryDelay = 60,
        private readonly ?string $parallelGroup = null,
        private readonly bool $virtual = false,
    ) {}

    /**
     * Get the step name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the job class.
     *
     * @return class-string|null
     */
    public function getJobClass(): ?string
    {
        return $this->jobClass;
    }

    /**
     * Check if this is a virtual completion step (no job to run).
     */
    public function isVirtual(): bool
    {
        return $this->virtual;
    }

    /**
     * Get the dependencies.
     *
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Check if step has a condition.
     */
    public function hasCondition(): bool
    {
        return $this->condition !== null;
    }

    /**
     * Evaluate the condition with context.
     *
     * @param array<string, mixed> $context
     */
    public function shouldRun(array $context): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return (bool) \call_user_func($this->condition, $context);
    }

    /**
     * Check if this is a branch step.
     */
    public function isBranch(): bool
    {
        return $this->branches !== null;
    }

    /**
     * Get the branches.
     *
     * @return array<string, class-string>|null
     */
    public function getBranches(): ?array
    {
        return $this->branches;
    }

    /**
     * Select which branch to execute.
     *
     * @param array<string, mixed> $context
     */
    public function selectBranch(array $context): ?string
    {
        if ($this->branchSelector === null) {
            return null;
        }

        return \call_user_func($this->branchSelector, $context);
    }

    /**
     * Get the timeout.
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * Get the retry count.
     */
    public function getRetries(): int
    {
        return $this->retries;
    }

    /**
     * Get the retry delay.
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Get the parallel group name.
     */
    public function getParallelGroup(): ?string
    {
        return $this->parallelGroup;
    }

    /**
     * Check if this step is part of a parallel group.
     */
    public function isParallel(): bool
    {
        return $this->parallelGroup !== null;
    }

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'job_class' => $this->jobClass,
            'dependencies' => $this->dependencies,
            'has_condition' => $this->condition !== null,
            'is_branch' => $this->isBranch(),
            'branches' => $this->branches,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
            'retry_delay' => $this->retryDelay,
            'parallel_group' => $this->parallelGroup,
            'virtual' => $this->virtual,
        ];
    }
}
