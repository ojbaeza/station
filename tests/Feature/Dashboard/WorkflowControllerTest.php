<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use Station\Dashboard\Http\Controllers\WorkflowController;
use Station\StationServiceProvider;
use Station\Workflows\WorkflowDefinition;
use Station\Workflows\WorkflowManager;

/**
 * Feature tests for WorkflowController JSON API endpoints.
 *
 * WorkflowController is a final class depending on WorkflowManager (also final).
 * We construct a real WorkflowManager with mocked Dispatcher, then bind
 * the WorkflowController into the container.
 *
 * Note: Bulk workflow routes (bulk/pause, bulk/resume, bulk/cancel) are shadowed
 * by the wildcard {id} routes registered before them in routes/web.php.
 * For example, POST /workflows/bulk/pause is caught by POST /workflows/{id}/pause
 * with id="bulk". These bulk endpoints are therefore not testable via HTTP routes.
 */
class WorkflowControllerTest extends TestCase
{
    private WorkflowManager $workflowManager;

    private Dispatcher&MockInterface $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->events = Mockery::mock(Dispatcher::class);
        $this->events->shouldReceive('dispatch')->byDefault();

        $this->workflowManager = new WorkflowManager(
            $this->events,
            ['table' => 'station_workflows'],
        );

        $controller = new WorkflowController($this->workflowManager);
        $this->app->instance(WorkflowController::class, $controller);
    }

    protected function tearDown(): void
    {
        // Clear persisted instance tracking (static property)
        $ref = new ReflectionClass(WorkflowManager::class);
        $prop = $ref->getProperty('persistedInstances');
        $prop->setValue($this->workflowManager, []);

        Mockery::close();
        parent::tearDown();
    }

    // ---- Pause endpoint ----

    public function testPauseRunningWorkflowReturnsSuccess(): void
    {
        $id = $this->createWorkflowInstance('running');

        $this->postJson("/station/api/workflows/{$id}/pause")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Workflow paused']);
    }

    public function testPausePersistsStatusChange(): void
    {
        $id = $this->createWorkflowInstance('running');

        $this->postJson("/station/api/workflows/{$id}/pause")
            ->assertOk();

        // Verify in DB
        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('paused', $row->status);
    }

    public function testPauseNonRunningWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('pending');

        $this->postJson("/station/api/workflows/{$id}/pause")
            ->assertOk()
            ->assertJson(['success' => false, 'message' => 'Unable to pause workflow']);
    }

    public function testPauseNonexistentWorkflowReturnsFalse(): void
    {
        $fakeId = Uuid::uuid7()->toString();

        $this->postJson("/station/api/workflows/{$fakeId}/pause")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testPauseCompletedWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('completed');

        $this->postJson("/station/api/workflows/{$id}/pause")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testPausePausedWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('paused');

        $this->postJson("/station/api/workflows/{$id}/pause")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testPauseFailedWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('failed');

        $this->postJson("/station/api/workflows/{$id}/pause")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testPauseCancelledWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('cancelled');

        $this->postJson("/station/api/workflows/{$id}/pause")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    // ---- Resume endpoint ----

    public function testResumeReturnsFailureWhenNotPaused(): void
    {
        $id = $this->createWorkflowInstance('running');

        $this->postJson("/station/api/workflows/{$id}/resume")
            ->assertOk()
            ->assertJson(['success' => false, 'message' => 'Unable to resume workflow']);
    }

    public function testResumeNonexistentWorkflowReturnsFalse(): void
    {
        $fakeId = Uuid::uuid7()->toString();

        $this->postJson("/station/api/workflows/{$fakeId}/resume")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testResumePausedWorkflowWithDefinitionReturnsSuccess(): void
    {
        Bus::fake();

        // Register a definition so resume can look it up
        $definition = WorkflowDefinition::define('test-def');
        $definition->addStep('step1', 'App\\Jobs\\TestJob');
        $this->workflowManager->register($definition);

        $id = $this->createWorkflowInstance('paused', 'test-def');

        $this->postJson("/station/api/workflows/{$id}/resume")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Workflow resumed']);
    }

    public function testResumePausedWorkflowPersistsStatusChange(): void
    {
        Bus::fake();

        $definition = WorkflowDefinition::define('test-def-persist');
        $definition->addStep('step1', 'App\\Jobs\\TestJob');
        $this->workflowManager->register($definition);

        $id = $this->createWorkflowInstance('paused', 'test-def-persist');

        $this->postJson("/station/api/workflows/{$id}/resume")
            ->assertOk();

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('running', $row->status);
    }

    public function testResumePausedWorkflowWithoutDefinitionReturnsFalse(): void
    {
        // No definition registered for 'unknown-def'
        $id = $this->createWorkflowInstance('paused', 'unknown-def');

        $this->postJson("/station/api/workflows/{$id}/resume")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testResumeCompletedWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('completed');

        $this->postJson("/station/api/workflows/{$id}/resume")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    // ---- Cancel endpoint ----

    public function testCancelRunningWorkflowReturnsSuccess(): void
    {
        $id = $this->createWorkflowInstance('running');

        $this->postJson("/station/api/workflows/{$id}/cancel")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Workflow cancelled']);
    }

    public function testCancelPersistsStatusChange(): void
    {
        $id = $this->createWorkflowInstance('running');

        $this->postJson("/station/api/workflows/{$id}/cancel")
            ->assertOk();

        $row = DB::table('station_workflows')->where('id', $id)->first();
        $this->assertSame('cancelled', $row->status);
    }

    public function testCancelPendingWorkflowReturnsSuccess(): void
    {
        $id = $this->createWorkflowInstance('pending');

        $this->postJson("/station/api/workflows/{$id}/cancel")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Workflow cancelled']);
    }

    public function testCancelPausedWorkflowReturnsSuccess(): void
    {
        $id = $this->createWorkflowInstance('paused');

        $this->postJson("/station/api/workflows/{$id}/cancel")
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Workflow cancelled']);
    }

    public function testCancelCompletedWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('completed');

        $this->postJson("/station/api/workflows/{$id}/cancel")
            ->assertOk()
            ->assertJson(['success' => false, 'message' => 'Unable to cancel workflow']);
    }

    public function testCancelFailedWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('failed');

        $this->postJson("/station/api/workflows/{$id}/cancel")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testCancelCancelledWorkflowReturnsFalse(): void
    {
        $id = $this->createWorkflowInstance('cancelled');

        $this->postJson("/station/api/workflows/{$id}/cancel")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    public function testCancelNonexistentWorkflowReturnsFalse(): void
    {
        $fakeId = Uuid::uuid7()->toString();

        $this->postJson("/station/api/workflows/{$fakeId}/cancel")
            ->assertOk()
            ->assertJson(['success' => false]);
    }

    // ---- Status endpoint ----

    public function testStatusReturnsWorkflowStatusData(): void
    {
        $id = $this->createWorkflowInstance('running', 'order-flow');

        $this->getJson("/station/api/workflows/order-flow/{$id}/status")
            ->assertOk()
            ->assertJsonStructure(['status', 'current_step', 'progress', 'error']);
    }

    public function testStatusReturnsCorrectValues(): void
    {
        $id = $this->createWorkflowInstance('running', 'order-flow');

        $response = $this->getJson("/station/api/workflows/order-flow/{$id}/status")
            ->assertOk();

        $data = $response->json();
        $this->assertSame('running', $data['status']);
        $this->assertNull($data['current_step']);
        $this->assertSame(0, $data['progress']);
        $this->assertNull($data['error']);
    }

    public function testStatusReturns404WhenNotFound(): void
    {
        $fakeId = Uuid::uuid7()->toString();

        $this->getJson("/station/api/workflows/order-flow/{$fakeId}/status")
            ->assertStatus(404)
            ->assertJson(['error' => 'Workflow instance not found']);
    }

    public function testStatusReturns404WhenDefinitionNameMismatch(): void
    {
        $id = $this->createWorkflowInstance('running', 'order-flow');

        // Request with wrong definition name
        $this->getJson("/station/api/workflows/wrong-name/{$id}/status")
            ->assertStatus(404)
            ->assertJson(['error' => 'Workflow instance not found']);
    }

    public function testStatusForCompletedWorkflow(): void
    {
        $id = $this->createWorkflowInstance('completed', 'order-flow');

        $response = $this->getJson("/station/api/workflows/order-flow/{$id}/status")
            ->assertOk();

        $this->assertSame('completed', $response->json('status'));
    }

    public function testStatusForFailedWorkflowIncludesError(): void
    {
        $id = Uuid::uuid7()->toString();

        // Two steps: one completed, one failed -> 50% progress
        $stepStatuses = json_encode([
            'validate' => 'completed',
            'payment' => 'failed',
        ]);

        DB::table('station_workflows')->insert([
            'id' => $id,
            'definition_id' => Uuid::uuid7()->toString(),
            'definition_name' => 'order-flow',
            'status' => 'failed',
            'current_step' => null,
            'input' => '{}',
            'context' => '{}',
            'results' => '{}',
            'step_statuses' => $stepStatuses,
            'definition_steps' => '[]',
            'error' => 'Step payment failed: timeout',
            'progress' => 50,
            'started_at' => now()->toDateTimeString(),
            'completed_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $response = $this->getJson("/station/api/workflows/order-flow/{$id}/status")
            ->assertOk();

        $this->assertSame('failed', $response->json('status'));
        $this->assertSame('Step payment failed: timeout', $response->json('error'));
        // Progress is re-calculated from step_statuses: 1 completed out of 2 = 50%
        $this->assertSame(50, $response->json('progress'));
    }

    // ---- Run endpoint ----

    public function testRunReturns404WhenDefinitionNotFound(): void
    {
        $this->postJson('/station/api/workflows/run', [
            'definition' => 'nonexistent',
        ])
            ->assertStatus(404)
            ->assertJson(['error' => 'Definition not found']);
    }

    public function testRunValidatesRequiredFields(): void
    {
        $this->postJson('/station/api/workflows/run', [])
            ->assertStatus(422);
    }

    public function testRunValidatesDefinitionIsString(): void
    {
        $this->postJson('/station/api/workflows/run', [
            'definition' => 123,
        ])
            ->assertStatus(422);
    }

    public function testRunValidatesConnectionMaxLength(): void
    {
        $this->postJson('/station/api/workflows/run', [
            'definition' => 'test',
            'connection' => str_repeat('a', 101),
        ])
            ->assertStatus(422);
    }

    public function testRunWithValidDefinitionReturnsIdAndStatus(): void
    {
        Bus::fake();

        $definition = WorkflowDefinition::define('deployable');
        $definition->addStep('build', 'App\\Jobs\\BuildJob');
        $this->workflowManager->register($definition);

        $response = $this->postJson('/station/api/workflows/run', [
            'definition' => 'deployable',
        ])
            ->assertOk()
            ->assertJsonStructure(['id', 'status']);

        $this->assertNotEmpty($response->json('id'));
        $this->assertSame('pending', $response->json('status'));
    }

    public function testRunWithConnectionReturnsIdAndStatus(): void
    {
        Bus::fake();

        $definition = WorkflowDefinition::define('deploy-redis');
        $definition->addStep('build', 'App\\Jobs\\BuildJob');
        $this->workflowManager->register($definition);

        $response = $this->postJson('/station/api/workflows/run', [
            'definition' => 'deploy-redis',
            'connection' => 'redis',
        ])
            ->assertOk()
            ->assertJsonStructure(['id', 'status']);

        $this->assertNotEmpty($response->json('id'));
    }

    public function testRunPersistsWorkflowInstanceInDatabase(): void
    {
        Bus::fake();

        $definition = WorkflowDefinition::define('persist-test');
        $definition->addStep('step1', 'App\\Jobs\\TestJob');
        $this->workflowManager->register($definition);

        $response = $this->postJson('/station/api/workflows/run', [
            'definition' => 'persist-test',
        ])
            ->assertOk();

        $instanceId = $response->json('id');

        $row = DB::table('station_workflows')->where('id', $instanceId)->first();
        $this->assertNotNull($row);
        $this->assertSame('persist-test', $row->definition_name);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.api.auth', 'none');
        $app['config']->set('station.api.middleware', []);
        $app['config']->set('station.default', 'redis');
        $app['config']->set('queue.connections', []);
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

    /**
     * Insert a workflow instance directly into the database and return its ID.
     */
    private function createWorkflowInstance(
        string $status,
        string $definitionName = 'test-workflow',
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
            'step_statuses' => '{}',
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
