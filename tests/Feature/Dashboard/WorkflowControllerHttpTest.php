<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Inertia\ServiceProvider;
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
 * HTTP feature tests for WorkflowController focusing on the Inertia page endpoints:
 * - index() with pagination, filtering, and stats aggregation
 * - show() with workflow detail rendering
 * - definitions() page
 * - Bulk action endpoints (bulkPause, bulkResume, bulkCancel) tested via direct
 *   controller invocation since the HTTP routes are shadowed by the {id} wildcard.
 *
 * These tests seed the station_workflows table directly and make real HTTP requests
 * to exercise the full controller logic including DB queries, pagination link building,
 * and Inertia prop assembly.
 */
class WorkflowControllerHttpTest extends TestCase
{
    private WorkflowManager $workflowManager;

    private Dispatcher&MockInterface $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->registerMinimalBladeView();

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

    // =========================================================================
    // index() - Empty database
    // =========================================================================

    public function testIndexWithEmptyDatabaseReturnsOkStatus(): void
    {
        $this->get('/station/workflows')
            ->assertOk();
    }

    public function testIndexWithEmptyDatabaseRendersWorkflowsComponent(): void
    {
        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(static fn($page) => $page->component('Station/Workflows'));
    }

    public function testIndexWithEmptyDatabaseReturnsZeroStats(): void
    {
        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('stats.pending', 0)
                ->where('stats.running', 0)
                ->where('stats.paused', 0)
                ->where('stats.completed', 0)
                ->where('stats.failed', 0)
                ->where('stats.cancelled', 0),
            );
    }

    public function testIndexWithEmptyDatabaseReturnsEmptyInstancesData(): void
    {
        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data', [])
                ->where('instances.total', 0)
                ->where('instances.page', 1)
                ->where('instances.per_page', 25)
                ->where('instances.last_page', 1)
                ->where('instances.from', null)
                ->where('instances.to', null),
            );
    }

    public function testIndexWithEmptyDatabaseReturnsEmptyConnections(): void
    {
        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('connections', []),
            );
    }

    public function testIndexWithEmptyDatabaseReturnsNullFilters(): void
    {
        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('filters.status', null)
                ->where('filters.definition', null)
                ->where('filters.connection', null),
            );
    }

    // =========================================================================
    // index() - Seeded data and stats aggregation
    // =========================================================================

    public function testIndexReturnsCorrectStatsAcrossAllStatuses(): void
    {
        $this->seedWorkflows([
            ['status' => 'pending'],
            ['status' => 'pending'],
            ['status' => 'running'],
            ['status' => 'running'],
            ['status' => 'running'],
            ['status' => 'paused'],
            ['status' => 'completed'],
            ['status' => 'completed'],
            ['status' => 'completed'],
            ['status' => 'completed'],
            ['status' => 'failed'],
            ['status' => 'cancelled'],
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('stats.pending', 2)
                ->where('stats.running', 3)
                ->where('stats.paused', 1)
                ->where('stats.completed', 4)
                ->where('stats.failed', 1)
                ->where('stats.cancelled', 1),
            );
    }

    public function testIndexStatsAreUnfilteredEvenWhenStatusFilterApplied(): void
    {
        $this->seedWorkflows([
            ['status' => 'pending'],
            ['status' => 'running'],
            ['status' => 'completed'],
        ]);

        // Filter by status=pending, but stats should still show all statuses
        $this->get('/station/workflows?status=pending')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('stats.pending', 1)
                ->where('stats.running', 1)
                ->where('stats.completed', 1),
            );
    }

    public function testIndexReturnsDistinctConnectionsForFilterDropdown(): void
    {
        $this->seedWorkflows([
            ['connection' => 'redis'],
            ['connection' => 'rabbitmq'],
            ['connection' => 'redis'],
            ['connection' => 'sqs'],
            ['connection' => null],
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                // Connections are sorted alphabetically
                ->where('connections', ['rabbitmq', 'redis', 'sqs']),
            );
    }

    public function testIndexReturnsInstanceDataWithCorrectShape(): void
    {
        $id = $this->insertWorkflow([
            'status' => 'running',
            'definition_name' => 'order-flow',
            'connection' => 'redis',
            'current_step' => 'validate',
            'progress' => 50,
            'started_at' => '2026-01-15 10:00:00',
            'completed_at' => null,
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 1)
                ->where('instances.data.0.id', $id)
                ->where('instances.data.0.definition_name', 'order-flow')
                ->where('instances.data.0.connection', 'redis')
                ->where('instances.data.0.status', 'running')
                ->where('instances.data.0.current_step', 'validate')
                ->where('instances.data.0.progress', 50)
                ->where('instances.data.0.started_at', '2026-01-15 10:00:00')
                ->where('instances.data.0.completed_at', null),
            );
    }

    public function testIndexInstancesIncludeDefinitionIdField(): void
    {
        $defId = Uuid::uuid7()->toString();
        $this->insertWorkflow([
            'definition_id' => $defId,
            'status' => 'running',
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data.0.definition_id', $defId),
            );
    }

    public function testIndexInstancesIncludeDurationForCompletedWorkflow(): void
    {
        $this->insertWorkflow([
            'status' => 'completed',
            'started_at' => '2026-01-15 10:00:00',
            'completed_at' => '2026-01-15 10:05:00',
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data.0.duration', 300), // 5 minutes = 300 seconds
            );
    }

    public function testIndexInstancesIncludeNullDurationWhenNotStarted(): void
    {
        $this->insertWorkflow([
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data.0.duration', null),
            );
    }

    public function testIndexOrdersInstancesByCreatedAtDescending(): void
    {
        $oldId = $this->insertWorkflow([
            'created_at' => '2026-01-10 08:00:00',
            'definition_name' => 'old-workflow',
        ]);
        $newId = $this->insertWorkflow([
            'created_at' => '2026-01-15 12:00:00',
            'definition_name' => 'new-workflow',
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data.0.id', $newId)
                ->where('instances.data.1.id', $oldId),
            );
    }

    // =========================================================================
    // index() - Status filtering
    // =========================================================================

    public function testIndexFiltersByStatusPending(): void
    {
        $this->seedWorkflows([
            ['status' => 'pending', 'definition_name' => 'wf-pending'],
            ['status' => 'running', 'definition_name' => 'wf-running'],
            ['status' => 'completed', 'definition_name' => 'wf-completed'],
        ]);

        $this->get('/station/workflows?status=pending')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 1)
                ->where('instances.data.0.status', 'pending')
                ->where('instances.total', 1)
                ->where('filters.status', 'pending'),
            );
    }

    public function testIndexFiltersByStatusCompleted(): void
    {
        $this->seedWorkflows([
            ['status' => 'pending'],
            ['status' => 'completed'],
            ['status' => 'completed'],
        ]);

        $this->get('/station/workflows?status=completed')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 2)
                ->where('instances.total', 2)
                ->where('filters.status', 'completed'),
            );
    }

    public function testIndexFiltersByStatusFailed(): void
    {
        $this->seedWorkflows([
            ['status' => 'failed'],
            ['status' => 'running'],
        ]);

        $this->get('/station/workflows?status=failed')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 1)
                ->where('instances.data.0.status', 'failed')
                ->where('filters.status', 'failed'),
            );
    }

    public function testIndexFiltersByStatusWithNoMatchReturnsEmptyData(): void
    {
        $this->seedWorkflows([
            ['status' => 'running'],
        ]);

        $this->get('/station/workflows?status=cancelled')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data', [])
                ->where('instances.total', 0)
                ->where('filters.status', 'cancelled'),
            );
    }

    // =========================================================================
    // index() - Definition filtering
    // =========================================================================

    public function testIndexFiltersByDefinitionName(): void
    {
        $this->seedWorkflows([
            ['definition_name' => 'order-flow'],
            ['definition_name' => 'deploy-flow'],
            ['definition_name' => 'order-flow'],
        ]);

        $this->get('/station/workflows?definition=order-flow')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 2)
                ->where('instances.total', 2)
                ->where('filters.definition', 'order-flow'),
            );
    }

    public function testIndexFiltersByDefinitionWithNoMatchReturnsEmpty(): void
    {
        $this->seedWorkflows([
            ['definition_name' => 'order-flow'],
        ]);

        $this->get('/station/workflows?definition=nonexistent')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data', [])
                ->where('instances.total', 0),
            );
    }

    // =========================================================================
    // index() - Connection filtering
    // =========================================================================

    public function testIndexFiltersByConnection(): void
    {
        $this->seedWorkflows([
            ['connection' => 'redis'],
            ['connection' => 'rabbitmq'],
            ['connection' => 'redis'],
        ]);

        $this->get('/station/workflows?connection=redis')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 2)
                ->where('instances.total', 2)
                ->where('filters.connection', 'redis'),
            );
    }

    // =========================================================================
    // index() - Combined filters
    // =========================================================================

    public function testIndexCombinesStatusAndDefinitionFilters(): void
    {
        $this->seedWorkflows([
            ['status' => 'running', 'definition_name' => 'order-flow'],
            ['status' => 'completed', 'definition_name' => 'order-flow'],
            ['status' => 'running', 'definition_name' => 'deploy-flow'],
        ]);

        $this->get('/station/workflows?status=running&definition=order-flow')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 1)
                ->where('instances.total', 1)
                ->where('instances.data.0.status', 'running')
                ->where('instances.data.0.definition_name', 'order-flow')
                ->where('filters.status', 'running')
                ->where('filters.definition', 'order-flow'),
            );
    }

    public function testIndexCombinesAllThreeFilters(): void
    {
        $this->seedWorkflows([
            ['status' => 'running', 'definition_name' => 'order-flow', 'connection' => 'redis'],
            ['status' => 'running', 'definition_name' => 'order-flow', 'connection' => 'rabbitmq'],
            ['status' => 'completed', 'definition_name' => 'order-flow', 'connection' => 'redis'],
            ['status' => 'running', 'definition_name' => 'deploy-flow', 'connection' => 'redis'],
        ]);

        $this->get('/station/workflows?status=running&definition=order-flow&connection=redis')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 1)
                ->where('instances.total', 1)
                ->where('filters.status', 'running')
                ->where('filters.definition', 'order-flow')
                ->where('filters.connection', 'redis'),
            );
    }

    // =========================================================================
    // index() - Pagination
    // =========================================================================

    public function testIndexDefaultPaginationIs25PerPage(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->insertWorkflow([
                'created_at' => \sprintf('2026-01-15 %02d:00:00', $i % 24),
            ]);
        }

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 25)
                ->where('instances.total', 30)
                ->where('instances.page', 1)
                ->where('instances.per_page', 25)
                ->where('instances.last_page', 2)
                ->where('instances.from', 1)
                ->where('instances.to', 25),
            );
    }

    public function testIndexSecondPageShowsRemainingItems(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?page=2')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 5)
                ->where('instances.total', 30)
                ->where('instances.page', 2)
                ->where('instances.from', 26)
                ->where('instances.to', 30),
            );
    }

    public function testIndexCustomPerPageLimitsResults(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=5')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 5)
                ->where('instances.total', 15)
                ->where('instances.per_page', 5)
                ->where('instances.last_page', 3)
                ->where('instances.from', 1)
                ->where('instances.to', 5),
            );
    }

    public function testIndexPerPageIsCappedAt100(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=200')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.per_page', 100),
            );
    }

    public function testIndexPerPageMinimumIs1(): void
    {
        $this->insertWorkflow([]);

        $this->get('/station/workflows?per_page=0')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.per_page', 1),
            );
    }

    public function testIndexPerPageNegativeValueBecomes1(): void
    {
        $this->insertWorkflow([]);

        $this->get('/station/workflows?per_page=-5')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.per_page', 1),
            );
    }

    public function testIndexPageMinimumIs1(): void
    {
        $this->insertWorkflow([]);

        $this->get('/station/workflows?page=0')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.page', 1),
            );
    }

    public function testIndexPageNegativeValueBecomes1(): void
    {
        $this->insertWorkflow([]);

        $this->get('/station/workflows?page=-3')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.page', 1),
            );
    }

    // =========================================================================
    // index() - Pagination links structure
    // =========================================================================

    public function testIndexPaginationLinksIncludePreviousAndNext(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=3&page=2')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.links')
                ->where('instances.links.0.label', '&laquo; Previous')
                ->etc(),
            );
    }

    public function testIndexFirstPageHasNullPreviousUrl(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=2')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.prev_page_url', null),
            );
    }

    public function testIndexLastPageHasNullNextUrl(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=2&page=3')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.next_page_url', null),
            );
    }

    public function testIndexMiddlePageHasBothPrevAndNextUrls(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=3&page=2')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->etc();
                $props = $page->toArray()['props'];
                $this->assertNotNull($props['instances']['prev_page_url']);
                $this->assertNotNull($props['instances']['next_page_url']);
            });
    }

    public function testIndexPaginationLinksPreserveFilterParams(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insertWorkflow(['status' => 'running']);
        }

        $this->get('/station/workflows?status=running&per_page=3&page=2')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->etc();
                $props = $page->toArray()['props'];
                $nextUrl = $props['instances']['next_page_url'];
                $prevUrl = $props['instances']['prev_page_url'];

                $this->assertStringContainsString('status=running', $nextUrl);
                $this->assertStringContainsString('status=running', $prevUrl);
            });
    }

    // =========================================================================
    // index() - Pagination with filters
    // =========================================================================

    public function testIndexPaginationWithStatusFilter(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insertWorkflow(['status' => 'running']);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->insertWorkflow(['status' => 'pending']);
        }

        $this->get('/station/workflows?status=running&per_page=3')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 3)
                ->where('instances.total', 10)
                ->where('instances.last_page', 4), // ceil(10/3) = 4
            );
    }

    // =========================================================================
    // index() - Pagination edge: beyond last page
    // =========================================================================

    public function testIndexBeyondLastPageReturnsEmptyData(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?page=100')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.data', [])
                ->where('instances.total', 3)
                ->where('instances.page', 100),
            );
    }

    // =========================================================================
    // index() - Pagination ellipsis links
    // =========================================================================

    public function testIndexPaginationLinksContainEllipsisForManyPages(): void
    {
        // 100 rows at 5 per page = 20 pages
        for ($i = 0; $i < 100; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=5&page=10')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->etc();
                $props = $page->toArray()['props'];
                $labels = array_column($props['instances']['links'], 'label');

                // Should contain ellipsis markers
                $this->assertContains('...', $labels);

                // Should contain page 1 (start) and page 20 (end)
                $this->assertContains('1', $labels);
                $this->assertContains('20', $labels);

                // Current page (10) should be active
                $activeLinks = array_filter(
                    $props['instances']['links'],
                    static fn($link) => $link['active'] === true,
                );
                $activeLabels = array_column($activeLinks, 'label');
                $this->assertContains('10', $activeLabels);
            });
    }

    public function testIndexPaginationLinksDoNotContainEllipsisForFewPages(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=5&page=1')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->etc();
                $props = $page->toArray()['props'];
                $labels = array_column($props['instances']['links'], 'label');

                // Only 2 pages, no ellipsis needed
                $this->assertNotContains('...', $labels);
            });
    }

    // =========================================================================
    // index() - Single page (no next/prev)
    // =========================================================================

    public function testIndexSinglePageHasNoNextOrPrevUrls(): void
    {
        $this->insertWorkflow([]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.prev_page_url', null)
                ->where('instances.next_page_url', null)
                ->where('instances.last_page', 1),
            );
    }

    // =========================================================================
    // index() - Pagination with per_page=1 edge case
    // =========================================================================

    public function testIndexWithPerPageOneShowsOneItemPerPage(): void
    {
        $this->insertWorkflow(['definition_name' => 'wf-a']);
        $this->insertWorkflow(['definition_name' => 'wf-b']);
        $this->insertWorkflow(['definition_name' => 'wf-c']);

        $this->get('/station/workflows?per_page=1')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('instances.data', 1)
                ->where('instances.total', 3)
                ->where('instances.per_page', 1)
                ->where('instances.last_page', 3),
            );
    }

    // =========================================================================
    // index() - Connection filter with null connections excluded
    // =========================================================================

    public function testIndexConnectionsListExcludesNullValues(): void
    {
        $this->seedWorkflows([
            ['connection' => null],
            ['connection' => null],
            ['connection' => 'redis'],
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('connections', ['redis']),
            );
    }

    // =========================================================================
    // index() - Stats handle missing statuses
    // =========================================================================

    public function testIndexStatsHandleMissingStatusesGracefully(): void
    {
        // Only seed "running" workflows - other statuses should be 0
        $this->seedWorkflows([
            ['status' => 'running'],
            ['status' => 'running'],
        ]);

        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('stats.pending', 0)
                ->where('stats.running', 2)
                ->where('stats.paused', 0)
                ->where('stats.completed', 0)
                ->where('stats.failed', 0)
                ->where('stats.cancelled', 0),
            );
    }

    // =========================================================================
    // index() - Pagination from/to correctness
    // =========================================================================

    public function testIndexFromAndToAreCorrectOnLastPartialPage(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->insertWorkflow([]);
        }

        $this->get('/station/workflows?per_page=5&page=2')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.from', 6)
                ->where('instances.to', 7)
                ->has('instances.data', 2),
            );
    }

    public function testIndexFromAndToAreNullWhenNoResults(): void
    {
        $this->get('/station/workflows')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instances.from', null)
                ->where('instances.to', null),
            );
    }

    // =========================================================================
    // show() - Workflow detail
    // =========================================================================

    public function testShowExistingWorkflowReturnsOk(): void
    {
        $id = $this->insertWorkflow([
            'status' => 'running',
            'definition_name' => 'order-flow',
        ]);

        $this->get("/station/workflows/{$id}")
            ->assertOk();
    }

    public function testShowRendersWorkflowDetailComponent(): void
    {
        $id = $this->insertWorkflow([
            'status' => 'running',
            'definition_name' => 'order-flow',
        ]);

        $this->get("/station/workflows/{$id}")
            ->assertOk()
            ->assertInertia(static fn($page) => $page->component('Station/WorkflowDetail'));
    }

    public function testShowReturnsInstanceDataInProps(): void
    {
        $id = $this->insertWorkflow([
            'status' => 'completed',
            'definition_name' => 'deploy-flow',
            'connection' => 'redis',
        ]);

        $this->get("/station/workflows/{$id}")
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('instance.id', $id)
                ->where('instance.definition_name', 'deploy-flow')
                ->where('instance.status', 'completed')
                ->where('instance.connection', 'redis'),
            );
    }

    public function testShowReturnsSnapshotDefinitionStepsWhenPresent(): void
    {
        $steps = [
            ['name' => 'validate', 'job_class' => 'App\\Jobs\\ValidateJob', 'dependencies' => []],
            ['name' => 'process', 'job_class' => 'App\\Jobs\\ProcessJob', 'dependencies' => ['validate']],
        ];

        $id = $this->insertWorkflow([
            'status' => 'completed',
            'definition_name' => 'order-flow',
            'definition_steps' => json_encode($steps),
        ]);

        $this->get("/station/workflows/{$id}")
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('definitionSteps', 2)
                ->where('definitionSteps.0.name', 'validate')
                ->where('definitionSteps.1.name', 'process'),
            );
    }

    public function testShowFallsBackToCurrentDefinitionStepsWhenSnapshotEmpty(): void
    {
        $def = WorkflowDefinition::define('fallback-flow');
        $def->addStep('step1', 'App\\Jobs\\StepOneJob');
        $def->addStep('step2', 'App\\Jobs\\StepTwoJob', ['step1']);
        $this->workflowManager->register($def);

        $id = $this->insertWorkflow([
            'status' => 'running',
            'definition_name' => 'fallback-flow',
            'definition_steps' => '[]',
        ]);

        $this->get("/station/workflows/{$id}")
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('definitionSteps', 2),
            );
    }

    public function testShowReturnsEmptyStepsWhenNoSnapshotAndNoDefinition(): void
    {
        $id = $this->insertWorkflow([
            'status' => 'running',
            'definition_name' => 'unregistered-flow',
            'definition_steps' => '[]',
        ]);

        $this->get("/station/workflows/{$id}")
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('definitionSteps', []),
            );
    }

    public function testShowNonexistentWorkflowReturns404(): void
    {
        $fakeId = Uuid::uuid7()->toString();

        $this->get("/station/workflows/{$fakeId}")
            ->assertNotFound();
    }

    // =========================================================================
    // Bulk pause - tested via direct controller invocation
    //
    // Note: The HTTP routes for bulk/pause, bulk/resume, bulk/cancel are shadowed
    // by the {id} wildcard routes in routes/web.php, so we test the controller
    // methods directly with a Request object.
    // =========================================================================

    public function testBulkPausePausesMultipleRunningWorkflows(): void
    {
        $id1 = $this->insertWorkflow(['status' => 'running']);
        $id2 = $this->insertWorkflow(['status' => 'running']);

        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/station/api/workflows/bulk/pause', 'POST', [
            'ids' => [$id1, $id2],
        ]);
        $request->setMethod('POST');

        $response = $controller->bulkPause($request);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['processed']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame([], $data['errors']);

        // Verify in DB
        $row1 = DB::table('station_workflows')->where('id', $id1)->first();
        $row2 = DB::table('station_workflows')->where('id', $id2)->first();
        $this->assertSame('paused', $row1->status);
        $this->assertSame('paused', $row2->status);
    }

    public function testBulkPauseWithNonRunningWorkflowsStillProcessesWithoutErrors(): void
    {
        $runningId = $this->insertWorkflow(['status' => 'running']);
        $completedId = $this->insertWorkflow(['status' => 'completed']);

        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', [
            'ids' => [$runningId, $completedId],
        ]);

        $response = $controller->bulkPause($request);
        $data = $response->getData(true);

        // pause() returns false for completed but does not throw, so both are "processed"
        $this->assertSame(2, $data['processed']);
        $this->assertSame(0, $data['failed']);
    }

    public function testBulkPauseWithEmptyIdsValidatesInController(): void
    {
        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', ['ids' => []]);

        // The validation rule 'required|array|max:100' - empty array fails 'required'
        $this->expectException(ValidationException::class);
        $controller->bulkPause($request);
    }

    // =========================================================================
    // Bulk resume - direct controller invocation
    // =========================================================================

    public function testBulkResumeWithNonPausedWorkflowsProcessesWithoutErrors(): void
    {
        $runningId = $this->insertWorkflow(['status' => 'running']);

        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', [
            'ids' => [$runningId],
        ]);

        $response = $controller->bulkResume($request);
        $data = $response->getData(true);

        // resume() returns false for non-paused but does not throw
        $this->assertSame(1, $data['processed']);
        $this->assertSame(0, $data['failed']);
    }

    public function testBulkResumeValidatesIdsRequired(): void
    {
        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', []);

        $this->expectException(ValidationException::class);
        $controller->bulkResume($request);
    }

    // =========================================================================
    // Bulk cancel - direct controller invocation
    // =========================================================================

    public function testBulkCancelCancelsMultipleWorkflows(): void
    {
        $id1 = $this->insertWorkflow(['status' => 'running']);
        $id2 = $this->insertWorkflow(['status' => 'pending']);

        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', [
            'ids' => [$id1, $id2],
        ]);

        $response = $controller->bulkCancel($request);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['processed']);
        $this->assertSame(0, $data['failed']);

        $row1 = DB::table('station_workflows')->where('id', $id1)->first();
        $row2 = DB::table('station_workflows')->where('id', $id2)->first();
        $this->assertSame('cancelled', $row1->status);
        $this->assertSame('cancelled', $row2->status);
    }

    public function testBulkCancelWithAlreadyTerminalWorkflowsProcessesWithoutErrors(): void
    {
        $completedId = $this->insertWorkflow(['status' => 'completed']);
        $failedId = $this->insertWorkflow(['status' => 'failed']);

        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', [
            'ids' => [$completedId, $failedId],
        ]);

        $response = $controller->bulkCancel($request);
        $data = $response->getData(true);

        // cancel() returns false for terminal workflows but does not throw
        $this->assertSame(2, $data['processed']);
        $this->assertSame(0, $data['failed']);
    }

    public function testBulkCancelValidatesIdsRequired(): void
    {
        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', []);

        $this->expectException(ValidationException::class);
        $controller->bulkCancel($request);
    }

    public function testBulkCancelValidatesIdsAreArray(): void
    {
        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', ['ids' => 'not-an-array']);

        $this->expectException(ValidationException::class);
        $controller->bulkCancel($request);
    }

    public function testBulkCancelValidatesMaximum100Ids(): void
    {
        $ids = array_map(static fn() => Uuid::uuid7()->toString(), range(1, 101));

        $controller = $this->app->make(WorkflowController::class);
        $request = Request::create('/test', 'POST', ['ids' => $ids]);

        $this->expectException(ValidationException::class);
        $controller->bulkCancel($request);
    }

    // =========================================================================
    // definitions() page
    // =========================================================================

    public function testDefinitionsRendersWorkflowDefinitionsComponent(): void
    {
        $this->get('/station/workflows/definitions')
            ->assertOk()
            ->assertInertia(static fn($page) => $page->component('Station/WorkflowDefinitions'));
    }

    public function testDefinitionsWithNoRegisteredDefinitionsReturnsEmptyArray(): void
    {
        $this->get('/station/workflows/definitions')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->where('definitions', []),
            );
    }

    public function testDefinitionsWithRegisteredDefinitionsReturnsTheirData(): void
    {
        $def = WorkflowDefinition::define('deploy-workflow');
        $def->description('Deploys the application');
        $def->addStep('build', 'App\\Jobs\\BuildJob');
        $def->addStep('deploy', 'App\\Jobs\\DeployJob', ['build']);
        $this->workflowManager->register($def);

        $this->get('/station/workflows/definitions')
            ->assertOk()
            ->assertInertia(
                static fn($page) => $page
                ->has('definitions', 1)
                ->where('definitions.0.name', 'deploy-workflow')
                ->where('definitions.0.description', 'Deploys the application')
                ->has('definitions.0.steps'),
            );
    }

    // =========================================================================
    // Environment and provider setup
    // =========================================================================

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
        return [
            StationServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Register a minimal Blade view so Inertia can render responses in tests.
     */
    private function registerMinimalBladeView(): void
    {
        $viewPath = sys_get_temp_dir() . '/app.blade.php';
        if (!file_exists($viewPath)) {
            file_put_contents($viewPath, '<!DOCTYPE html><html><head></head><body>@inertia</body></html>');
        }

        $this->app['config']->set('inertia.testing.ensure_pages_exist', false);
        View::addLocation(sys_get_temp_dir());
    }

    /**
     * Insert a single workflow instance and return its ID.
     *
     * @param array<string, mixed> $overrides
     */
    private function insertWorkflow(array $overrides): string
    {
        $id = Uuid::uuid7()->toString();
        $status = $overrides['status'] ?? 'pending';

        $defaults = [
            'id' => $id,
            'definition_id' => $overrides['definition_id'] ?? Uuid::uuid7()->toString(),
            'definition_name' => $overrides['definition_name'] ?? 'default-workflow',
            'connection' => $overrides['connection'] ?? null,
            'status' => $status,
            'current_step' => $overrides['current_step'] ?? null,
            'input' => '{}',
            'context' => '{}',
            'results' => '{}',
            'step_statuses' => $overrides['step_statuses'] ?? '{}',
            'definition_steps' => $overrides['definition_steps'] ?? '[]',
            'error' => $overrides['error'] ?? null,
            'progress' => $overrides['progress'] ?? 0,
            'started_at' => $overrides['started_at'] ?? ($status !== 'pending' ? now()->toDateTimeString() : null),
            'completed_at' => $overrides['completed_at'] ?? (\in_array($status, ['completed', 'failed', 'cancelled'], true) ? now()->toDateTimeString() : null),
            'created_at' => $overrides['created_at'] ?? now()->toDateTimeString(),
            'updated_at' => $overrides['updated_at'] ?? now()->toDateTimeString(),
        ];

        DB::table('station_workflows')->insert($defaults);

        return $id;
    }

    /**
     * Seed multiple workflow instances.
     *
     * @param array<int, array<string, mixed>> $workflows
     * @return array<int, string> List of inserted IDs
     */
    private function seedWorkflows(array $workflows): array
    {
        $ids = [];
        foreach ($workflows as $overrides) {
            $ids[] = $this->insertWorkflow($overrides);
        }

        return $ids;
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
