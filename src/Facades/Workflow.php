<?php

declare(strict_types=1);

namespace Station\Facades;

use Illuminate\Support\Facades\Facade;
use Station\Core\Workflow as SimpleWorkflow;
use Station\Workflows\WorkflowDefinition;
use Station\Workflows\WorkflowInstance;
use Station\Workflows\WorkflowManager;

/**
 * Workflow facade for managing workflow definitions and instances.
 *
 * Simple workflows (DAG wrapper around Bus::batch):
 *
 * @method static SimpleWorkflow create(string $name)
 *
 * Workflow definitions:
 * @method static WorkflowDefinition define(string $name)
 * @method static void register(WorkflowDefinition $definition)
 * @method static WorkflowDefinition|null getDefinition(string $name)
 * @method static array<string, WorkflowDefinition> getDefinitions()
 *
 * Workflow execution:
 * @method static WorkflowInstance run(string $definitionName, array<string, mixed> $input = [])
 * @method static WorkflowInstance runAsync(string $definitionName, array<string, mixed> $input = [], ?string $connection = null)
 * @method static void executeExistingInstance(string $definitionName, string $instanceId)
 * @method static void executeAsyncStep(string $instanceId, string $stepName, string $definitionName)
 * @method static void handleAsyncStepFailure(string $instanceId, string $stepName, string $error)
 * @method static WorkflowInstance|null getInstance(string $instanceId)
 * @method static array<string, mixed>|null status(string $definitionName, string $instanceId)
 *
 * Workflow control:
 * @method static bool cancel(string $instanceId)
 * @method static bool pause(string $instanceId)
 * @method static bool resume(string $instanceId)
 *
 * Workflow recovery:
 * @method static array<array{id: string, action: string, step: string}> recoverStuckWorkflows(int $threshold = 300)
 *
 * Workflow queries:
 * @method static array<WorkflowInstance> getInstances(string $definitionName, int $limit = 50)
 *
 * @see WorkflowManager
 * @see SimpleWorkflow
 */
final class Workflow extends Facade
{
    /**
     * Create a simple workflow (DAG wrapper around Bus::batch).
     *
     * Usage:
     * Workflow::create('my-workflow')
     *     ->add('step1', new FirstJob())
     *     ->add('step2', new SecondJob(), ['step1'])
     *     ->dispatch();
     */
    public static function create(string $name): SimpleWorkflow
    {
        return new SimpleWorkflow($name);
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return WorkflowManager::class;
    }
}
