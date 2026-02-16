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
use Station\Enums\WorkflowStepStatus;
use Station\StationServiceProvider;
use Station\Workflows\WorkflowDefinition;
use Station\Workflows\WorkflowManager;

/**
 * Extended feature tests for WorkflowManager covering:
 * - cancel() on finished workflow returns false
 * - cancel() on running workflow returns true
 * - pause() on non-running workflow returns false
 * - resume() on non-paused workflow returns false
 * - resume() with no definition returns false
 * - getInstance() returns null for missing ID
 * - getInstance() returns hydrated instance for existing ID
 * - status() returns null for wrong definition name
 * - status() returns data for correct instance
 * - getInstances() returns empty array when no instances
 * - getInstances() returns instances for a definition
 * - handleAsyncStepFailure() marks starter failure
 * - handleAsyncStepFailure() on terminal workflow is no-op
 * - recoverStuckWorkflows() with no stuck workflows
 */
class WorkflowManagerExtendedTest extends TestCase
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
        // Clear static tracking
        $ref = new ReflectionClass(WorkflowManager::class);
        $prop = $ref->getProperty('persistedInstances');
        $prop->setValue($this->manager, []);
        parent::tearDown();
    }

    // ---- Helper: assertStringContains (PHPUnit 11 compatible) ----

    private static function assertStringContains(string $needle, ?string $haystack): void
    {
        self::assertNotNull($haystack, "Expected non-null string containing '{$needle}'");
        self::assertStringContainsString($needle, $haystack);
    }

    // ---- cancel ----

    public function testCancelRunningWorkflowReturnsTrue(): void
    {
        $id = $this->insertWorkflow('running');

        $this->assertTrue($this->manager->cancel($id));

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('cancelled', $row->status);
    }

    public function testCancelCompletedWorkflowReturnsFalse(): void
    {
        $id = $this->insertWorkflow('completed');

        $this->assertFalse($this->manager->cancel($id));
    }

    public function testCancelFailedWorkflowReturnsFalse(): void
    {
        $id = $this->insertWorkflow('failed');

        $this->assertFalse($this->manager->cancel($id));
    }

    public function testCancelCancelledWorkflowReturnsFalse(): void
    {
        $id = $this->insertWorkflow('cancelled');

        $this->assertFalse($this->manager->cancel($id));
    }

    public function testCancelNonexistentWorkflowReturnsFalse(): void
    {
        $this->assertFalse($this->manager->cancel(Uuid::uuid7()->toString()));
    }

    public function testCancelPendingWorkflowReturnsTrue(): void
    {
        $id = $this->insertWorkflow('pending');

        $this->assertTrue($this->manager->cancel($id));
    }

    // ---- pause ----

    public function testPauseRunningWorkflowReturnsTrue(): void
    {
        $id = $this->insertWorkflow('running');

        $this->assertTrue($this->manager->pause($id));

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('paused', $row->status);
    }

    public function testPausePendingWorkflowReturnsFalse(): void
    {
        $id = $this->insertWorkflow('pending');

        $this->assertFalse($this->manager->pause($id));
    }

    public function testPauseCompletedWorkflowReturnsFalse(): void
    {
        $id = $this->insertWorkflow('completed');

        $this->assertFalse($this->manager->pause($id));
    }

    public function testPauseNonexistentReturnsFalse(): void
    {
        $this->assertFalse($this->manager->pause(Uuid::uuid7()->toString()));
    }

    // ---- resume ----

    public function testResumeRunningWorkflowReturnsFalse(): void
    {
        $id = $this->insertWorkflow('running');

        $this->assertFalse($this->manager->resume($id));
    }

    public function testResumeNonexistentReturnsFalse(): void
    {
        $this->assertFalse($this->manager->resume(Uuid::uuid7()->toString()));
    }

    public function testResumePausedWithNoDefinitionReturnsFalse(): void
    {
        // No definition registered for 'unknown'
        $id = $this->insertWorkflow('paused', 'unknown');

        $this->assertFalse($this->manager->resume($id));
    }

    public function testResumePausedWithDefinitionReturnsTrue(): void
    {
        Bus::fake();

        $def = WorkflowDefinition::define('resume-def');
        $def->addStep('step1', 'App\\Jobs\\TestJob');
        $this->manager->register($def);

        $id = $this->insertWorkflow('paused', 'resume-def');

        $this->assertTrue($this->manager->resume($id));

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('running', $row->status);
    }

    // ---- getInstance ----

    public function testGetInstanceReturnsNullForMissingId(): void
    {
        $this->assertNull($this->manager->getInstance(Uuid::uuid7()->toString()));
    }

    public function testGetInstanceReturnsHydratedInstance(): void
    {
        $id = $this->insertWorkflow('running', 'my-wf');

        $instance = $this->manager->getInstance($id);

        $this->assertNotNull($instance);
        $this->assertSame($id, $instance->getId());
        $this->assertSame('running', $instance->getStatus());
        $this->assertSame('my-wf', $instance->getDefinitionName());
    }

    public function testGetInstanceWithConnection(): void
    {
        $id = $this->insertWorkflow('running', 'my-wf', 'redis');

        $instance = $this->manager->getInstance($id);

        $this->assertNotNull($instance);
        $this->assertSame('redis', $instance->getConnection());
    }

    // ---- status ----

    public function testStatusReturnsNullForMissingInstance(): void
    {
        $this->assertNull($this->manager->status('my-wf', Uuid::uuid7()->toString()));
    }

    public function testStatusReturnsNullForWrongDefinitionName(): void
    {
        $id = $this->insertWorkflow('running', 'correct-name');

        $this->assertNull($this->manager->status('wrong-name', $id));
    }

    public function testStatusReturnsDataForCorrectInstance(): void
    {
        $id = $this->insertWorkflow('running', 'order-flow');

        $status = $this->manager->status('order-flow', $id);

        $this->assertNotNull($status);
        $this->assertSame('running', $status['status']);
        $this->assertArrayHasKey('current_step', $status);
        $this->assertArrayHasKey('progress', $status);
        $this->assertArrayHasKey('error', $status);
    }

    // ---- getInstances ----

    public function testGetInstancesReturnsEmptyForUnknownDefinition(): void
    {
        $instances = $this->manager->getInstances('nonexistent');

        $this->assertIsArray($instances);
        $this->assertEmpty($instances);
    }

    public function testGetInstancesReturnsInstancesForDefinition(): void
    {
        $this->insertWorkflow('running', 'batch-def');
        $this->insertWorkflow('completed', 'batch-def');
        $this->insertWorkflow('running', 'other-def');

        $instances = $this->manager->getInstances('batch-def');

        $this->assertCount(2, $instances);

        foreach ($instances as $instance) {
            $this->assertSame('batch-def', $instance->getDefinitionName());
        }
    }

    public function testGetInstancesRespectsLimit(): void
    {
        $this->insertWorkflow('running', 'limited');
        $this->insertWorkflow('running', 'limited');
        $this->insertWorkflow('running', 'limited');

        $instances = $this->manager->getInstances('limited', 2);

        $this->assertCount(2, $instances);
    }

    // ---- handleAsyncStepFailure ----

    public function testHandleAsyncStepFailureForStarterStep(): void
    {
        $id = $this->insertWorkflow('running', 'async-fail');

        $this->manager->handleAsyncStepFailure($id, '_starter', 'Connection reset');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContains('Workflow starter job failed', $row->error);
    }

    public function testHandleAsyncStepFailureOnTerminalWorkflowIsNoop(): void
    {
        $id = $this->insertWorkflow('completed', 'done-wf');

        // Should not throw or change status
        $this->manager->handleAsyncStepFailure($id, 'step1', 'Some error');

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('completed', $row->status);
    }

    public function testHandleAsyncStepFailureOnNonexistentIsNoop(): void
    {
        $this->expectNotToPerformAssertions();
        $this->manager->handleAsyncStepFailure(Uuid::uuid7()->toString(), 'step1', 'Error');
    }

    // ---- recoverStuckWorkflows ----

    public function testRecoverStuckWorkflowsWithNoStuckReturnsEmpty(): void
    {
        $recovered = $this->manager->recoverStuckWorkflows();

        $this->assertIsArray($recovered);
        $this->assertEmpty($recovered);
    }

    public function testRecoverStuckWorkflowsRecoversQueuedSteps(): void
    {
        Bus::fake();

        $def = WorkflowDefinition::define('stuck-def');
        $def->addStep('step1', 'App\\Jobs\\TestJob');
        $this->manager->register($def);

        $id = $this->insertWorkflow('running', 'stuck-def', null, [
            'step1' => WorkflowStepStatus::Queued->value,
        ], now()->subSeconds(600)->toDateTimeString());

        $recovered = $this->manager->recoverStuckWorkflows(300);

        $this->assertNotEmpty($recovered);
        $this->assertSame('redispatched', $recovered[0]['action']);
        $this->assertSame('step1', $recovered[0]['step']);
    }

    public function testRecoverStuckWorkflowsTimesOutRunningSteps(): void
    {
        $def = WorkflowDefinition::define('timeout-def');
        $def->addStep('step1', 'App\\Jobs\\TestJob');
        $this->manager->register($def);

        $id = $this->insertWorkflow('running', 'timeout-def', null, [
            'step1' => WorkflowStepStatus::Running->value,
        ], now()->subSeconds(600)->toDateTimeString());

        $recovered = $this->manager->recoverStuckWorkflows(300);

        $this->assertNotEmpty($recovered);
        $this->assertSame('timed_out', $recovered[0]['action']);
    }

    // ---- run (synchronous) ----

    public function testRunWithUndefinedWorkflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->manager->run('nonexistent');
    }

    // ---- runAsync ----

    public function testRunAsyncWithUndefinedWorkflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' is not defined");

        $this->manager->runAsync('nonexistent');
    }

    public function testRunAsyncCreatesInstanceAndDispatchesJob(): void
    {
        Bus::fake();

        $def = WorkflowDefinition::define('async-wf');
        $def->addStep('step1', 'App\\Jobs\\TestJob');
        $this->manager->register($def);

        $instance = $this->manager->runAsync('async-wf', ['key' => 'value'], 'redis');

        $this->assertSame('pending', $instance->getStatus());
        $this->assertSame('redis', $instance->getConnection());

        // Verify persisted in DB
        $row = DB::table('station_workflows')->where('id', $instance->getId())->first();
        $this->assertNotNull($row);
        $this->assertSame('async-wf', $row->definition_name);
        $this->assertSame('redis', $row->connection);
    }

    // ---- define and register ----

    public function testDefineReturnsWorkflowDefinition(): void
    {
        $def = $this->manager->define('new-workflow');

        $this->assertInstanceOf(WorkflowDefinition::class, $def);
        $this->assertSame('new-workflow', $def->getName());
    }

    public function testRegisterStoresDefinition(): void
    {
        $def = WorkflowDefinition::define('registered');
        $def->addStep('step1', 'App\\Jobs\\TestJob');

        $this->manager->register($def);

        $retrieved = $this->manager->getDefinition('registered');
        $this->assertNotNull($retrieved);
        $this->assertSame('registered', $retrieved->getName());
    }

    public function testGetDefinitionReturnsNullForUnknown(): void
    {
        $this->assertNull($this->manager->getDefinition('unknown'));
    }

    public function testGetDefinitionsReturnsAllRegistered(): void
    {
        $def1 = WorkflowDefinition::define('wf1');
        $def1->addStep('s1', 'App\\Jobs\\TestJob');
        $this->manager->register($def1);

        $def2 = WorkflowDefinition::define('wf2');
        $def2->addStep('s1', 'App\\Jobs\\TestJob');
        $this->manager->register($def2);

        $defs = $this->manager->getDefinitions();

        $this->assertCount(2, $defs);
        $this->assertArrayHasKey('wf1', $defs);
        $this->assertArrayHasKey('wf2', $defs);
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

    private function insertWorkflow(
        string $status,
        string $definitionName = 'test-wf',
        ?string $connection = null,
        array $stepStatuses = [],
        ?string $updatedAt = null,
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
            'definition_steps' => '[]',
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
