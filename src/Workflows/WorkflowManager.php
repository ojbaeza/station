<?php

declare(strict_types=1);

namespace Station\Workflows;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Station\DTOs\WorkflowInstanceRow;
use Station\Enums\WorkflowStatus;
use Station\Enums\WorkflowStepStatus;
use Station\Events\WorkflowCompleted;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStarted;
use Station\Events\WorkflowStepCompleted;
use Station\Workflows\Jobs\RunWorkflowJob;
use Station\Workflows\Jobs\WorkflowStepJob;
use Throwable;

/**
 * Manages workflow definitions and instances.
 */
final class WorkflowManager
{
    /** @var array<string, true> Tracks which instances have been inserted (avoids wasted SELECT in updateOrInsert) */
    private array $persistedInstances = [];

    /** @var array<string, WorkflowDefinition> In-memory cache of definitions */
    private array $definitions = [];

    public function __construct(
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {}

    /**
     * Reset in-memory state (call after fork to avoid parent state leaking into child).
     */
    public function resetState(): void
    {
        $this->persistedInstances = [];
    }

    /**
     * Define a new workflow.
     */
    public function define(string $name): WorkflowDefinition
    {
        $definition = WorkflowDefinition::define($name);
        $this->definitions[$name] = $definition;

        return $definition;
    }

    /**
     * Register a workflow definition.
     */
    public function register(WorkflowDefinition $definition): void
    {
        $definition->validate();
        $this->definitions[$definition->getName()] = $definition;
    }

    /**
     * Get a workflow definition by name.
     */
    public function getDefinition(string $name): ?WorkflowDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    /**
     * Get all registered definitions.
     *
     * @return array<string, WorkflowDefinition>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Run a workflow synchronously.
     *
     * @param array<string, mixed> $input
     */
    public function run(string $definitionName, array $input = []): WorkflowInstance
    {
        $definition = $this->getDefinition($definitionName);

        if ($definition === null) {
            throw new InvalidArgumentException("Workflow '{$definitionName}' is not defined");
        }

        $definition->validate();

        // Create instance
        $instance = new WorkflowInstance(
            $definition->getId(),
            $definition->getName(),
            $input,
        );

        // Snapshot definition steps at execution time
        $instance->setDefinitionSteps(
            array_values(array_map(static fn($step) => $step->toArray(), $definition->getSteps())),
        );

        // Persist instance
        $this->saveInstance($instance);

        // Start the workflow
        $instance->start();
        $this->saveInstance($instance);

        $this->events->dispatch(new WorkflowStarted($instance));

        // Execute the workflow
        $this->executeWorkflow($definition, $instance);

        return $instance;
    }

    /**
     * Run a workflow asynchronously via queued step jobs.
     *
     * @param array<string, mixed> $input
     */
    public function runAsync(string $definitionName, array $input = [], ?string $connection = null): WorkflowInstance
    {
        $definition = $this->getDefinition($definitionName);

        if ($definition === null) {
            throw new InvalidArgumentException("Workflow '{$definitionName}' is not defined");
        }

        $definition->validate();

        $instance = new WorkflowInstance(
            $definition->getId(),
            $definition->getName(),
            $input,
        );

        $instance->setConnection($connection);

        // Snapshot definition steps before persisting
        $instance->setDefinitionSteps(
            array_values(array_map(static fn($step) => $step->toArray(), $definition->getSteps())),
        );

        $this->saveInstance($instance);

        $job = new RunWorkflowJob($definitionName, $instance->getId());

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        dispatch($job);

        return $instance;
    }

    /**
     * Execute an existing workflow instance (called from RunWorkflowJob).
     */
    public function executeExistingInstance(string $definitionName, string $instanceId): void
    {
        $definition = $this->getDefinition($definitionName);

        if ($definition === null) {
            throw new InvalidArgumentException("Workflow '{$definitionName}' is not defined");
        }

        DB::transaction(function () use ($definition, $instanceId): void {
            $instance = $this->loadInstanceForUpdate($instanceId);

            if ($instance === null) {
                throw new InvalidArgumentException("Workflow instance '{$instanceId}' not found");
            }

            // Snapshot definition steps if not already set
            if (empty($instance->getDefinitionSteps())) {
                $instance->setDefinitionSteps(
                    array_values(array_map(static fn($step) => $step->toArray(), $definition->getSteps())),
                );
            }

            $instance->start();
            $this->saveInstance($instance);

            $this->events->dispatch(new WorkflowStarted($instance));

            $this->advanceWorkflowAsync($definition, $instance);
        });
    }

    /**
     * Get a workflow instance by ID.
     */
    public function getInstance(string $instanceId): ?WorkflowInstance
    {
        $data = DB::table($this->getTable())
            ->where('id', $instanceId)
            ->first();

        if ($data === null) {
            return null;
        }

        // Mark as already persisted so saveInstance() uses UPDATE
        $this->persistedInstances[$instanceId] = true;

        return $this->hydrateInstance(WorkflowInstanceRow::fromObject($data));
    }

    /**
     * Get workflow status.
     *
     * @return array{status: string, current_step: ?string, progress: int, error: ?string}|null
     */
    public function status(string $definitionName, string $instanceId): ?array
    {
        $instance = $this->getInstance($instanceId);

        if ($instance === null || $instance->getDefinitionName() !== $definitionName) {
            return null;
        }

        return [
            'status' => $instance->getStatus(),
            'current_step' => $instance->getCurrentStep(),
            'progress' => $instance->getProgress(),
            'error' => $instance->getError(),
        ];
    }

    /**
     * Cancel a running workflow.
     */
    public function cancel(string $instanceId): bool
    {
        $instance = $this->getInstance($instanceId);

        if ($instance === null || $instance->isFinished()) {
            return false;
        }

        $instance->cancel();
        $this->saveInstance($instance);

        return true;
    }

    /**
     * Pause a running workflow.
     */
    public function pause(string $instanceId): bool
    {
        $instance = $this->getInstance($instanceId);

        if ($instance === null || $instance->getStatus() !== WorkflowStatus::Running->value) {
            return false;
        }

        $instance->pause();
        $this->saveInstance($instance);

        return true;
    }

    /**
     * Resume a paused workflow.
     */
    public function resume(string $instanceId): bool
    {
        $definition = null;
        $instance = null;

        $result = DB::transaction(function () use ($instanceId, &$definition, &$instance): bool {
            $instance = $this->loadInstanceForUpdate($instanceId);

            if ($instance === null || $instance->getStatus() !== WorkflowStatus::Paused->value) {
                return false;
            }

            $definition = $this->getDefinition($instance->getDefinitionName());

            if ($definition === null) {
                return false;
            }

            $instance->resume();
            $this->saveInstance($instance);

            $this->advanceWorkflowAsync($definition, $instance);

            return true;
        });

        return $result;
    }

    /**
     * Get instances for a workflow definition.
     *
     * @return array<WorkflowInstance>
     */
    public function getInstances(string $definitionName, int $limit = 50): array
    {
        $rows = DB::table($this->getTable())
            ->where('definition_name', $definitionName)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $rows->map(fn($row) => $this->hydrateInstance(WorkflowInstanceRow::fromObject($row)))->all();
    }

    /**
     * Execute a single async step (called by WorkflowStepJob::handle).
     */
    public function executeAsyncStep(string $instanceId, string $stepName, string $definitionName): void
    {
        $definition = $this->getDefinition($definitionName);

        if ($definition === null) {
            throw new InvalidArgumentException("Workflow '{$definitionName}' is not defined");
        }

        $step = $definition->getStep($stepName);

        if ($step === null) {
            throw new InvalidArgumentException("Step '{$stepName}' not found in workflow '{$definitionName}'");
        }

        // Phase 1: Claim the step under lock
        $context = [];
        $results = [];
        $claimed = DB::transaction(function () use ($instanceId, $stepName, &$context, &$results): bool {
            $instance = $this->loadInstanceForUpdate($instanceId);

            if ($instance === null) {
                return false;
            }

            // Abort if workflow is not running or step is not queued
            if ($instance->getStatus() !== WorkflowStatus::Running->value) {
                return false;
            }

            if ($instance->getStepStatus($stepName) !== WorkflowStepStatus::Queued->value) {
                return false;
            }

            // Atomically transition queued → running
            $instance->startStep($stepName);
            $context = $instance->getContext();
            $results = $instance->getResults();
            $this->saveInstance($instance);

            return true;
        });

        if (!$claimed) {
            return;
        }

        // Phase 2: Execute user's job (outside lock — may be long-running)
        $result = null;
        $contextUpdates = [];

        try {
            if ($step->isBranch()) {
                $branchName = $step->selectBranch($context);

                if ($branchName === null) {
                    // No branch selected — record as skipped
                    DB::transaction(function () use ($instanceId, $stepName): void {
                        $instance = $this->loadInstanceForUpdate($instanceId);
                        if ($instance === null) {
                            return;
                        }
                        $instance->skipStep($stepName);
                        $this->saveInstance($instance);
                    });

                    return;
                }

                $branches = $step->getBranches();

                if (!isset($branches[$branchName])) {
                    throw new RuntimeException("Unknown branch: {$branchName}");
                }

                $jobClass = $branches[$branchName];
            } else {
                $jobClass = $step->getJobClass();
            }

            $job = new $jobClass($instanceId, $context, $results);

            if (method_exists($job, 'handle')) {
                $result = app()->call([$job, 'handle']); // @phpstan-ignore argument.type
            }

            if (method_exists($job, 'getContextUpdates')) {
                $contextUpdates = $job->getContextUpdates(); // @phpstan-ignore method.nonObject
            }

            if ($step->isBranch()) {
                $result = ['branch' => $branchName, 'result' => $result];
            }
        } catch (Throwable $e) {
            // Step execution failed — record failure under lock
            DB::transaction(function () use ($instanceId, $stepName, $e, $definition): void {
                $instance = $this->loadInstanceForUpdate($instanceId);
                if ($instance === null) {
                    return;
                }

                $instance->failStep($stepName, $e->getMessage());
                $this->checkWorkflowCompletion($definition, $instance);
                $this->saveInstance($instance);
            });

            throw $e;
        }

        // Phase 3: Record completion and advance (under lock)
        DB::transaction(function () use ($instanceId, $stepName, $result, $contextUpdates, $definition): void {
            $instance = $this->loadInstanceForUpdate($instanceId);
            if ($instance === null) {
                return;
            }

            // Always record step completion (work already happened)
            $instance->completeStep($stepName, $result);

            if (!empty($contextUpdates)) {
                $instance->mergeContext($contextUpdates);
            }

            $this->events->dispatch(new WorkflowStepCompleted($instance, $stepName, $result));

            // Only advance if workflow is still running
            if ($instance->getStatus() === WorkflowStatus::Running->value) {
                $this->advanceWorkflowAsync($definition, $instance);
            } else {
                $this->saveInstance($instance);
            }
        });
    }

    /**
     * Handle async step failure after all retries exhausted (called by WorkflowStepJob::failed).
     */
    public function handleAsyncStepFailure(string $instanceId, string $stepName, string $error): void
    {
        try {
            DB::transaction(function () use ($instanceId, $stepName, $error): void {
                $instance = $this->loadInstanceForUpdate($instanceId);
                if ($instance === null) {
                    return;
                }

                // Don't modify terminal workflows
                if ($instance->isFinished()) {
                    return;
                }

                // For the _starter pseudo-step, fail the entire workflow
                if ($stepName === '_starter') {
                    $instance->fail("Workflow starter job failed: {$error}");
                    $this->events->dispatch(new WorkflowFailed($instance));
                    $this->saveInstance($instance);

                    return;
                }

                $instance->failStep($stepName, $error);

                $definition = $this->getDefinition($instance->getDefinitionName());
                if ($definition !== null) {
                    $this->checkWorkflowCompletion($definition, $instance);
                }

                $this->saveInstance($instance);
            });
        } catch (Throwable) {
            // Best-effort — don't let failure handler errors propagate
        }
    }

    /**
     * Recover stuck workflows by re-dispatching queued steps or failing timed-out running steps.
     *
     * @return array<array{id: string, action: string, step: string}>
     */
    public function recoverStuckWorkflows(int $threshold = 300): array
    {
        $recovered = [];

        $stuckRows = DB::table($this->getTable())
            ->where('status', WorkflowStatus::Running->value)
            ->where('updated_at', '<', now()->subSeconds($threshold))
            ->pluck('id');

        foreach ($stuckRows as $id) {
            try {
                DB::transaction(function () use ($id, $threshold, &$recovered): void {
                    $instance = $this->loadInstanceForUpdate($id);
                    if ($instance === null || $instance->getStatus() !== WorkflowStatus::Running->value) {
                        return;
                    }

                    $definition = $this->getDefinition($instance->getDefinitionName());
                    if ($definition === null) {
                        return;
                    }

                    $stepStatuses = $instance->getStepStatuses();
                    $acted = false;

                    foreach ($stepStatuses as $stepName => $status) {
                        if ($status === WorkflowStepStatus::Queued->value) {
                            // Re-dispatch lost step job
                            $step = $definition->getStep($stepName);
                            if ($step !== null) {
                                $this->dispatchStepJob($step, $instance);
                                $recovered[] = ['id' => $id, 'action' => 'redispatched', 'step' => $stepName];
                                $acted = true;
                            }
                        } elseif ($status === WorkflowStepStatus::Running->value) {
                            // Worker died mid-execution
                            $instance->failStep($stepName, "Step timed out (no completion after {$threshold}s)");
                            $recovered[] = ['id' => $id, 'action' => 'timed_out', 'step' => $stepName];
                            $acted = true;
                        }
                    }

                    if ($acted) {
                        $this->checkWorkflowCompletion($definition, $instance);
                        $this->saveInstance($instance);
                    } else {
                        // No queued/running steps but workflow not complete — dispatch gap
                        $this->advanceWorkflowAsync($definition, $instance);
                        $recovered[] = ['id' => $id, 'action' => 'advanced', 'step' => '*'];
                    }
                });
            } catch (Throwable) {
                // Continue recovering other workflows
            }
        }

        return $recovered;
    }

    /**
     * The core async orchestration loop. Must run inside a transaction with row lock.
     *
     * Evaluates which steps are ready to execute, handles virtual/conditional steps inline,
     * marks real steps as queued, and dispatches WorkflowStepJob for each.
     */
    private function advanceWorkflowAsync(WorkflowDefinition $definition, WorkflowInstance $instance): void
    {
        $steps = $definition->getSteps();
        $stepsToDispatch = [];
        $changed = true;
        $maxIterations = \count($steps);

        while ($changed && $maxIterations-- > 0) {
            $changed = false;

            foreach ($steps as $step) {
                $stepName = $step->getName();
                $status = $instance->getStepStatus($stepName);

                // Only process pending steps
                if ($status !== null && $status !== WorkflowStepStatus::Pending->value) {
                    continue;
                }

                // Check dependencies (completed or skipped)
                if (!$instance->areDependenciesResolved($step->getDependencies())) {
                    continue;
                }

                // Virtual steps: auto-complete inline
                if ($step->isVirtual()) {
                    $instance->completeStep($stepName);
                    $changed = true;

                    continue;
                }

                // Conditional steps: skip if condition not met
                if ($step->hasCondition() && !$step->shouldRun($instance->getContext())) {
                    $instance->skipStep($stepName);
                    $changed = true;

                    continue;
                }

                // Real step: mark queued, collect for dispatch
                $instance->queueStep($stepName);
                $stepsToDispatch[] = $step;
            }
        }

        $this->checkWorkflowCompletion($definition, $instance);
        $this->saveInstance($instance);

        // Dispatch step jobs outside the critical section logic (still in same transaction for atomicity)
        foreach ($stepsToDispatch as $step) {
            $this->dispatchStepJob($step, $instance);
        }
    }

    /**
     * Dispatch a WorkflowStepJob for a given step.
     */
    private function dispatchStepJob(WorkflowStep $step, WorkflowInstance $instance): void
    {
        $job = new WorkflowStepJob(
            $instance->getId(),
            $step->getName(),
            $instance->getDefinitionName(),
        );

        $job->tries = $step->getRetries();

        if ($step->getTimeout() !== null) {
            $job->timeout = $step->getTimeout();
        }

        if ($step->getRetryDelay() > 0) {
            $job->backoff = $step->getRetryDelay();
        }

        if ($instance->getConnection() !== null) {
            $job->onConnection($instance->getConnection());
        }

        dispatch($job);
    }

    /**
     * Check if a workflow has reached a terminal state and update accordingly.
     */
    private function checkWorkflowCompletion(WorkflowDefinition $definition, WorkflowInstance $instance): void
    {
        $allDone = true;
        $canProgress = false;

        foreach ($definition->getSteps() as $step) {
            $status = $instance->getStepStatus($step->getName());

            if (!\in_array($status, [
                WorkflowStepStatus::Completed->value,
                WorkflowStepStatus::Skipped->value,
                WorkflowStepStatus::Failed->value,
            ], true)) {
                $allDone = false;

                // Check if any pending step could still make progress
                if ($status === WorkflowStepStatus::Queued->value || $status === WorkflowStepStatus::Running->value) {
                    $canProgress = true;
                } elseif ($status === null || $status === WorkflowStepStatus::Pending->value) {
                    // Could this pending step's deps still resolve?
                    if ($instance->areDependenciesResolved($step->getDependencies())) {
                        $canProgress = true;
                    } else {
                        // Check if all blocking deps are themselves still possible
                        foreach ($step->getDependencies() as $dep) {
                            $depStatus = $instance->getStepStatus($dep);
                            if ($depStatus !== WorkflowStepStatus::Failed->value) {
                                $canProgress = true;

                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($allDone) {
            $hasFailed = false;

            foreach ($instance->getStepStatuses() as $status) {
                if ($status === WorkflowStepStatus::Failed->value) {
                    $hasFailed = true;

                    break;
                }
            }

            if ($hasFailed) {
                $instance->fail($instance->getError() ?? 'One or more steps failed');
                $this->events->dispatch(new WorkflowFailed($instance));
            } else {
                $instance->complete();
                $this->events->dispatch(new WorkflowCompleted($instance));
            }
        } elseif (!$canProgress) {
            // Deadlocked: no steps can make progress (failed deps blocking remaining steps)
            $instance->fail($instance->getError() ?? 'Workflow deadlocked: no remaining steps can make progress');
            $this->events->dispatch(new WorkflowFailed($instance));
        }
    }

    /**
     * Execute the workflow steps synchronously.
     */
    private function executeWorkflow(WorkflowDefinition $definition, WorkflowInstance $instance): void
    {
        // Get execution order (topological sort)
        $executionGroups = $this->resolveExecutionOrder($definition);

        foreach ($executionGroups as $group) {
            // Check if workflow was cancelled/paused
            $instance = $this->getInstance($instance->getId()) ?? $instance;

            if ($instance->getStatus() !== WorkflowStatus::Running->value) {
                return;
            }

            // Execute all steps in this group (they can run in parallel)
            foreach ($group as $stepName) {
                $step = $definition->getStep($stepName);

                if ($step === null) {
                    continue;
                }

                // Check if dependencies are resolved (completed or skipped)
                if (!$instance->areDependenciesResolved($step->getDependencies())) {
                    continue;
                }

                // Check condition
                if ($step->hasCondition() && !$step->shouldRun($instance->getContext())) {
                    $instance->skipStep($stepName);
                    $this->saveInstance($instance);

                    continue;
                }

                // Skip virtual completion steps (all deps already met)
                if ($step->isVirtual()) {
                    $instance->completeStep($stepName);
                    $this->saveInstance($instance);

                    continue;
                }

                // Handle branch steps
                if ($step->isBranch()) {
                    $this->executeBranchStep($step, $instance);

                    continue;
                }

                // Execute the step
                $this->executeStep($step, $instance);
            }
        }

        // Check terminal state (completion, failure, or deadlock)
        $this->checkWorkflowCompletion($definition, $instance);
        $this->saveInstance($instance);
    }

    /**
     * Execute a single step synchronously.
     */
    private function executeStep(WorkflowStep $step, WorkflowInstance $instance): void
    {
        $instance->startStep($step->getName());
        $this->saveInstance($instance);

        try {
            $jobClass = $step->getJobClass();
            $job = new $jobClass($instance->getId(), $instance->getContext(), $instance->getResults());

            // Add workflow metadata to job
            if (property_exists($job, 'workflowInstanceId')) {
                $job->workflowInstanceId = $instance->getId();
            }

            if (property_exists($job, 'workflowStepName')) {
                $job->workflowStepName = $step->getName();
            }

            // Dispatch synchronously for now (could be made async with events)
            $result = null;

            if (method_exists($job, 'handle')) {
                $result = app()->call([$job, 'handle']); // @phpstan-ignore argument.type
            }

            // Update instance with result
            $instance->completeStep($step->getName(), $result);

            // Merge any context updates from the job
            if (method_exists($job, 'getContextUpdates')) {
                $instance->mergeContext($job->getContextUpdates()); // @phpstan-ignore method.nonObject
            }

            $this->saveInstance($instance);

            $this->events->dispatch(new WorkflowStepCompleted($instance, $step->getName(), $result));
        } catch (Throwable $e) {
            $instance->failStep($step->getName(), $e->getMessage());
            $this->saveInstance($instance);
        }
    }

    /**
     * Execute a branch step synchronously.
     */
    private function executeBranchStep(WorkflowStep $step, WorkflowInstance $instance): void
    {
        $branchName = $step->selectBranch($instance->getContext());

        if ($branchName === null) {
            $instance->skipStep($step->getName());
            $this->saveInstance($instance);

            return;
        }

        $branches = $step->getBranches();

        if (!isset($branches[$branchName])) {
            $instance->failStep($step->getName(), "Unknown branch: {$branchName}");
            $this->saveInstance($instance);

            return;
        }

        $instance->startStep($step->getName());
        $this->saveInstance($instance);

        try {
            $jobClass = $branches[$branchName];
            $job = new $jobClass($instance->getId(), $instance->getContext(), $instance->getResults());

            $result = null;

            if (method_exists($job, 'handle')) {
                $result = app()->call([$job, 'handle']);
            }

            $instance->completeStep($step->getName(), [
                'branch' => $branchName,
                'result' => $result,
            ]);

            if (method_exists($job, 'getContextUpdates')) {
                $instance->mergeContext($job->getContextUpdates());
            }

            $this->saveInstance($instance);

            $this->events->dispatch(new WorkflowStepCompleted($instance, $step->getName(), $result));
        } catch (Throwable $e) {
            $instance->failStep($step->getName(), $e->getMessage());
            $this->saveInstance($instance);
        }
    }

    /**
     * Load an instance with a row lock for atomic updates.
     */
    private function loadInstanceForUpdate(string $instanceId): ?WorkflowInstance
    {
        $data = DB::table($this->getTable())
            ->where('id', $instanceId)
            ->lockForUpdate()
            ->first();

        if ($data === null) {
            return null;
        }

        $this->persistedInstances[$instanceId] = true;

        return $this->hydrateInstance(WorkflowInstanceRow::fromObject($data));
    }

    /**
     * Hydrate a WorkflowInstance from a WorkflowInstanceRow DTO.
     */
    private function hydrateInstance(WorkflowInstanceRow $row): WorkflowInstance
    {
        return WorkflowInstance::fromArray([
            'id' => $row->id,
            'definition_id' => $row->definition_id,
            'definition_name' => $row->definition_name,
            'connection' => $row->connection,
            'status' => $row->status,
            'current_step' => $row->current_step,
            'input' => json_decode($row->input, true) ?? [],
            'context' => json_decode($row->context, true) ?? [],
            'results' => json_decode($row->results, true) ?? [],
            'step_statuses' => json_decode($row->step_statuses, true) ?? [],
            'definition_steps' => $row->definition_steps !== null ? (json_decode($row->definition_steps, true) ?? []) : [],
            'error' => $row->error,
            'created_at' => $row->created_at,
            'started_at' => $row->started_at,
            'completed_at' => $row->completed_at,
        ]);
    }

    /**
     * Resolve execution order using topological sort.
     *
     * @return array<int, array<string>>
     */
    private function resolveExecutionOrder(WorkflowDefinition $definition): array
    {
        $steps = $definition->getSteps();
        $inDegree = [];
        $adjacency = [];

        foreach ($steps as $name => $step) {
            $inDegree[$name] = \count($step->getDependencies());
            $adjacency[$name] = [];
        }

        foreach ($steps as $name => $step) {
            foreach ($step->getDependencies() as $dep) {
                $adjacency[$dep][] = $name;
            }
        }

        $groups = [];
        $currentGroup = [];

        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $currentGroup[] = $name;
            }
        }

        while ($currentGroup !== []) {
            $groups[] = $currentGroup;
            $nextGroup = [];

            foreach ($currentGroup as $name) {
                foreach ($adjacency[$name] as $dependent) {
                    $inDegree[$dependent]--;

                    if ($inDegree[$dependent] === 0) {
                        $nextGroup[] = $dependent;
                    }
                }
            }

            $currentGroup = $nextGroup;
        }

        return $groups;
    }

    /**
     * Save a workflow instance to storage.
     *
     * Uses tracked insert/update instead of updateOrInsert to avoid
     * a wasted SELECT on every call (~4N saved queries for N-step workflows).
     */
    private function saveInstance(WorkflowInstance $instance): void
    {
        $data = $instance->toArray();

        $values = [
            'definition_id' => $data['definition_id'],
            'definition_name' => $data['definition_name'],
            'connection' => $data['connection'] ?? null,
            'status' => $data['status'],
            'current_step' => $data['current_step'],
            'input' => json_encode($data['input']),
            'context' => json_encode($data['context']),
            'results' => json_encode($data['results']),
            'step_statuses' => json_encode($data['step_statuses']),
            'definition_steps' => json_encode($data['definition_steps']),
            'error' => $data['error'],
            'progress' => $data['progress'],
            'started_at' => $data['started_at'],
            'completed_at' => $data['completed_at'],
            'updated_at' => now(),
        ];

        $id = $instance->getId();

        if (!isset($this->persistedInstances[$id])) {
            $values['created_at'] = $data['created_at'];
            DB::table($this->getTable())->insert(array_merge(['id' => $id], $values));
            $this->persistedInstances[$id] = true;
        } else {
            DB::table($this->getTable())->where('id', $id)->update($values);
        }

        // Free memory for terminal instances — they won't be updated again
        if ($instance->isFinished()) {
            unset($this->persistedInstances[$id]);
        }
    }

    /**
     * Get the workflows table name.
     */
    private function getTable(): string
    {
        return $this->config['table'] ?? 'station_workflows';
    }
}
