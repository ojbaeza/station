<?php

declare(strict_types=1);

namespace Station\Workflows;

/**
 * Placeholder job for workflow branching logic.
 *
 * This is used internally by the workflow system when a branch step is defined.
 * The actual job class is selected dynamically based on the branch selector.
 */
final class WorkflowBranchJob
{
    public ?string $workflowInstanceId = null;

    public ?string $workflowStepName = null;

    /** @var array<string, mixed> */
    private array $contextUpdates = [];

    public function __construct(
        private readonly string $instanceId,
        /** @var array<string, mixed> */
        private readonly array $context,
        /** @var array<string, mixed> */
        private readonly array $results = [],
    ) {}

    /**
     * This job doesn't actually run - the branch selector determines which job runs.
     */
    public function handle(): void
    {
        // No-op - actual job is selected via branch selector
    }

    /**
     * Get any context updates from this job.
     *
     * @return array<string, mixed>
     */
    public function getContextUpdates(): array
    {
        return $this->contextUpdates;
    }
}
