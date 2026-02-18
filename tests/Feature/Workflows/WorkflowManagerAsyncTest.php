<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Workflows;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use RuntimeException;
use Station\Enums\WorkflowStepStatus;
use Station\StationServiceProvider;
use Station\Workflows\WorkflowDefinition;
use Station\Workflows\WorkflowManager;

/**
 * A simple job class for async step execution tests.
 */
class AsyncTestJob
{
    public function __construct(
        public readonly string $instanceId,
        public readonly array $context,
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        return 'async-done';
    }
}

/**
 * A job class that provides context updates for async step tests.
 */
class AsyncContextJob
{
    private array $contextUpdates = [];

    public function __construct(
        public readonly string $instanceId,
        public readonly array $context,
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        $this->contextUpdates = ['async_processed' => true];

        return 'context-updated';
    }

    public function getContextUpdates(): array
    {
        return $this->contextUpdates;
    }
}

/**
 * A job that throws an exception during async execution.
 */
class AsyncFailingJob
{
    public function __construct(
        public readonly string $instanceId,
        public readonly array $context,
        public readonly array $results = [],
    ) {}

    public function handle(): void
    {
        throw new RuntimeException('Async step failed');
    }
}

/**
 * A branch job for branch step testing.
 */
class BranchJobA
{
    public function __construct(
        public readonly string $instanceId,
        public readonly array $context,
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        return 'branch-a-result';
    }
}

/**
 * Feature tests for WorkflowManager covering:
 * - executeExistingInstance with valid and invalid inputs
 * - executeAsyncStep with step completion, context updates, and failure
 * - executeAsyncStep with branch steps
 * - handleAsyncStepFailure for regular step with definition
 * - recoverStuckWorkflows with advanced gap path (no queued/running steps)
 * - runAsync with no connection
 * - executeBranchStep (sync) with unknown branch
 */
class WorkflowManagerAsyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private WorkflowManager $manager;

    private Dispatcher&MockInterface $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->events = Mockery::mock(Dispatcher::class);
        $this->events->shouldReceive('dispatch')->byDefault();

        $this->manager = new WorkflowManager(
            $this->events,
            ['table' => 'station_workflows'],
        );
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(WorkflowManager::class);
        $prop = $ref->getProperty('persistedInstances');
        $prop->setValue($this->manager, []);
        parent::tearDown();
    }

    // ---- executeExistingInstance ----

    public function testExecuteExistingInstanceStartsAndAdvancesWorkflow(): void
    {
        Bus::fake();

        $def = WorkflowDefinition::define('existing-test');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $id = $this->insertWorkflow('pending', 'existing-test');

        $this->manager->executeExistingInstance('existing-test', $id);

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('running', $row->status);
    }

    public function testExecuteExistingInstanceThrowsForUndefinedDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->manager->executeExistingInstance('nonexistent', Uuid::uuid7()->toString());
    }

    public function testExecuteExistingInstanceThrowsForMissingInstance(): void
    {
        $def = WorkflowDefinition::define('missing-instance');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->manager->executeExistingInstance('missing-instance', Uuid::uuid7()->toString());
    }

    public function testExecuteExistingInstanceSnapshotsDefinitionStepsIfEmpty(): void
    {
        Bus::fake();

        $def = WorkflowDefinition::define('snap-test');
        $def->addStep('step1', AsyncTestJob::class);
        $def->addStep('step2', AsyncTestJob::class, ['step1']);
        $this->manager->register($def);

        // Insert with empty definition_steps
        $id = $this->insertWorkflow('pending', 'snap-test', null, [], null, '[]');

        $this->manager->executeExistingInstance('snap-test', $id);

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $steps = json_decode($row->definition_steps, true);
        $this->assertNotEmpty($steps);
        $this->assertCount(2, $steps);
    }

    // ---- executeAsyncStep ----

    public function testExecuteAsyncStepCompletesStep(): void
    {
        $def = WorkflowDefinition::define('async-step');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $id = $this->insertWorkflow('running', 'async-step', null, [
            'step1' => WorkflowStepStatus::Queued->value,
        ]);

        $this->manager->executeAsyncStep($id, 'step1', 'async-step');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $statuses = json_decode($row->step_statuses, true);
        $this->assertSame('completed', $statuses['step1']);
    }

    public function testExecuteAsyncStepWithContextUpdates(): void
    {
        $def = WorkflowDefinition::define('ctx-async');
        $def->addStep('step1', AsyncContextJob::class);
        $this->manager->register($def);

        $id = $this->insertWorkflow('running', 'ctx-async', null, [
            'step1' => WorkflowStepStatus::Queued->value,
        ]);

        $this->manager->executeAsyncStep($id, 'step1', 'ctx-async');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $context = json_decode($row->context, true);
        $this->assertTrue($context['async_processed']);
    }

    public function testExecuteAsyncStepThrowsForUndefinedDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->manager->executeAsyncStep('some-id', 'step1', 'nonexistent');
    }

    public function testExecuteAsyncStepThrowsForUndefinedStep(): void
    {
        $def = WorkflowDefinition::define('no-step');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Step 'nonexistent' not found");

        $this->manager->executeAsyncStep('some-id', 'nonexistent', 'no-step');
    }

    public function testExecuteAsyncStepSkipsWhenWorkflowNotRunning(): void
    {
        $def = WorkflowDefinition::define('cancelled-wf');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $id = $this->insertWorkflow('cancelled', 'cancelled-wf', null, [
            'step1' => WorkflowStepStatus::Queued->value,
        ]);

        // Should not throw or modify
        $this->manager->executeAsyncStep($id, 'step1', 'cancelled-wf');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('cancelled', $row->status);
    }

    public function testExecuteAsyncStepSkipsWhenStepNotQueued(): void
    {
        $def = WorkflowDefinition::define('already-done');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $id = $this->insertWorkflow('running', 'already-done', null, [
            'step1' => WorkflowStepStatus::Completed->value,
        ]);

        // Should return without executing since step is already completed
        $this->manager->executeAsyncStep($id, 'step1', 'already-done');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $statuses = json_decode($row->step_statuses, true);
        $this->assertSame('completed', $statuses['step1']);
    }

    public function testExecuteAsyncStepFailureRecordsErrorAndThrows(): void
    {
        $def = WorkflowDefinition::define('failing-async');
        $def->addStep('step1', AsyncFailingJob::class);
        $this->manager->register($def);

        $id = $this->insertWorkflow('running', 'failing-async', null, [
            'step1' => WorkflowStepStatus::Queued->value,
        ]);

        try {
            $this->manager->executeAsyncStep($id, 'step1', 'failing-async');
            $this->fail('Expected RuntimeException to be thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Async step failed', $e->getMessage());
        }

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $statuses = json_decode($row->step_statuses, true);
        $this->assertSame('failed', $statuses['step1']);
    }

    public function testExecuteAsyncStepSkipsForMissingInstance(): void
    {
        $def = WorkflowDefinition::define('missing-inst');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        // Should silently return - no instance found
        $this->manager->executeAsyncStep(Uuid::uuid7()->toString(), 'step1', 'missing-inst');

        // No exception means it was handled gracefully
        $this->addToAssertionCount(1);
    }

    // ---- handleAsyncStepFailure with regular step ----

    public function testHandleAsyncStepFailureForRegularStepWithDefinition(): void
    {
        $def = WorkflowDefinition::define('fail-step-def');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $id = $this->insertWorkflow('running', 'fail-step-def', null, [
            'step1' => WorkflowStepStatus::Running->value,
        ]);

        $this->manager->handleAsyncStepFailure($id, 'step1', 'Worker died');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $statuses = json_decode($row->step_statuses, true);
        $this->assertSame('failed', $statuses['step1']);
        $this->assertSame('failed', $row->status);
    }

    public function testHandleAsyncStepFailureForStepWithNoDefinition(): void
    {
        // Don't register the definition
        $id = $this->insertWorkflow('running', 'no-def-registered', null, [
            'step1' => WorkflowStepStatus::Running->value,
        ]);

        // Should still mark the step as failed even without definition
        $this->manager->handleAsyncStepFailure($id, 'step1', 'Error');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $statuses = json_decode($row->step_statuses, true);
        $this->assertSame('failed', $statuses['step1']);
    }

    // ---- recoverStuckWorkflows with gap path ----

    public function testRecoverStuckWorkflowsAdvancesWhenNoQueuedOrRunningSteps(): void
    {
        Bus::fake();

        $def = WorkflowDefinition::define('gap-def');
        $def->addStep('step1', AsyncTestJob::class);
        $def->addStep('step2', AsyncTestJob::class, ['step1']);
        $this->manager->register($def);

        // All steps completed except step2 which is still pending (not queued/running)
        $id = $this->insertWorkflow('running', 'gap-def', null, [
            'step1' => WorkflowStepStatus::Completed->value,
            // step2 is not in step_statuses at all - gap
        ], now()->subSeconds(600)->toDateTimeString());

        $recovered = $this->manager->recoverStuckWorkflows(300);

        $this->assertNotEmpty($recovered);
        $this->assertSame('advanced', $recovered[0]['action']);
        $this->assertSame('*', $recovered[0]['step']);
    }

    public function testRecoverStuckWorkflowsWithNonExistentDefinitionSkipsWorkflow(): void
    {
        // Don't register any definition
        $id = $this->insertWorkflow('running', 'unregistered-def', null, [
            'step1' => WorkflowStepStatus::Queued->value,
        ], now()->subSeconds(600)->toDateTimeString());

        $recovered = $this->manager->recoverStuckWorkflows(300);

        // Should be empty because definition was not found
        $this->assertEmpty($recovered);
    }

    public function testRecoverStuckWorkflowsIgnoresNonRunningWorkflows(): void
    {
        $def = WorkflowDefinition::define('non-running');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $this->insertWorkflow('completed', 'non-running', null, [], now()->subSeconds(600)->toDateTimeString());
        $this->insertWorkflow('failed', 'non-running', null, [], now()->subSeconds(600)->toDateTimeString());

        $recovered = $this->manager->recoverStuckWorkflows(300);

        $this->assertEmpty($recovered);
    }

    // ---- runAsync without connection ----

    public function testRunAsyncWithoutConnectionDoesNotSetConnection(): void
    {
        Bus::fake();

        $def = WorkflowDefinition::define('no-conn');
        $def->addStep('step1', AsyncTestJob::class);
        $this->manager->register($def);

        $instance = $this->manager->runAsync('no-conn', ['key' => 'value']);

        $this->assertNull($instance->getConnection());
        $this->assertSame('pending', $instance->getStatus());

        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $this->assertNull($row->connection);
    }

    // ---- Sync execution: branch step with null branch (skip) ----

    public function testRunWithBranchStepSkippedWhenNoBranchSelected(): void
    {
        $definition = $this->manager->define('branch-skip');
        $definition->addStep('start', AsyncTestJob::class);
        $definition->addBranch(
            'router',
            static fn(array $ctx) => null, // No branch selected
            ['option-a' => BranchJobA::class],
            ['start'],
        );

        $instance = $this->manager->run('branch-skip');

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame('skipped', $loaded->getStepStatus('router'));
    }

    public function testRunWithBranchStepUnknownBranchFails(): void
    {
        $definition = $this->manager->define('branch-unknown');
        $definition->addStep('start', AsyncTestJob::class);
        $definition->addBranch(
            'router',
            static fn(array $ctx) => 'nonexistent-branch',
            ['option-a' => BranchJobA::class],
            ['start'],
        );

        $instance = $this->manager->run('branch-unknown');

        $loaded = $this->manager->getInstance($instance->getId());
        // Should fail because 'nonexistent-branch' is not in branches
        $this->assertSame('failed', $loaded->getStatus());
        $error = $loaded->getStepStatus('router');
        $this->assertSame('failed', $error);
    }

    public function testRunWithBranchStepSelectsCorrectBranch(): void
    {
        $definition = $this->manager->define('branch-select');
        $definition->addStep('start', AsyncTestJob::class);
        $definition->addBranch(
            'router',
            static fn(array $ctx) => 'option-a',
            ['option-a' => BranchJobA::class],
            ['start'],
        );

        $instance = $this->manager->run('branch-select');

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $result = $loaded->getStepResult('router');
        $this->assertIsArray($result);
        $this->assertSame('option-a', $result['branch']);
        $this->assertSame('branch-a-result', $result['result']);
    }

    // ---- checkWorkflowCompletion: deadlock detection ----

    public function testDeadlockedWorkflowFailsViaSyncExecution(): void
    {
        // A workflow where step1 fails and step2 depends on step1 --
        // this creates a deadlock that sync execution should detect
        $definition = $this->manager->define('deadlock');
        $definition->addStep('step1', AsyncFailingJob::class);
        $definition->addStep('step2', AsyncTestJob::class, ['step1']);

        $instance = $this->manager->run('deadlock');

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('failed', $loaded->getStatus());
        $this->assertSame('failed', $loaded->getStepStatus('step1'));
    }

    // ---- helpers ----

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    private function insertWorkflow(
        string $status,
        string $definitionName = 'test-wf',
        ?string $connection = null,
        array $stepStatuses = [],
        ?string $updatedAt = null,
        ?string $definitionSteps = null,
    ): string {
        $id = Uuid::uuid7()->toString();

        DB::table('station_workflows')->insert([
            'id' => $id,
            'definition_id' => Uuid::uuid7()->toString(),
            'definition_name' => $definitionName,
            'connection' => $connection,
            'status' => $status,
            'current_step' => null,
            'input' => '{}',
            'context' => '{}',
            'results' => '{}',
            'step_statuses' => json_encode($stepStatuses),
            'definition_steps' => $definitionSteps ?? '[]',
            'error' => null,
            'progress' => 0,
            'started_at' => $status !== 'pending' ? now()->toDateTimeString() : null,
            'completed_at' => \in_array($status, ['completed', 'failed', 'cancelled'], true) ? now()->toDateTimeString() : null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => $updatedAt ?? now()->toDateTimeString(),
        ]);

        return $id;
    }

    private function createTables(): void
    {
        $db = $this->app['db'];

        $db->statement('CREATE TABLE IF NOT EXISTS station_workflows (
            id VARCHAR(36) PRIMARY KEY,
            definition_id VARCHAR(36) NOT NULL,
            definition_name VARCHAR(255) NOT NULL,
            connection VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            current_step VARCHAR(255) NULL,
            input TEXT NOT NULL DEFAULT "{}",
            context TEXT NOT NULL DEFAULT "{}",
            results TEXT NOT NULL DEFAULT "{}",
            step_statuses TEXT NOT NULL DEFAULT "{}",
            definition_steps TEXT NULL,
            error TEXT NULL,
            progress INTEGER NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
