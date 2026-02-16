<?php

declare(strict_types=1);

namespace Station\Workflows;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use Station\Enums\WorkflowStatus;
use Station\Enums\WorkflowStepStatus;

/**
 * Represents a running instance of a workflow.
 */
final class WorkflowInstance
{
    private string $id;

    private string $status = WorkflowStatus::Pending->value;

    private ?string $currentStep = null;

    /** @var array<string, string> Step name => status */
    private array $stepStatuses = [];

    /** @var array<string, mixed> Shared context across steps */
    private array $context = [];

    /** @var array<string, mixed> Results from completed steps */
    private array $results = [];

    /** @var array<int, array<string, mixed>> Snapshot of definition steps at execution time */
    private array $definitionSteps = [];

    private ?string $connection = null;

    private ?string $error = null;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $startedAt = null;

    private ?DateTimeImmutable $completedAt = null;

    public function __construct(
        private readonly string $definitionId,
        private readonly string $definitionName,
        /** @var array<string, mixed> Initial input data */
        private readonly array $input = [],
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->context = $input;
    }

    /**
     * Create from array (for hydration from storage).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self(
            $data['definition_id'],
            $data['definition_name'],
            $data['input'] ?? [],
        );

        // Use reflection to set private properties
        $reflection = new ReflectionClass($instance);

        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($instance, $data['id']);

        $instance->status = $data['status'];
        $instance->currentStep = $data['current_step'] ?? null;
        $instance->connection = $data['connection'] ?? null;
        $instance->context = $data['context'] ?? [];
        $instance->results = $data['results'] ?? [];
        $instance->stepStatuses = $data['step_statuses'] ?? [];
        $instance->definitionSteps = $data['definition_steps'] ?? [];
        $instance->error = $data['error'] ?? null;

        if (isset($data['created_at'])) {
            $createdAtProperty = $reflection->getProperty('createdAt');
            $createdAtProperty->setAccessible(true);
            $createdAtProperty->setValue($instance, new DateTimeImmutable($data['created_at']));
        }

        if (isset($data['started_at'])) {
            $instance->startedAt = new DateTimeImmutable($data['started_at']);
        }

        if (isset($data['completed_at'])) {
            $instance->completedAt = new DateTimeImmutable($data['completed_at']);
        }

        return $instance;
    }

    /**
     * Get the instance ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the definition ID.
     */
    public function getDefinitionId(): string
    {
        return $this->definitionId;
    }

    /**
     * Get the definition name.
     */
    public function getDefinitionName(): string
    {
        return $this->definitionName;
    }

    /**
     * Get the connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /**
     * Set the connection name.
     */
    public function setConnection(?string $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Get the status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get the current step.
     */
    public function getCurrentStep(): ?string
    {
        return $this->currentStep;
    }

    /**
     * Get the input data.
     *
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        return $this->input;
    }

    /**
     * Get the shared context.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get a value from context.
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /**
     * Set a value in context.
     */
    public function setContextValue(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * Merge data into context.
     *
     * @param array<string, mixed> $data
     */
    public function mergeContext(array $data): void
    {
        $this->context = array_merge($this->context, $data);
    }

    /**
     * Get results from completed steps.
     *
     * @return array<string, mixed>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get result from a specific step.
     */
    public function getStepResult(string $step): mixed
    {
        return $this->results[$step] ?? null;
    }

    /**
     * Set the result for a step.
     */
    public function setStepResult(string $step, mixed $result): void
    {
        $this->results[$step] = $result;
    }

    /**
     * Get the error message.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Get step statuses.
     *
     * @return array<string, string>
     */
    public function getStepStatuses(): array
    {
        return $this->stepStatuses;
    }

    /**
     * Get the snapshotted definition steps.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefinitionSteps(): array
    {
        return $this->definitionSteps;
    }

    /**
     * Set the snapshotted definition steps.
     *
     * @param array<int, array<string, mixed>> $steps
     */
    public function setDefinitionSteps(array $steps): void
    {
        $this->definitionSteps = $steps;
    }

    /**
     * Get status for a specific step.
     */
    public function getStepStatus(string $step): ?string
    {
        return $this->stepStatuses[$step] ?? null;
    }

    /**
     * Check if a step is completed.
     */
    public function isStepCompleted(string $step): bool
    {
        return ($this->stepStatuses[$step] ?? null) === WorkflowStepStatus::Completed->value;
    }

    /**
     * Check if all dependencies for a step are completed.
     *
     * @param array<string> $dependencies
     */
    public function areDependenciesCompleted(array $dependencies): bool
    {
        foreach ($dependencies as $dep) {
            if (!$this->isStepCompleted($dep)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if all dependencies for a step are resolved (completed or skipped).
     *
     * @param array<string> $dependencies
     */
    public function areDependenciesResolved(array $dependencies): bool
    {
        foreach ($dependencies as $dep) {
            $status = $this->stepStatuses[$dep] ?? null;

            if ($status !== WorkflowStepStatus::Completed->value && $status !== WorkflowStepStatus::Skipped->value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark a step as queued (dispatched to queue, not yet picked up).
     */
    public function queueStep(string $step): void
    {
        $this->stepStatuses[$step] = WorkflowStepStatus::Queued->value;
    }

    /**
     * Mark the workflow as started.
     */
    public function start(): void
    {
        $this->status = WorkflowStatus::Running->value;
        $this->startedAt = new DateTimeImmutable();
    }

    /**
     * Mark a step as started.
     */
    public function startStep(string $step): void
    {
        $this->currentStep = $step;
        $this->stepStatuses[$step] = WorkflowStepStatus::Running->value;
    }

    /**
     * Mark a step as completed.
     */
    public function completeStep(string $step, mixed $result = null): void
    {
        $this->stepStatuses[$step] = WorkflowStepStatus::Completed->value;

        if ($result !== null) {
            $this->results[$step] = $result;
        }

        if ($this->currentStep === $step) {
            $this->currentStep = null;
        }
    }

    /**
     * Mark a step as failed.
     */
    public function failStep(string $step, string $error): void
    {
        $this->stepStatuses[$step] = WorkflowStepStatus::Failed->value;
        $this->error = "Step '{$step}' failed: {$error}";
    }

    /**
     * Mark a step as skipped (condition not met).
     */
    public function skipStep(string $step): void
    {
        $this->stepStatuses[$step] = WorkflowStepStatus::Skipped->value;
    }

    /**
     * Mark the workflow as completed.
     */
    public function complete(): void
    {
        $this->status = WorkflowStatus::Completed->value;
        $this->completedAt = new DateTimeImmutable();
        $this->currentStep = null;
    }

    /**
     * Mark the workflow as failed.
     */
    public function fail(string $error): void
    {
        $this->status = WorkflowStatus::Failed->value;
        $this->error = $error;
        $this->completedAt = new DateTimeImmutable();
    }

    /**
     * Mark the workflow as cancelled.
     */
    public function cancel(): void
    {
        $this->status = WorkflowStatus::Cancelled->value;
        $this->completedAt = new DateTimeImmutable();
    }

    /**
     * Pause the workflow.
     */
    public function pause(): void
    {
        $this->status = WorkflowStatus::Paused->value;
    }

    /**
     * Resume the workflow.
     */
    public function resume(): void
    {
        $this->status = WorkflowStatus::Running->value;
    }

    /**
     * Check if the workflow is finished (completed, failed, or cancelled).
     */
    public function isFinished(): bool
    {
        return \in_array($this->status, [
            WorkflowStatus::Completed->value,
            WorkflowStatus::Failed->value,
            WorkflowStatus::Cancelled->value,
        ], true);
    }

    /**
     * Calculate progress percentage.
     */
    public function getProgress(): int
    {
        $total = \count($this->stepStatuses);

        if ($total === 0) {
            return 0;
        }

        $completed = 0;

        foreach ($this->stepStatuses as $status) {
            if (\in_array($status, [WorkflowStepStatus::Completed->value, WorkflowStepStatus::Skipped->value], true)) {
                $completed++;
            }
        }

        return (int) round(($completed / $total) * 100);
    }

    /**
     * Get created at timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Get started at timestamp.
     */
    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * Get completed at timestamp.
     */
    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Get the duration in seconds.
     */
    public function getDuration(): ?int
    {
        if ($this->startedAt === null) {
            return null;
        }

        $end = $this->completedAt ?? new DateTimeImmutable();

        return $end->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'definition_id' => $this->definitionId,
            'definition_name' => $this->definitionName,
            'connection' => $this->connection,
            'status' => $this->status,
            'current_step' => $this->currentStep,
            'input' => $this->input,
            'context' => $this->context,
            'results' => $this->results,
            'step_statuses' => $this->stepStatuses,
            'definition_steps' => $this->definitionSteps,
            'error' => $this->error,
            'progress' => $this->getProgress(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt?->format('Y-m-d H:i:s'),
            'duration' => $this->getDuration(),
        ];
    }
}
