<?php

declare(strict_types=1);

namespace Station\Core;

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Represents a workflow with DAG dependency management.
 *
 * Usage:
 * Workflow::create('my-workflow')
 *     ->add('step1', new FirstJob())
 *     ->add('step2', new SecondJob(), ['step1'])
 *     ->add('step3', new ThirdJob(), ['step1'])
 *     ->add('step4', new FourthJob(), ['step2', 'step3'])
 *     ->dispatch();
 *
 * This creates: step1 → step2 ─┐
 *                    └─→ step3 ─┴─→ step4
 */
final class Workflow
{
    private string $id;

    private string $name;

    private ?string $connection = null;

    private ?string $queue = null;

    /** @var array<string, array{job: object, dependencies: array<string>}> */
    private array $steps = [];

    /** @var callable|null */
    private mixed $thenCallback = null;

    /** @var callable|null */
    private mixed $catchCallback = null;

    /** @var callable|null */
    private mixed $finallyCallback = null;

    public function __construct(string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
    }

    /**
     * Create a new workflow.
     */
    public static function create(string $name): self
    {
        return new self($name);
    }

    /**
     * Add a step to the workflow.
     *
     * @param array<string> $dependencies
     */
    public function add(string $name, object $job, array $dependencies = []): self
    {
        $this->steps[$name] = [
            'job' => $job,
            'dependencies' => $dependencies,
        ];

        return $this;
    }

    /**
     * Set the connection for all jobs.
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Set the queue for all jobs.
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Set a callback to be called when the workflow completes successfully.
     */
    public function then(callable $callback): self
    {
        $this->thenCallback = $callback;

        return $this;
    }

    /**
     * Set a callback to be called if the workflow fails.
     */
    public function catch(callable $callback): self
    {
        $this->catchCallback = $callback;

        return $this;
    }

    /**
     * Set a callback to be called after the workflow completes (success or failure).
     */
    public function finally(callable $callback): self
    {
        $this->finallyCallback = $callback;

        return $this;
    }

    /**
     * Dispatch the workflow.
     */
    public function dispatch(): string
    {
        // Resolve execution order using topological sort
        $executionGroups = $this->resolveExecutionOrder();

        // Convert to nested batch structure
        $batch = $this->buildBatch($executionGroups);

        $batch->dispatch();

        return $this->id;
    }

    /**
     * Get the workflow ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the workflow name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Resolve execution order using topological sort.
     *
     * @return array<int, array<string>>
     */
    private function resolveExecutionOrder(): array
    {
        $inDegree = [];
        $adjacency = [];

        // Initialize
        foreach ($this->steps as $name => $step) {
            $inDegree[$name] = \count($step['dependencies']);
            $adjacency[$name] = [];
        }

        // Build adjacency list
        foreach ($this->steps as $name => $step) {
            foreach ($step['dependencies'] as $dep) {
                $adjacency[$dep][] = $name;
            }
        }

        // Find all steps with no dependencies
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

        // Check for cycles
        $processedCount = array_sum(array_map('count', $groups));

        if ($processedCount !== \count($this->steps)) {
            throw new RuntimeException('Workflow contains circular dependencies');
        }

        return $groups;
    }

    /**
     * Build a batch structure from execution groups.
     *
     * @param array<int, array<string>> $groups
     */
    private function buildBatch(array $groups): PendingBatch
    {
        // Build chained batch structure
        $jobs = [];

        foreach ($groups as $groupIndex => $group) {
            $groupJobs = [];

            foreach ($group as $stepName) {
                $job = $this->steps[$stepName]['job'];

                // Add workflow metadata
                if (property_exists($job, 'workflowId')) {
                    $job->workflowId = $this->id;
                }

                if (property_exists($job, 'workflowName')) {
                    $job->workflowName = $this->name;
                }

                if (property_exists($job, 'workflowStep')) {
                    $job->workflowStep = $stepName;
                }

                $groupJobs[] = $job;
            }

            // Each group can run in parallel
            $jobs[] = $groupJobs;
        }

        // Flatten for batch - Laravel's batch handles parallel execution
        $flatJobs = [];

        foreach ($jobs as $group) {
            if (\count($group) === 1) {
                $flatJobs[] = $group[0];
            } else {
                // Multiple jobs in a group run in parallel
                $flatJobs[] = $group;
            }
        }

        $batch = Bus::batch($flatJobs)->name($this->name);

        if ($this->connection !== null) {
            $batch->onConnection($this->connection);
        }

        if ($this->queue !== null) {
            $batch->onQueue($this->queue);
        }

        if ($this->thenCallback !== null) {
            $batch->then($this->thenCallback);
        }

        if ($this->catchCallback !== null) {
            $batch->catch($this->catchCallback);
        }

        if ($this->finallyCallback !== null) {
            $batch->finally($this->finallyCallback);
        }

        return $batch;
    }
}
