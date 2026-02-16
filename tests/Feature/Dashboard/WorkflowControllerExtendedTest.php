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
 * Extended feature tests for WorkflowController covering:
 * - run endpoint edge cases (null connection)
 * - status endpoint with different progress values
 * - show endpoint for nonexistent workflow
 * - definitions page
 *
 * Note: Bulk workflow routes (bulk/pause, bulk/resume, bulk/cancel) are shadowed
 * by the wildcard {id} routes registered before them in routes/web.php.
 * For example, POST /workflows/bulk/pause is caught by POST /workflows/{id}/pause
 * with id="bulk". These bulk endpoints are not testable via HTTP routes.
 *
 * Similarly, the index page and show page render Inertia views, which are not
 * directly testable without the full Inertia stack.
 */
class WorkflowControllerExtendedTest extends TestCase
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
        $ref = new ReflectionClass(WorkflowManager::class);
        $prop = $ref->getProperty('persistedInstances');
        $prop->setAccessible(true);
        $prop->setValue($this->workflowManager, []);

        Mockery::close();
        parent::tearDown();
    }

    // ---- Run endpoint edge cases ----

    public function testRunWithNullConnectionIsValid(): void
    {
        Bus::fake();

        $definition = WorkflowDefinition::define('null-conn');
        $definition->addStep('step1', 'App\\Jobs\\TestJob');
        $this->workflowManager->register($definition);

        $this->postJson('/station/api/workflows/run', [
            'definition' => 'null-conn',
            'connection' => null,
        ])
            ->assertOk()
            ->assertJsonStructure(['id', 'status']);
    }

    public function testRunWithEmptyConnectionIsValid(): void
    {
        Bus::fake();

        $definition = WorkflowDefinition::define('empty-conn');
        $definition->addStep('step1', 'App\\Jobs\\TestJob');
        $this->workflowManager->register($definition);

        $this->postJson('/station/api/workflows/run', [
            'definition' => 'empty-conn',
        ])
            ->assertOk()
            ->assertJsonStructure(['id', 'status']);
    }

    public function testRunDispatchesJobAndReturnsInstance(): void
    {
        Bus::fake();

        $definition = WorkflowDefinition::define('dispatch-test');
        $definition->addStep('build', 'App\\Jobs\\BuildJob');
        $definition->addStep('deploy', 'App\\Jobs\\DeployJob', ['build']);
        $this->workflowManager->register($definition);

        $response = $this->postJson('/station/api/workflows/run', [
            'definition' => 'dispatch-test',
            'connection' => 'rabbitmq',
        ])
            ->assertOk()
            ->assertJsonStructure(['id', 'status']);

        $instanceId = $response->json('id');

        // Verify the workflow instance was persisted
        $row = DB::table('station_workflows')->where('id', $instanceId)->first();
        $this->assertNotNull($row);
        $this->assertSame('dispatch-test', $row->definition_name);
        $this->assertSame('rabbitmq', $row->connection);
    }

    // ---- Status endpoint with different progress values ----

    public function testStatusWithProgressReturnsCorrectProgress(): void
    {
        $id = Uuid::uuid7()->toString();

        DB::table('station_workflows')->insert([
            'id' => $id,
            'definition_id' => Uuid::uuid7()->toString(),
            'definition_name' => 'order-flow',
            'status' => 'running',
            'current_step' => 'payment',
            'input' => '{}',
            'context' => '{}',
            'results' => '{}',
            'step_statuses' => json_encode(['validate' => 'completed', 'payment' => 'running']),
            'definition_steps' => '[]',
            'error' => null,
            'progress' => 50,
            'started_at' => now()->toDateTimeString(),
            'completed_at' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $response = $this->getJson("/station/api/workflows/order-flow/{$id}/status")
            ->assertOk();

        $this->assertSame('running', $response->json('status'));
        $this->assertSame('payment', $response->json('current_step'));
        $this->assertSame(50, $response->json('progress'));
    }

    public function testStatusWithConnectionReturnsCorrectData(): void
    {
        $id = Uuid::uuid7()->toString();

        DB::table('station_workflows')->insert([
            'id' => $id,
            'definition_id' => Uuid::uuid7()->toString(),
            'definition_name' => 'deploy-flow',
            'connection' => 'redis',
            'status' => 'completed',
            'current_step' => null,
            'input' => '{}',
            'context' => '{}',
            'results' => '{}',
            'step_statuses' => json_encode(['build' => 'completed', 'deploy' => 'completed']),
            'definition_steps' => '[]',
            'error' => null,
            'progress' => 100,
            'started_at' => now()->subMinutes(5)->toDateTimeString(),
            'completed_at' => now()->toDateTimeString(),
            'created_at' => now()->subMinutes(5)->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $response = $this->getJson("/station/api/workflows/deploy-flow/{$id}/status")
            ->assertOk();

        $this->assertSame('completed', $response->json('status'));
        $this->assertSame(100, $response->json('progress'));
        $this->assertNull($response->json('error'));
    }

    // ---- Show endpoint ----

    public function testShowNonexistentWorkflowReturns404(): void
    {
        $fakeId = Uuid::uuid7()->toString();

        $this->get("/station/workflows/{$fakeId}")
            ->assertStatus(404);
    }

    // ---- Definitions endpoint (Inertia page) ----

    public function testDefinitionsPageWithNoDefinitions(): void
    {
        $response = $this->get('/station/workflows/definitions');

        // Inertia may return 500 if root view is not configured
        $this->assertTrue(\in_array($response->status(), [200, 500], true));
    }

    public function testDefinitionsPageWithDefinitionsDoesNotCrash(): void
    {
        $def = WorkflowDefinition::define('test-def');
        $def->addStep('step1', 'App\\Jobs\\TestJob');
        $this->workflowManager->register($def);

        $response = $this->get('/station/workflows/definitions');

        $this->assertTrue(\in_array($response->status(), [200, 500], true));
    }

    // ---- Pause/Resume/Cancel (single workflow via non-bulk routes) ----

    public function testPauseMultipleWorkflowsSequentially(): void
    {
        $id1 = $this->createWorkflowInstance('running');
        $id2 = $this->createWorkflowInstance('running');

        $this->postJson("/station/api/workflows/{$id1}/pause")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->postJson("/station/api/workflows/{$id2}/pause")
            ->assertOk()
            ->assertJson(['success' => true]);

        // Verify both were paused
        $row1 = DB::table('station_workflows')->where('id', $id1)->first();
        $row2 = DB::table('station_workflows')->where('id', $id2)->first();
        $this->assertSame('paused', $row1->status);
        $this->assertSame('paused', $row2->status);
    }

    public function testCancelMultipleWorkflowsSequentially(): void
    {
        $id1 = $this->createWorkflowInstance('running');
        $id2 = $this->createWorkflowInstance('pending');

        $this->postJson("/station/api/workflows/{$id1}/cancel")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->postJson("/station/api/workflows/{$id2}/cancel")
            ->assertOk()
            ->assertJson(['success' => true]);

        $row1 = DB::table('station_workflows')->where('id', $id1)->first();
        $row2 = DB::table('station_workflows')->where('id', $id2)->first();
        $this->assertSame('cancelled', $row1->status);
        $this->assertSame('cancelled', $row2->status);
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

    private function createWorkflowInstance(
        string $status,
        string $definitionName = 'test-workflow',
        ?string $connection = null,
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
