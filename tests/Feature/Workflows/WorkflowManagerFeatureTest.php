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
use Station\Events\WorkflowCompleted;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStarted;
use Station\Events\WorkflowStepCompleted;
use Station\StationServiceProvider;
use Station\Workflows\WorkflowDefinition;
use Station\Workflows\WorkflowInstance;
use Station\Workflows\WorkflowManager;

/**
 * A simple job class for testing synchronous workflow execution.
 */
class TestWorkflowJob
{
    public function __construct(
        public readonly string $instanceId,
        public readonly array $context,
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        return 'done';
    }
}

/**
 * A job class that updates context.
 */
class ContextUpdatingJob
{
    private array $contextUpdates = [];

    public function __construct(
        public readonly string $instanceId,
        public readonly array $context,
        public readonly array $results = [],
    ) {}

    public function handle(): string
    {
        $this->contextUpdates = ['processed' => true, 'count' => ($this->context['count'] ?? 0) + 1];

        return 'updated';
    }

    public function getContextUpdates(): array
    {
        return $this->contextUpdates;
    }
}

/**
 * A job class that throws an exception.
 */
class FailingWorkflowJob
{
    public function __construct(
        public readonly string $instanceId,
        public readonly array $context,
        public readonly array $results = [],
    ) {}

    public function handle(): void
    {
        throw new RuntimeException('Step failed intentionally');
    }
}

/**
 * Feature tests for WorkflowManager covering DB-dependent methods.
 *
 * Note: run() returns the instance before executeWorkflow() finishes updating it,
 * because executeWorkflow re-fetches the instance from DB into a local variable.
 * So we use getInstance() or DB queries to verify final state after run().
 */
class WorkflowManagerFeatureTest extends TestCase
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
        // Clear persisted instance tracking (static property)
        $ref = new ReflectionClass(WorkflowManager::class);
        $prop = $ref->getProperty('persistedInstances');
        $prop->setValue($this->manager, []);
        parent::tearDown();
    }

    // ---- define / register / getDefinition / getDefinitions ----

    public function testDefineCreatesAndRegistersDefinition(): void
    {
        $definition = $this->manager->define('order-flow');

        $this->assertInstanceOf(WorkflowDefinition::class, $definition);
        $this->assertSame('order-flow', $definition->getName());
        $this->assertSame($definition, $this->manager->getDefinition('order-flow'));
    }

    public function testRegisterValidatesDefinition(): void
    {
        $definition = WorkflowDefinition::define('test');
        $definition->addStep('step1', TestWorkflowJob::class);
        $this->manager->register($definition);

        $this->assertSame($definition, $this->manager->getDefinition('test'));
    }

    public function testRegisterRejectsInvalidDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $definition = WorkflowDefinition::define('empty');
        // No steps added -> validate() should fail
        $this->manager->register($definition);
    }

    public function testGetDefinitionReturnsNullForUnknown(): void
    {
        $this->assertNull($this->manager->getDefinition('nonexistent'));
    }

    public function testGetDefinitionsReturnsAllRegistered(): void
    {
        $this->manager->define('flow1')->addStep('s1', TestWorkflowJob::class);
        $this->manager->define('flow2')->addStep('s2', TestWorkflowJob::class);

        $definitions = $this->manager->getDefinitions();
        $this->assertCount(2, $definitions);
        $this->assertArrayHasKey('flow1', $definitions);
        $this->assertArrayHasKey('flow2', $definitions);
    }

    // ---- run (synchronous) ----

    public function testRunExecutesSingleStepWorkflow(): void
    {
        $definition = $this->manager->define('simple');
        $definition->addStep('do-work', TestWorkflowJob::class);

        $instance = $this->manager->run('simple');

        // run() returns instance before executeWorkflow finishes;
        // verify final state from DB
        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame('done', $loaded->getStepResult('do-work'));
    }

    public function testRunExecutesMultiStepLinearWorkflow(): void
    {
        $definition = $this->manager->define('linear');
        $definition->addStep('step1', TestWorkflowJob::class);
        $definition->addStep('step2', TestWorkflowJob::class, ['step1']);
        $definition->addStep('step3', TestWorkflowJob::class, ['step2']);

        $instance = $this->manager->run('linear');

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame('done', $loaded->getStepResult('step1'));
        $this->assertSame('done', $loaded->getStepResult('step2'));
        $this->assertSame('done', $loaded->getStepResult('step3'));
    }

    public function testRunPersistsInstanceInDatabase(): void
    {
        $definition = $this->manager->define('persist');
        $definition->addStep('step1', TestWorkflowJob::class);

        $instance = $this->manager->run('persist');

        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertSame('persist', $row->definition_name);
    }

    public function testRunDispatchesWorkflowStartedEvent(): void
    {
        $this->events->shouldReceive('dispatch')
            ->with(Mockery::type(WorkflowStarted::class))
            ->once();

        $definition = $this->manager->define('events-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $instance = $this->manager->run('events-test');
        $this->assertNotNull($instance->getId());
    }

    public function testRunDispatchesStepCompletedEvent(): void
    {
        $dispatched = false;
        $this->events->shouldReceive('dispatch')
            ->with(Mockery::type(WorkflowStepCompleted::class))
            ->once()
            ->andReturnUsing(static function () use (&$dispatched): void {
                $dispatched = true;
            });

        $definition = $this->manager->define('step-event-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $this->manager->run('step-event-test');
        $this->assertTrue($dispatched);
    }

    public function testRunDispatchesWorkflowCompletedEvent(): void
    {
        $dispatched = false;
        $this->events->shouldReceive('dispatch')
            ->with(Mockery::type(WorkflowCompleted::class))
            ->once()
            ->andReturnUsing(static function () use (&$dispatched): void {
                $dispatched = true;
            });

        $definition = $this->manager->define('complete-event-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $this->manager->run('complete-event-test');
        $this->assertTrue($dispatched);
    }

    public function testRunThrowsForUndefinedWorkflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->manager->run('nonexistent');
    }

    public function testRunWithContextUpdatingJob(): void
    {
        $definition = $this->manager->define('context-test');
        $definition->addStep('update', ContextUpdatingJob::class);

        $instance = $this->manager->run('context-test');

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $context = $loaded->getContext();
        $this->assertTrue($context['processed']);
        $this->assertSame(1, $context['count']);
    }

    public function testRunWithFailingStep(): void
    {
        $this->events->shouldReceive('dispatch')
            ->with(Mockery::type(WorkflowFailed::class))
            ->once();

        $definition = $this->manager->define('failing');
        $definition->addStep('broken', FailingWorkflowJob::class);

        $instance = $this->manager->run('failing');

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('failed', $loaded->getStatus());
        $this->assertStringContainsString('Step failed intentionally', $loaded->getError());
    }

    public function testRunWithConditionalStepSkipped(): void
    {
        $definition = $this->manager->define('conditional');
        $definition->addStep('step1', TestWorkflowJob::class);
        $definition->addConditionalStep(
            'optional',
            TestWorkflowJob::class,
            static fn(array $ctx) => ($ctx['run_optional'] ?? false) === true,
            ['step1'],
        );

        $instance = $this->manager->run('conditional', ['run_optional' => false]);

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame('skipped', $loaded->getStepStatus('optional'));
    }

    public function testRunWithConditionalStepExecuted(): void
    {
        $definition = $this->manager->define('conditional-run');
        $definition->addStep('step1', TestWorkflowJob::class);
        $definition->addConditionalStep(
            'optional',
            TestWorkflowJob::class,
            static fn(array $ctx) => ($ctx['run_optional'] ?? false) === true,
            ['step1'],
        );

        $instance = $this->manager->run('conditional-run', ['run_optional' => true]);

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame('completed', $loaded->getStepStatus('optional'));
    }

    public function testRunWithParallelSteps(): void
    {
        $definition = $this->manager->define('parallel');
        $definition->addStep('start', TestWorkflowJob::class);
        $definition->addParallel('parallel-group', [
            'task-a' => TestWorkflowJob::class,
            'task-b' => TestWorkflowJob::class,
        ], ['start']);
        $definition->addStep('finish', TestWorkflowJob::class, ['parallel-group']);

        $instance = $this->manager->run('parallel');

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame('done', $loaded->getStepResult('task-a'));
        $this->assertSame('done', $loaded->getStepResult('task-b'));
        $this->assertSame('done', $loaded->getStepResult('finish'));
    }

    public function testRunWithInput(): void
    {
        $definition = $this->manager->define('with-input');
        $definition->addStep('step1', TestWorkflowJob::class);

        $instance = $this->manager->run('with-input', ['user_id' => 42]);

        $loaded = $this->manager->getInstance($instance->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame(42, $loaded->getInput()['user_id']);
    }

    // ---- getInstance ----

    public function testGetInstanceReturnsHydratedInstance(): void
    {
        $definition = $this->manager->define('get-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $original = $this->manager->run('get-test');

        $loaded = $this->manager->getInstance($original->getId());

        $this->assertNotNull($loaded);
        $this->assertSame($original->getId(), $loaded->getId());
        $this->assertSame('completed', $loaded->getStatus());
        $this->assertSame('get-test', $loaded->getDefinitionName());
    }

    public function testGetInstanceReturnsNullForUnknown(): void
    {
        $this->assertNull($this->manager->getInstance('nonexistent-id'));
    }

    public function testGetInstanceHydratesAllFields(): void
    {
        $id = Uuid::uuid7()->toString();
        $defId = Uuid::uuid7()->toString();

        DB::table('station_workflows')->insert([
            'id' => $id,
            'definition_id' => $defId,
            'definition_name' => 'test-flow',
            'connection' => 'redis',
            'status' => 'running',
            'current_step' => 'step2',
            'input' => json_encode(['key' => 'value']),
            'context' => json_encode(['ctx' => 'data']),
            'results' => json_encode(['step1' => 'result1']),
            'step_statuses' => json_encode(['step1' => 'completed', 'step2' => 'running']),
            'definition_steps' => json_encode([['name' => 'step1'], ['name' => 'step2']]),
            'error' => null,
            'progress' => 50,
            'started_at' => '2025-01-15 10:00:00',
            'completed_at' => null,
            'created_at' => '2025-01-15 09:55:00',
            'updated_at' => now()->toDateTimeString(),
        ]);

        $instance = $this->manager->getInstance($id);

        $this->assertNotNull($instance);
        $this->assertSame($id, $instance->getId());
        $this->assertSame($defId, $instance->getDefinitionId());
        $this->assertSame('test-flow', $instance->getDefinitionName());
        $this->assertSame('redis', $instance->getConnection());
        $this->assertSame('running', $instance->getStatus());
        $this->assertSame('step2', $instance->getCurrentStep());
        $this->assertSame(['key' => 'value'], $instance->getInput());
        $this->assertSame(['ctx' => 'data'], $instance->getContext());
        $this->assertSame('result1', $instance->getStepResult('step1'));
        $this->assertSame('completed', $instance->getStepStatus('step1'));
        $this->assertSame('running', $instance->getStepStatus('step2'));
        $this->assertCount(2, $instance->getDefinitionSteps());
    }

    // ---- getInstances ----

    public function testGetInstancesReturnsInstancesForDefinition(): void
    {
        $definition = $this->manager->define('multi');
        $definition->addStep('step1', TestWorkflowJob::class);

        $this->manager->run('multi');
        $this->manager->run('multi');

        $instances = $this->manager->getInstances('multi');

        $this->assertCount(2, $instances);
        $this->assertContainsOnlyInstancesOf(WorkflowInstance::class, $instances);
    }

    public function testGetInstancesRespectsLimit(): void
    {
        $definition = $this->manager->define('limited');
        $definition->addStep('step1', TestWorkflowJob::class);

        $this->manager->run('limited');
        $this->manager->run('limited');
        $this->manager->run('limited');

        $instances = $this->manager->getInstances('limited', 2);

        $this->assertCount(2, $instances);
    }

    public function testGetInstancesReturnsEmptyForUnknownDefinition(): void
    {
        $instances = $this->manager->getInstances('nonexistent');
        $this->assertEmpty($instances);
    }

    // ---- status ----

    public function testStatusReturnsStatusArray(): void
    {
        $definition = $this->manager->define('status-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $instance = $this->manager->run('status-test');

        $status = $this->manager->status('status-test', $instance->getId());

        $this->assertNotNull($status);
        $this->assertSame('completed', $status['status']);
        $this->assertNull($status['current_step']);
        $this->assertSame(100, $status['progress']);
        $this->assertNull($status['error']);
    }

    public function testStatusReturnsNullForUnknownInstance(): void
    {
        $this->assertNull($this->manager->status('any', 'nonexistent'));
    }

    public function testStatusReturnsNullWhenDefinitionNameMismatch(): void
    {
        $definition = $this->manager->define('real-name');
        $definition->addStep('step1', TestWorkflowJob::class);

        $instance = $this->manager->run('real-name');

        $this->assertNull($this->manager->status('wrong-name', $instance->getId()));
    }

    // ---- cancel ----

    public function testCancelRunningWorkflow(): void
    {
        $id = $this->insertWorkflowRow('running');

        $result = $this->manager->cancel($id);

        $this->assertTrue($result);

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('cancelled', $row->status);
    }

    public function testCancelPendingWorkflow(): void
    {
        $id = $this->insertWorkflowRow('pending');

        $this->assertTrue($this->manager->cancel($id));
    }

    public function testCancelFinishedWorkflowReturnsFalse(): void
    {
        $id = $this->insertWorkflowRow('completed');

        $this->assertFalse($this->manager->cancel($id));
    }

    public function testCancelNonexistentReturnsFalse(): void
    {
        $this->assertFalse($this->manager->cancel('nonexistent'));
    }

    // ---- pause ----

    public function testPauseRunningWorkflow(): void
    {
        $id = $this->insertWorkflowRow('running');

        $result = $this->manager->pause($id);

        $this->assertTrue($result);

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('paused', $row->status);
    }

    public function testPauseNonRunningReturnsFalse(): void
    {
        $id = $this->insertWorkflowRow('pending');

        $this->assertFalse($this->manager->pause($id));
    }

    public function testPauseNonexistentReturnsFalse(): void
    {
        $this->assertFalse($this->manager->pause('nonexistent'));
    }

    // ---- resume ----

    public function testResumePausedWorkflow(): void
    {
        Bus::fake();

        $definition = $this->manager->define('resumable');
        $definition->addStep('step1', TestWorkflowJob::class);

        $id = $this->insertWorkflowRow('paused', 'resumable');

        $result = $this->manager->resume($id);

        $this->assertTrue($result);

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('running', $row->status);
    }

    public function testResumeNonPausedReturnsFalse(): void
    {
        $id = $this->insertWorkflowRow('running');

        $this->assertFalse($this->manager->resume($id));
    }

    public function testResumeWithoutDefinitionReturnsFalse(): void
    {
        $id = $this->insertWorkflowRow('paused', 'unknown-def');

        $this->assertFalse($this->manager->resume($id));
    }

    public function testResumeNonexistentReturnsFalse(): void
    {
        $this->assertFalse($this->manager->resume('nonexistent'));
    }

    // ---- runAsync ----

    public function testRunAsyncPersistsInstanceAndDispatchesJob(): void
    {
        Bus::fake();

        $definition = $this->manager->define('async-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $instance = $this->manager->runAsync('async-test');

        $this->assertSame('pending', $instance->getStatus());

        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $this->assertNotNull($row);
        $this->assertSame('async-test', $row->definition_name);
    }

    public function testRunAsyncWithConnectionSetsConnection(): void
    {
        Bus::fake();

        $definition = $this->manager->define('async-conn');
        $definition->addStep('step1', TestWorkflowJob::class);

        $instance = $this->manager->runAsync('async-conn', [], 'redis');

        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $this->assertSame('redis', $row->connection);
    }

    public function testRunAsyncThrowsForUndefinedWorkflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager->runAsync('nonexistent');
    }

    public function testRunAsyncSnapshotsDefinitionSteps(): void
    {
        Bus::fake();

        $definition = $this->manager->define('async-snapshot');
        $definition->addStep('step1', TestWorkflowJob::class);
        $definition->addStep('step2', TestWorkflowJob::class, ['step1']);

        $instance = $this->manager->runAsync('async-snapshot');

        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $steps = json_decode($row->definition_steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame('step1', $steps[0]['name']);
        $this->assertSame('step2', $steps[1]['name']);
    }

    // ---- handleAsyncStepFailure ----

    public function testHandleAsyncStepFailureFailsStep(): void
    {
        $this->events->shouldReceive('dispatch')
            ->with(Mockery::type(WorkflowFailed::class))
            ->once();

        // Register the definition so handleAsyncStepFailure can look it up
        $definition = $this->manager->define('failure-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $id = $this->insertWorkflowRow('running', 'failure-test', ['step1' => 'running']);

        $this->manager->handleAsyncStepFailure($id, 'step1', 'Timeout exceeded');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $statuses = json_decode($row->step_statuses, true);
        $this->assertSame('failed', $statuses['step1']);
    }

    public function testHandleAsyncStepFailureStarterPseudoStepFailsWorkflow(): void
    {
        $this->events->shouldReceive('dispatch')
            ->with(Mockery::type(WorkflowFailed::class))
            ->once();

        $id = $this->insertWorkflowRow('running');

        $this->manager->handleAsyncStepFailure($id, '_starter', 'Starter job failed');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Workflow starter job failed', $row->error);
    }

    public function testHandleAsyncStepFailureSkipsFinishedWorkflow(): void
    {
        $id = $this->insertWorkflowRow('completed');

        $this->manager->handleAsyncStepFailure($id, 'step1', 'error');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('completed', $row->status);
    }

    public function testHandleAsyncStepFailureHandlesNonexistentInstance(): void
    {
        $this->expectNotToPerformAssertions();
        $this->manager->handleAsyncStepFailure('nonexistent', 'step1', 'error');
    }

    // ---- recoverStuckWorkflows ----

    public function testRecoverStuckWorkflowsTimesOutRunningSteps(): void
    {
        $definition = $this->manager->define('stuck-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $id = $this->insertWorkflowRow('running', 'stuck-test', ['step1' => 'running']);

        // Set updated_at far in the past
        DB::table('station_workflows')
            ->where('id', $id)
            ->update(['updated_at' => now()->subMinutes(10)->toDateTimeString()]);

        $recovered = $this->manager->recoverStuckWorkflows(300);

        $this->assertNotEmpty($recovered);
        $this->assertSame('timed_out', $recovered[0]['action']);
        $this->assertSame('step1', $recovered[0]['step']);
    }

    public function testRecoverStuckWorkflowsReturnsEmptyWhenNoStuck(): void
    {
        $recovered = $this->manager->recoverStuckWorkflows();
        $this->assertEmpty($recovered);
    }

    public function testRecoverStuckWorkflowsAdvancesStaleWorkflows(): void
    {
        Bus::fake();

        $definition = $this->manager->define('stale-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $id = $this->insertWorkflowRow('running', 'stale-test');

        // Set updated_at far in the past but no stuck steps
        DB::table('station_workflows')
            ->where('id', $id)
            ->update(['updated_at' => now()->subMinutes(10)->toDateTimeString()]);

        $recovered = $this->manager->recoverStuckWorkflows(300);

        $this->assertNotEmpty($recovered);
        $this->assertSame('advanced', $recovered[0]['action']);
    }

    public function testRecoverStuckWorkflowsRedispatchesQueuedSteps(): void
    {
        Bus::fake();

        $definition = $this->manager->define('queued-stuck');
        $definition->addStep('step1', TestWorkflowJob::class);

        $id = $this->insertWorkflowRow('running', 'queued-stuck', ['step1' => 'queued']);

        DB::table('station_workflows')
            ->where('id', $id)
            ->update(['updated_at' => now()->subMinutes(10)->toDateTimeString()]);

        $recovered = $this->manager->recoverStuckWorkflows(300);

        $this->assertNotEmpty($recovered);
        $this->assertSame('redispatched', $recovered[0]['action']);
        $this->assertSame('step1', $recovered[0]['step']);
    }

    // ---- executeExistingInstance ----

    public function testExecuteExistingInstanceThrowsForUndefinedWorkflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager->executeExistingInstance('nonexistent', 'some-id');
    }

    public function testExecuteExistingInstanceThrowsForMissingInstance(): void
    {
        $definition = $this->manager->define('exec-test');
        $definition->addStep('step1', TestWorkflowJob::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->manager->executeExistingInstance('exec-test', 'nonexistent');
    }

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

    // ---- Helper methods ----

    private function insertWorkflowRow(
        string $status,
        string $definitionName = 'test-workflow',
        array $stepStatuses = [],
    ): string {
        $id = Uuid::uuid7()->toString();

        DB::table('station_workflows')->insert([
            'id' => $id,
            'definition_id' => Uuid::uuid7()->toString(),
            'definition_name' => $definitionName,
            'connection' => null,
            'status' => $status,
            'current_step' => null,
            'input' => '{}',
            'context' => '{}',
            'results' => '{}',
            'step_statuses' => json_encode($stepStatuses),
            'definition_steps' => '[]',
            'error' => null,
            'progress' => 0,
            'started_at' => $status !== 'pending' ? now()->toDateTimeString() : null,
            'completed_at' => \in_array($status, ['completed', 'failed', 'cancelled'], true) ? now()->toDateTimeString() : null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
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
