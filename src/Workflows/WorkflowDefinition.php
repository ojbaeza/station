<?php

declare(strict_types=1);

namespace Station\Workflows;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Defines a reusable workflow structure that can be persisted and executed multiple times.
 */
final class WorkflowDefinition
{
    private string $id;

    /** @var array<string, WorkflowStep> */
    private array $steps = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    private ?int $timeout = null;

    private int $maxRetries = 3;

    private string $source = 'code';

    public function __construct(
        private readonly string $name,
        private ?string $description = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
    }

    /**
     * Create a new workflow definition.
     */
    public static function define(string $name): self
    {
        return new self($name);
    }

    /**
     * Set the workflow description.
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Add a step to the workflow.
     *
     * @param class-string|object $jobClass Job class name or job instance
     * @param array<string> $dependencies
     */
    public function addStep(
        string $name,
        string|object $jobClass,
        array $dependencies = [],
        ?callable $condition = null,
    ): self {
        $className = \is_object($jobClass) ? $jobClass::class : $jobClass;

        $this->steps[$name] = new WorkflowStep(
            name: $name,
            jobClass: $className,
            dependencies: $dependencies,
            condition: $condition,
        );

        return $this;
    }

    /**
     * Add a conditional step that only runs if the condition is met.
     *
     * @param class-string|object $jobClass Job class name or job instance
     * @param array<string> $dependencies
     */
    public function addConditionalStep(
        string $name,
        string|object $jobClass,
        callable $condition,
        array $dependencies = [],
    ): self {
        return $this->addStep($name, $jobClass, $dependencies, $condition);
    }

    /**
     * Add parallel steps that run concurrently.
     *
     * @param array<string, class-string|object> $jobs Map of step name to job class or instance
     * @param array<string> $dependencies Steps that must complete before these parallel steps
     */
    public function addParallel(
        string $groupName,
        array $jobs,
        array $dependencies = [],
    ): self {
        $parallelStepNames = [];

        foreach ($jobs as $stepName => $job) {
            $jobClass = \is_object($job) ? $job::class : $job;
            $this->steps[$stepName] = new WorkflowStep(
                name: $stepName,
                jobClass: $jobClass,
                dependencies: $dependencies,
                condition: null,
                parallelGroup: $groupName,
            );
            $parallelStepNames[] = $stepName;
        }

        // Virtual completion step — no job to run, depends on all parallel steps
        $this->steps[$groupName] = new WorkflowStep(
            name: $groupName,
            jobClass: null,
            dependencies: $parallelStepNames,
            condition: null,
            parallelGroup: null,
            virtual: true,
        );

        return $this;
    }

    /**
     * Add a branching point in the workflow.
     *
     * @param array<string, class-string> $branches Map of condition name to job class
     * @param array<string> $dependencies
     */
    public function addBranch(
        string $name,
        callable $selector,
        array $branches,
        array $dependencies = [],
    ): self {
        $this->steps[$name] = new WorkflowStep(
            name: $name,
            jobClass: WorkflowBranchJob::class,
            dependencies: $dependencies,
            condition: null,
            branches: $branches,
            branchSelector: $selector,
        );

        return $this;
    }

    /**
     * Set the workflow timeout in seconds.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the maximum number of retries for failed steps.
     */
    public function maxRetries(int $retries): self
    {
        $this->maxRetries = $retries;

        return $this;
    }

    /**
     * Set the source of this definition (e.g. 'code' or 'database').
     */
    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Get the source of this definition.
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Add metadata to the workflow definition.
     *
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
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
     * Get the workflow description.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get all steps.
     *
     * @return array<string, WorkflowStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Get a specific step.
     */
    public function getStep(string $name): ?WorkflowStep
    {
        return $this->steps[$name] ?? null;
    }

    /**
     * Get the workflow timeout.
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * Get the maximum retries.
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Get the metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Validate the workflow definition.
     *
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        if (empty($this->steps)) {
            throw new InvalidArgumentException('Workflow must have at least one step');
        }

        // Check for missing dependencies
        foreach ($this->steps as $step) {
            foreach ($step->getDependencies() as $dep) {
                if (!isset($this->steps[$dep])) {
                    throw new InvalidArgumentException(
                        "Step '{$step->getName()}' depends on unknown step '{$dep}'",
                    );
                }
            }
        }

        // Check for circular dependencies
        $this->detectCycles();
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
            'name' => $this->name,
            'description' => $this->description,
            'steps' => array_map(static fn($step) => $step->toArray(), $this->steps),
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'metadata' => $this->metadata,
            'source' => $this->source,
        ];
    }

    /**
     * Detect circular dependencies using DFS.
     *
     * @throws InvalidArgumentException
     */
    private function detectCycles(): void
    {
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($this->steps) as $name) {
            if ($this->hasCycleDfs($name, $visited, $recursionStack)) {
                throw new InvalidArgumentException(
                    'Workflow contains circular dependencies',
                );
            }
        }
    }

    /**
     * DFS helper for cycle detection.
     *
     * @param array<string, bool> $visited
     * @param array<string, bool> $recursionStack
     */
    private function hasCycleDfs(string $node, array &$visited, array &$recursionStack): bool
    {
        if (isset($recursionStack[$node])) {
            return true;
        }

        if (isset($visited[$node])) {
            return false;
        }

        $visited[$node] = true;
        $recursionStack[$node] = true;

        $step = $this->steps[$node];

        foreach ($step->getDependencies() as $dep) {
            if ($this->hasCycleDfs($dep, $visited, $recursionStack)) {
                return true;
            }
        }

        unset($recursionStack[$node]);

        return false;
    }
}
