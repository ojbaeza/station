<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Workflows;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Enums\WorkflowStatus;
use Station\Enums\WorkflowStepStatus;
use Station\Events\WorkflowCompleted;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStarted;
use Station\Events\WorkflowStepCompleted;
use Station\StationServiceProvider;
use Station\Workflows\WorkflowDefinition;
use Station\Workflows\WorkflowInstance;
use Station\Workflows\WorkflowManager;

// ---------- Test job stubs ----------

class WMStubJob
{
    public ?string $workflowInstanceId = null;

    public ?string $workflowStepName = null;

    /** @var array<string, mixed> */
    private array $contextUpdates = [];

    public function __construct(
        public readonly string $instanceId = '',
        /** @var array<string, mixed> */
        public readonly array $context = [],
        /** @var array<string, mixed> */
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        return 'stub-result';
    }

    /** @return array<string, mixed> */
    public function getContextUpdates(): array
    {
        return $this->contextUpdates;
    }

    /** @param array<string, mixed> $updates */
    public function setContextUpdates(array $updates): void
    {
        $this->contextUpdates = $updates;
    }
}

class WMContextUpdatingJob
{
    public function __construct(
        public readonly string $instanceId = '',
        /** @var array<string, mixed> */
        public readonly array $context = [],
        /** @var array<string, mixed> */
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        return 'context-result';
    }

    /** @return array<string, mixed> */
    public function getContextUpdates(): array
    {
        return ['processed' => true, 'counter' => 42];
    }
}

class WMFailingJob
{
    public function __construct(
        public readonly string $instanceId = '',
        /** @var array<string, mixed> */
        public readonly array $context = [],
        /** @var array<string, mixed> */
        public readonly array $results = [],
    ) {}

    public function handle(): void
    {
        throw new RuntimeException('Job execution failed');
    }

    /** @return array<string, mixed> */
    public function getContextUpdates(): array
    {
        return [];
    }
}

class WMNoHandleJob
{
    public function __construct(
        public readonly string $instanceId = '',
        /** @var array<string, mixed> */
        public readonly array $context = [],
        /** @var array<string, mixed> */
        public readonly array $results = [],
    ) {}
}

class WMBranchAJob
{
    public function __construct(
        public readonly string $instanceId = '',
        /** @var array<string, mixed> */
        public readonly array $context = [],
        /** @var array<string, mixed> */
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        return 'branch-a-result';
    }

    /** @return array<string, mixed> */
    public function getContextUpdates(): array
    {
        return ['branch_taken' => 'a'];
    }
}

class WMBranchBJob
{
    public function __construct(
        public readonly string $instanceId = '',
        /** @var array<string, mixed> */
        public readonly array $context = [],
        /** @var array<string, mixed> */
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        return 'branch-b-result';
    }

    /** @return array<string, mixed> */
    public function getContextUpdates(): array
    {
        return ['branch_taken' => 'b'];
    }
}

// ---------- Test class ----------

class WorkflowManagerTest extends TestCase
{
    private WorkflowManager $sut;

    /** @var array<string, mixed> */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Collect dispatched events for verification
        $this->dispatchedEvents = [];
        $dispatcher = $this->app->make(Dispatcher::class);

        $this->sut = new WorkflowManager($dispatcher, [
            'table' => 'station_workflows',
        ]);
    }

    // =========================================================================
    // define()
    // =========================================================================

    public function testDefineReturnsWorkflowDefinition(): void
    {
        $definition = $this->sut->define('my-workflow');

        $this->assertInstanceOf(WorkflowDefinition::class, $definition);
        $this->assertSame('my-workflow', $definition->getName());
    }

    public function testDefineRegistersDefinitionInMemory(): void
    {
        $definition = $this->sut->define('my-workflow');

        $retrieved = $this->sut->getDefinition('my-workflow');

        $this->assertSame($definition, $retrieved);
    }

    public function testDefineOverwritesPreviousDefinitionWithSameName(): void
    {
        $first = $this->sut->define('my-workflow');
        $second = $this->sut->define('my-workflow');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->sut->getDefinition('my-workflow'));
    }

    // =========================================================================
    // register()
    // =========================================================================

    public function testRegisterStoresDefinition(): void
    {
        $definition = WorkflowDefinition::define('external-workflow')
            ->addStep('step1', WMStubJob::class);

        $this->sut->register($definition);

        $this->assertSame($definition, $this->sut->getDefinition('external-workflow'));
    }

    public function testRegisterValidatesDefinition(): void
    {
        $definition = WorkflowDefinition::define('invalid-workflow');
        // No steps added - validation should fail

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow must have at least one step');

        $this->sut->register($definition);
    }

    public function testRegisterReplacesExistingDefinitionWithSameName(): void
    {
        $first = WorkflowDefinition::define('workflow')
            ->addStep('step1', WMStubJob::class);

        $second = WorkflowDefinition::define('workflow')
            ->addStep('step2', WMStubJob::class);

        $this->sut->register($first);
        $this->sut->register($second);

        $this->assertSame($second, $this->sut->getDefinition('workflow'));
    }

    public function testRegisterRejectsCircularDependencies(): void
    {
        $definition = WorkflowDefinition::define('circular')
            ->addStep('a', WMStubJob::class, ['c'])
            ->addStep('b', WMStubJob::class, ['a'])
            ->addStep('c', WMStubJob::class, ['b']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow contains circular dependencies');

        $this->sut->register($definition);
    }

    public function testRegisterRejectsMissingDependency(): void
    {
        $definition = WorkflowDefinition::define('missing-dep')
            ->addStep('a', WMStubJob::class, ['nonexistent']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Step 'a' depends on unknown step 'nonexistent'");

        $this->sut->register($definition);
    }

    // =========================================================================
    // getDefinition() / getDefinitions()
    // =========================================================================

    public function testGetDefinitionReturnsNullForUnknownName(): void
    {
        $this->assertNull($this->sut->getDefinition('unknown'));
    }

    public function testGetDefinitionsReturnsAllRegistered(): void
    {
        $this->sut->define('workflow-a')->addStep('s1', WMStubJob::class);
        $this->sut->define('workflow-b')->addStep('s1', WMStubJob::class);

        $definitions = $this->sut->getDefinitions();

        $this->assertCount(2, $definitions);
        $this->assertArrayHasKey('workflow-a', $definitions);
        $this->assertArrayHasKey('workflow-b', $definitions);
    }

    public function testGetDefinitionsReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->sut->getDefinitions());
    }

    // =========================================================================
    // run() - synchronous execution
    // =========================================================================

    public function testRunThrowsForUnregisteredDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->sut->run('nonexistent');
    }

    public function testRunDispatchesWorkflowStartedEvent(): void
    {
        $events = [];
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(WorkflowStarted::class, static function (WorkflowStarted $event) use (&$events): void {
            $events[] = $event;
        });

        $this->sut = new WorkflowManager($dispatcher, ['table' => 'station_workflows']);

        $this->sut->define('event-test')->addStep('s1', WMStubJob::class);
        $this->sut->run('event-test');

        $this->assertCount(1, $events);
        $this->assertInstanceOf(WorkflowStarted::class, $events[0]);
    }

    public function testRunDispatchesWorkflowCompletedEvent(): void
    {
        $events = [];
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(WorkflowCompleted::class, static function (WorkflowCompleted $event) use (&$events): void {
            $events[] = $event;
        });

        $this->sut = new WorkflowManager($dispatcher, ['table' => 'station_workflows']);

        $this->sut->define('completion-test')->addStep('s1', WMStubJob::class);
        $this->sut->run('completion-test');

        $this->assertCount(1, $events);
        $this->assertInstanceOf(WorkflowCompleted::class, $events[0]);
    }

    public function testRunDispatchesStepCompletedEvents(): void
    {
        $events = [];
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(WorkflowStepCompleted::class, static function (WorkflowStepCompleted $event) use (&$events): void {
            $events[] = $event;
        });

        $this->sut = new WorkflowManager($dispatcher, ['table' => 'station_workflows']);

        $this->sut->define('step-events-test')
            ->addStep('s1', WMStubJob::class)
            ->addStep('s2', WMStubJob::class, ['s1']);
        $this->sut->run('step-events-test');

        $this->assertCount(2, $events);
        $this->assertSame('s1', $events[0]->stepName);
        $this->assertSame('s2', $events[1]->stepName);
    }

    public function testRunWithInputPassesInputToContext(): void
    {
        $definition = $this->sut->define('input-test')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->run('input-test', ['user_id' => 42, 'action' => 'process']);

        $this->assertSame(['user_id' => 42, 'action' => 'process'], $instance->getInput());
    }

    public function testRunPersistsInstanceToDatabase(): void
    {
        $this->sut->define('persist-test')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->run('persist-test');

        // Verify it was saved to the database
        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();

        $this->assertNotNull($row);
        $this->assertSame($instance->getId(), $row->id);
        $this->assertSame('persist-test', $row->definition_name);
    }

    public function testRunSnapshotsDefinitionSteps(): void
    {
        $this->sut->define('snapshot-test')
            ->addStep('step1', WMStubJob::class)
            ->addStep('step2', WMStubJob::class, ['step1']);

        $instance = $this->sut->run('snapshot-test');

        $defSteps = $instance->getDefinitionSteps();
        $this->assertCount(2, $defSteps);
    }

    // =========================================================================
    // run() - conditional steps
    // =========================================================================

    // =========================================================================
    // run() - virtual (parallel group completion) steps
    // =========================================================================

    // =========================================================================
    // run() - branch steps
    // =========================================================================

    // =========================================================================
    // run() - error handling
    // =========================================================================

    public function testRunDispatchesWorkflowFailedEventOnFailure(): void
    {
        $events = [];
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(WorkflowFailed::class, static function (WorkflowFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $this->sut = new WorkflowManager($dispatcher, ['table' => 'station_workflows']);

        $this->sut->define('fail-event-test')
            ->addStep('step1', WMFailingJob::class);
        $this->sut->run('fail-event-test');

        $this->assertCount(1, $events);
        $this->assertInstanceOf(WorkflowFailed::class, $events[0]);
    }

    // =========================================================================
    // run() - resolveExecutionOrder (topological sort, tested via run)
    // =========================================================================

    // =========================================================================
    // runAsync()
    // =========================================================================

    public function testRunAsyncReturnsInstanceInPendingState(): void
    {
        $this->sut->define('async-workflow')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('async-workflow');

        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $this->assertSame(WorkflowStatus::Pending->value, $instance->getStatus());
    }

    public function testRunAsyncThrowsForUnregisteredDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->sut->runAsync('nonexistent');
    }

    public function testRunAsyncPersistsInstance(): void
    {
        $this->sut->define('async-persist')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('async-persist');

        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $this->assertNotNull($row);
        $this->assertSame('async-persist', $row->definition_name);
    }

    public function testRunAsyncSnapshotsDefinitionSteps(): void
    {
        $this->sut->define('async-snapshot')
            ->addStep('step1', WMStubJob::class)
            ->addStep('step2', WMStubJob::class, ['step1']);

        $instance = $this->sut->runAsync('async-snapshot');

        $defSteps = $instance->getDefinitionSteps();
        $this->assertCount(2, $defSteps);
    }

    public function testRunAsyncSetsConnectionOnInstance(): void
    {
        $this->sut->define('async-connection')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('async-connection', [], 'redis');

        $this->assertSame('redis', $instance->getConnection());
    }

    public function testRunAsyncWithInputPassesInputToInstance(): void
    {
        $this->sut->define('async-input')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('async-input', ['key' => 'value']);

        $this->assertSame(['key' => 'value'], $instance->getInput());
    }

    // =========================================================================
    // getInstance()
    // =========================================================================

    public function testGetInstanceReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->sut->getInstance('nonexistent-id'));
    }

    // =========================================================================
    // status()
    // =========================================================================

    public function testStatusReturnsNullForNonexistentInstance(): void
    {
        $this->assertNull($this->sut->status('my-workflow', 'nonexistent-id'));
    }

    public function testStatusReturnsNullForWrongDefinitionName(): void
    {
        $this->sut->define('correct-name')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->run('correct-name');

        $this->assertNull($this->sut->status('wrong-name', $instance->getId()));
    }

    public function testStatusReturnsCorrectData(): void
    {
        $this->sut->define('status-test')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->run('status-test');
        $status = $this->sut->status('status-test', $instance->getId());

        $this->assertNotNull($status);
        $this->assertSame(WorkflowStatus::Completed->value, $status['status']);
        $this->assertSame(100, $status['progress']);
        $this->assertNull($status['error']);
    }

    // =========================================================================
    // cancel()
    // =========================================================================

    public function testCancelReturnsFalseForNonexistentInstance(): void
    {
        $this->assertFalse($this->sut->cancel('nonexistent'));
    }

    public function testCancelReturnsFalseForFinishedInstance(): void
    {
        $this->sut->define('cancel-finished')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->run('cancel-finished');

        // Instance is already completed
        $this->assertFalse($this->sut->cancel($instance->getId()));
    }

    public function testCancelReturnsTrueAndUpdatesStatus(): void
    {
        // Create a running instance via runAsync (it stays pending, but let's
        // insert a running-state instance manually for isolation)
        $this->sut->define('cancel-test')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('cancel-test');

        // The async instance is in pending state, which is not finished, so cancel should work
        $result = $this->sut->cancel($instance->getId());

        $this->assertTrue($result);

        $reloaded = $this->sut->getInstance($instance->getId());
        $this->assertSame(WorkflowStatus::Cancelled->value, $reloaded->getStatus());
    }

    // =========================================================================
    // pause()
    // =========================================================================

    public function testPauseReturnsFalseForNonexistentInstance(): void
    {
        $this->assertFalse($this->sut->pause('nonexistent'));
    }

    public function testPauseReturnsFalseForNonRunningInstance(): void
    {
        $this->sut->define('pause-non-running')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('pause-non-running');
        // Instance is pending, not running
        $this->assertFalse($this->sut->pause($instance->getId()));
    }

    public function testPauseReturnsTrueForRunningInstance(): void
    {
        $this->sut->define('pause-test')
            ->addStep('step1', WMStubJob::class);

        // Create and manually set to running via the database
        $instance = $this->sut->runAsync('pause-test');
        DB::table('station_workflows')->where('id', $instance->getId())->update([
            'status' => WorkflowStatus::Running->value,
        ]);

        $result = $this->sut->pause($instance->getId());

        $this->assertTrue($result);

        $reloaded = $this->sut->getInstance($instance->getId());
        $this->assertSame(WorkflowStatus::Paused->value, $reloaded->getStatus());
    }

    // =========================================================================
    // getInstances()
    // =========================================================================

    public function testGetInstancesReturnsEmptyArrayForNoInstances(): void
    {
        $instances = $this->sut->getInstances('nonexistent');

        $this->assertSame([], $instances);
    }

    public function testGetInstancesReturnsMatchingInstances(): void
    {
        $this->sut->define('list-test')
            ->addStep('step1', WMStubJob::class);

        $this->sut->run('list-test');
        $this->sut->run('list-test');

        $instances = $this->sut->getInstances('list-test');

        $this->assertCount(2, $instances);
        foreach ($instances as $instance) {
            $this->assertInstanceOf(WorkflowInstance::class, $instance);
            $this->assertSame('list-test', $instance->getDefinitionName());
        }
    }

    public function testGetInstancesRespectsLimit(): void
    {
        $this->sut->define('limit-test')
            ->addStep('step1', WMStubJob::class);

        for ($i = 0; $i < 5; $i++) {
            $this->sut->run('limit-test');
        }

        $instances = $this->sut->getInstances('limit-test', 3);

        $this->assertCount(3, $instances);
    }

    public function testGetInstancesDoesNotReturnOtherDefinitions(): void
    {
        $this->sut->define('workflow-a')
            ->addStep('step1', WMStubJob::class);
        $this->sut->define('workflow-b')
            ->addStep('step1', WMStubJob::class);

        $this->sut->run('workflow-a');
        $this->sut->run('workflow-b');

        $instancesA = $this->sut->getInstances('workflow-a');
        $instancesB = $this->sut->getInstances('workflow-b');

        $this->assertCount(1, $instancesA);
        $this->assertCount(1, $instancesB);
        $this->assertSame('workflow-a', $instancesA[0]->getDefinitionName());
        $this->assertSame('workflow-b', $instancesB[0]->getDefinitionName());
    }

    // =========================================================================
    // executeExistingInstance()
    // =========================================================================

    public function testExecuteExistingInstanceThrowsForUnknownDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->sut->executeExistingInstance('nonexistent', 'some-id');
    }

    public function testExecuteExistingInstanceThrowsForUnknownInstanceId(): void
    {
        $this->sut->define('exec-test')
            ->addStep('step1', WMStubJob::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow instance 'bad-id' not found");

        $this->sut->executeExistingInstance('exec-test', 'bad-id');
    }

    public function testExecuteExistingInstanceRunsWorkflow(): void
    {
        $events = [];
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(WorkflowStarted::class, static function (WorkflowStarted $event) use (&$events): void {
            $events[] = $event;
        });

        $this->sut = new WorkflowManager($dispatcher, ['table' => 'station_workflows']);

        $this->sut->define('exec-existing')
            ->addStep('step1', WMStubJob::class);

        // Create an instance via runAsync (pending state, not executed)
        $instance = $this->sut->runAsync('exec-existing');

        // Now execute it
        $this->sut->executeExistingInstance('exec-existing', $instance->getId());

        // Should have dispatched WorkflowStarted
        $this->assertNotEmpty($events);
    }

    // =========================================================================
    // executeAsyncStep()
    // =========================================================================

    public function testExecuteAsyncStepThrowsForUnknownDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->sut->executeAsyncStep('some-id', 'step1', 'nonexistent');
    }

    public function testExecuteAsyncStepThrowsForUnknownStep(): void
    {
        $this->sut->define('async-step-test')
            ->addStep('step1', WMStubJob::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Step 'nonexistent' not found in workflow 'async-step-test'");

        $this->sut->executeAsyncStep('some-id', 'nonexistent', 'async-step-test');
    }

    public function testExecuteAsyncStepCompletesQueuedStep(): void
    {
        $this->sut->define('async-exec')
            ->addStep('step1', WMStubJob::class);

        // Create instance, start it, and queue the step
        $instance = $this->sut->runAsync('async-exec');
        $instanceId = $instance->getId();

        // Set instance to running with step1 queued
        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['step1' => WorkflowStepStatus::Queued->value]),
        ]);

        $this->sut->executeAsyncStep($instanceId, 'step1', 'async-exec');

        // Reload and verify
        $reloaded = $this->sut->getInstance($instanceId);
        $this->assertSame(WorkflowStepStatus::Completed->value, $reloaded->getStepStatus('step1'));
    }

    public function testExecuteAsyncStepSkipsNonRunningWorkflow(): void
    {
        $this->sut->define('async-skip')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('async-skip');
        $instanceId = $instance->getId();

        // Instance is pending, not running - step should not execute
        DB::table('station_workflows')->where('id', $instanceId)->update([
            'step_statuses' => json_encode(['step1' => WorkflowStepStatus::Queued->value]),
        ]);

        $this->sut->executeAsyncStep($instanceId, 'step1', 'async-skip');

        $reloaded = $this->sut->getInstance($instanceId);
        // Step should still be queued since workflow is not running
        $this->assertSame(WorkflowStepStatus::Queued->value, $reloaded->getStepStatus('step1'));
    }

    public function testExecuteAsyncStepSkipsNonQueuedStep(): void
    {
        $this->sut->define('async-not-queued')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('async-not-queued');
        $instanceId = $instance->getId();

        // Set instance to running but step1 is already completed
        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['step1' => WorkflowStepStatus::Completed->value]),
        ]);

        $this->sut->executeAsyncStep($instanceId, 'step1', 'async-not-queued');

        $reloaded = $this->sut->getInstance($instanceId);
        // Step should still be completed (not re-executed)
        $this->assertSame(WorkflowStepStatus::Completed->value, $reloaded->getStepStatus('step1'));
    }

    public function testExecuteAsyncStepHandlesFailureAndRethrows(): void
    {
        $this->sut->define('async-fail')
            ->addStep('step1', WMFailingJob::class);

        $instance = $this->sut->runAsync('async-fail');
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['step1' => WorkflowStepStatus::Queued->value]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Job execution failed');

        $this->sut->executeAsyncStep($instanceId, 'step1', 'async-fail');
    }

    public function testExecuteAsyncStepRecordsFailureInDatabase(): void
    {
        $this->sut->define('async-fail-db')
            ->addStep('step1', WMFailingJob::class);

        $instance = $this->sut->runAsync('async-fail-db');
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['step1' => WorkflowStepStatus::Queued->value]),
        ]);

        try {
            $this->sut->executeAsyncStep($instanceId, 'step1', 'async-fail-db');
        } catch (RuntimeException) {
            // Expected
        }

        $reloaded = $this->sut->getInstance($instanceId);
        $this->assertSame(WorkflowStepStatus::Failed->value, $reloaded->getStepStatus('step1'));
    }

    public function testExecuteAsyncStepDispatchesStepCompletedEvent(): void
    {
        $events = [];
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(WorkflowStepCompleted::class, static function (WorkflowStepCompleted $event) use (&$events): void {
            $events[] = $event;
        });

        $this->sut = new WorkflowManager($dispatcher, ['table' => 'station_workflows']);

        $this->sut->define('async-event')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('async-event');
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['step1' => WorkflowStepStatus::Queued->value]),
        ]);

        $this->sut->executeAsyncStep($instanceId, 'step1', 'async-event');

        $this->assertNotEmpty($events);
        $this->assertSame('step1', $events[0]->stepName);
    }

    public function testExecuteAsyncStepHandlesBranchStep(): void
    {
        $this->sut->define('async-branch')
            ->addBranch(
                'router',
                static fn(array $context) => $context['type'] ?? 'a',
                [
                    'a' => WMBranchAJob::class,
                    'b' => WMBranchBJob::class,
                ],
            );

        $instance = $this->sut->runAsync('async-branch', ['type' => 'b']);
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['router' => WorkflowStepStatus::Queued->value]),
        ]);

        $this->sut->executeAsyncStep($instanceId, 'router', 'async-branch');

        $reloaded = $this->sut->getInstance($instanceId);
        $this->assertSame(WorkflowStepStatus::Completed->value, $reloaded->getStepStatus('router'));

        $result = $reloaded->getStepResult('router');
        $this->assertSame('b', $result['branch']);
    }

    public function testExecuteAsyncStepSkipsBranchWhenSelectorReturnsNull(): void
    {
        $this->sut->define('async-branch-null')
            ->addBranch(
                'router',
                static fn(array $context) => null,
                [
                    'a' => WMBranchAJob::class,
                ],
            );

        $instance = $this->sut->runAsync('async-branch-null');
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['router' => WorkflowStepStatus::Queued->value]),
        ]);

        $this->sut->executeAsyncStep($instanceId, 'router', 'async-branch-null');

        $reloaded = $this->sut->getInstance($instanceId);
        $this->assertSame(WorkflowStepStatus::Skipped->value, $reloaded->getStepStatus('router'));
    }

    public function testExecuteAsyncStepThrowsForUnknownBranch(): void
    {
        $this->sut->define('async-branch-unknown')
            ->addBranch(
                'router',
                static fn(array $context) => 'nonexistent',
                [
                    'a' => WMBranchAJob::class,
                ],
            );

        $instance = $this->sut->runAsync('async-branch-unknown');
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['router' => WorkflowStepStatus::Queued->value]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown branch: nonexistent');

        $this->sut->executeAsyncStep($instanceId, 'router', 'async-branch-unknown');
    }

    // =========================================================================
    // handleAsyncStepFailure()
    // =========================================================================

    public function testHandleAsyncStepFailureRecordsStepFailure(): void
    {
        $this->sut->define('failure-handler')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('failure-handler');
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
            'step_statuses' => json_encode(['step1' => WorkflowStepStatus::Running->value]),
        ]);

        $this->sut->handleAsyncStepFailure($instanceId, 'step1', 'Something went wrong');

        $reloaded = $this->sut->getInstance($instanceId);
        $this->assertSame(WorkflowStepStatus::Failed->value, $reloaded->getStepStatus('step1'));
    }

    public function testHandleAsyncStepFailureWithStarterStepFailsEntireWorkflow(): void
    {
        $events = [];
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(WorkflowFailed::class, static function (WorkflowFailed $event) use (&$events): void {
            $events[] = $event;
        });

        $this->sut = new WorkflowManager($dispatcher, ['table' => 'station_workflows']);

        $this->sut->define('starter-fail')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->runAsync('starter-fail');
        $instanceId = $instance->getId();

        DB::table('station_workflows')->where('id', $instanceId)->update([
            'status' => WorkflowStatus::Running->value,
        ]);

        $this->sut->handleAsyncStepFailure($instanceId, '_starter', 'Starter crashed');

        $reloaded = $this->sut->getInstance($instanceId);
        $this->assertSame(WorkflowStatus::Failed->value, $reloaded->getStatus());
        $this->assertStringContainsString('Starter crashed', $reloaded->getError());
        $this->assertCount(1, $events);
    }

    public function testHandleAsyncStepFailureDoesNotModifyFinishedWorkflow(): void
    {
        $this->sut->define('already-finished')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->run('already-finished');
        $instanceId = $instance->getId();

        // Workflow is already completed
        $this->sut->handleAsyncStepFailure($instanceId, 'step1', 'Late failure');

        $reloaded = $this->sut->getInstance($instanceId);
        // Status should still be completed, not changed to failed
        $this->assertSame(WorkflowStatus::Completed->value, $reloaded->getStatus());
    }

    public function testHandleAsyncStepFailureSilentlyHandlesNonexistentInstance(): void
    {
        $this->expectNotToPerformAssertions();

        // Should not throw for nonexistent instance (best-effort)
        $this->sut->handleAsyncStepFailure('nonexistent', 'step1', 'error');
    }

    // =========================================================================
    // checkWorkflowCompletion() - tested indirectly via run()
    // =========================================================================

    // =========================================================================
    // saveInstance() - insert vs update tracking (tested indirectly)
    // =========================================================================

    public function testSaveInstanceInsertsOnFirstCallAndUpdatesSubsequently(): void
    {
        $this->sut->define('save-test')
            ->addStep('step1', WMStubJob::class);

        $instance = $this->sut->run('save-test');

        // Verify exactly one row exists
        $count = DB::table('station_workflows')->where('id', $instance->getId())->count();
        $this->assertSame(1, $count);
    }

    public function testSaveInstanceCleansUpMemoryForFinishedInstances(): void
    {
        $this->sut->define('cleanup-test')
            ->addStep('step1', WMStubJob::class);

        // Run creates and finishes the workflow, which should clean up persistedInstances
        $instance = $this->sut->run('cleanup-test');

        // Running again should create a new INSERT, not an UPDATE
        // (verifying the persistedInstances memory was cleaned)
        $instance2 = $this->sut->run('cleanup-test');

        $this->assertNotSame($instance->getId(), $instance2->getId());

        $count = DB::table('station_workflows')->count();
        $this->assertSame(2, $count);
    }

    // =========================================================================
    // getTable() config
    // =========================================================================

    public function testDefaultTableIsStationWorkflows(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);
        $managerWithDefaults = new WorkflowManager($dispatcher);

        $managerWithDefaults->define('table-test')
            ->addStep('step1', WMStubJob::class);

        $instance = $managerWithDefaults->run('table-test');

        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $this->assertNotNull($row);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testMultipleRunsCreateIndependentInstances(): void
    {
        $this->sut->define('multi-run')
            ->addStep('step1', WMStubJob::class);

        $instance1 = $this->sut->run('multi-run', ['run' => 1]);
        $instance2 = $this->sut->run('multi-run', ['run' => 2]);

        $this->assertNotSame($instance1->getId(), $instance2->getId());
        $this->assertSame(['run' => 1], $instance1->getInput());
        $this->assertSame(['run' => 2], $instance2->getInput());
    }

    public function testRunValidatesDefinitionBeforeExecution(): void
    {
        // Use define() which does NOT validate, then tamper with it
        $definition = $this->sut->define('validate-on-run');
        // The definition has no steps, so validate() inside run() should throw

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow must have at least one step');

        $this->sut->run('validate-on-run');
    }

    public function testRunAsyncValidatesDefinitionBeforeExecution(): void
    {
        $this->sut->define('validate-async');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow must have at least one step');

        $this->sut->runAsync('validate-async');
    }

    // =========================================================================
    // recoverStuckWorkflows() -- limited testing since it relies on timestamps
    // =========================================================================

    public function testRecoverStuckWorkflowsReturnsEmptyWhenNoStuckWorkflows(): void
    {
        $result = $this->sut->recoverStuckWorkflows();

        $this->assertSame([], $result);
    }

    // =========================================================================
    // Testbench setup
    // =========================================================================

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }
}
