<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Station\Dashboard\Http\Controllers\Traits\ApiHelpers;
use Station\Workflows\WorkflowManager;

final class WorkflowController extends Controller
{
    use ApiHelpers;

    public function __construct(
        private readonly WorkflowManager $workflowManager,
    ) {}

    /**
     * Display the workflows list.
     */
    public function index(Request $request): Response
    {
        $definitions = $this->workflowManager->getDefinitions();
        $status = $request->get('status');
        $definition = $request->get('definition');
        $connection = $request->get('connection');
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        // Stats from unfiltered aggregate query
        $stats = DB::table('station_workflows')
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        // Distinct connections for filter dropdown
        $connections = DB::table('station_workflows')
            ->whereNotNull('connection')
            ->distinct()
            ->pluck('connection')
            ->sort()
            ->values()
            ->all();

        // Filtered + paginated query
        $query = DB::table('station_workflows');

        if ($status) {
            $query->where('status', $status);
        }

        if ($definition) {
            $query->where('definition_name', $definition);
        }

        if ($connection) {
            $query->where('connection', $connection);
        }

        $total = (clone $query)->count();
        $offset = ($page - 1) * $perPage;

        $instances = $query
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(static fn($row) => [
                'id' => $row->id,
                'definition_id' => $row->definition_id,
                'definition_name' => $row->definition_name,
                'connection' => $row->connection ?? null,
                'status' => $row->status,
                'current_step' => $row->current_step,
                'progress' => $row->progress,
                'started_at' => $row->started_at,
                'completed_at' => $row->completed_at,
                'duration' => $row->started_at && $row->completed_at
                    ? strtotime($row->completed_at) - strtotime($row->started_at)
                    : ($row->started_at ? time() - strtotime($row->started_at) : null),
            ]);

        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $from = $total > 0 ? $offset + 1 : null;
        $to = $total > 0 ? min($offset + $perPage, $total) : null;

        // Build pagination links
        $links = [];
        $links[] = ['url' => $page > 1 ? $this->buildPageUrl($page - 1, $request) : null, 'label' => '&laquo; Previous', 'active' => false];
        $startPage = max(1, $page - 2);
        $endPage = min($lastPage, $page + 2);

        if ($startPage > 1) {
            $links[] = ['url' => $this->buildPageUrl(1, $request), 'label' => '1', 'active' => false];
            if ($startPage > 2) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $links[] = ['url' => $this->buildPageUrl($i, $request), 'label' => (string) $i, 'active' => $i === $page];
        }

        if ($endPage < $lastPage) {
            if ($endPage < $lastPage - 1) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
            }
            $links[] = ['url' => $this->buildPageUrl($lastPage, $request), 'label' => (string) $lastPage, 'active' => false];
        }

        $links[] = ['url' => $page < $lastPage ? $this->buildPageUrl($page + 1, $request) : null, 'label' => 'Next &raquo;', 'active' => false];

        return Inertia::render('Station/Workflows', [
            'instances' => [
                'data' => $instances->values()->all(),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
                'prev_page_url' => $page > 1 ? $this->buildPageUrl($page - 1, $request) : null,
                'next_page_url' => $page < $lastPage ? $this->buildPageUrl($page + 1, $request) : null,
                'links' => $links,
            ],
            'stats' => [
                'pending' => $stats['pending'] ?? 0,
                'running' => $stats['running'] ?? 0,
                'paused' => $stats['paused'] ?? 0,
                'completed' => $stats['completed'] ?? 0,
                'failed' => $stats['failed'] ?? 0,
                'cancelled' => $stats['cancelled'] ?? 0,
            ],
            'connections' => $connections,
            'filters' => [
                'status' => $status,
                'definition' => $definition,
                'connection' => $connection,
            ],
        ]);
    }

    /**
     * Display the workflow definitions page.
     */
    public function definitions(): Response
    {
        $definitions = $this->workflowManager->getDefinitions();

        return Inertia::render('Station/WorkflowDefinitions', [
            'definitions' => array_values(array_map(
                static fn($def) => $def->toArray(),
                $definitions,
            )),
        ]);
    }

    /**
     * Run a workflow asynchronously.
     */
    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'definition' => 'required|string',
            'connection' => 'nullable|string|max:100',
        ]);

        $definition = $this->workflowManager->getDefinition($request->input('definition'));

        if ($definition === null) {
            return response()->json(['error' => 'Definition not found'], 404);
        }

        $instance = $this->workflowManager->runAsync(
            $request->input('definition'),
            [],
            $request->input('connection'),
        );

        return response()->json([
            'id' => $instance->getId(),
            'status' => $instance->getStatus(),
        ]);
    }

    /**
     * Display a workflow instance.
     */
    public function show(string $id): Response
    {
        $instance = $this->workflowManager->getInstance($id);

        if ($instance === null) {
            abort(404);
        }

        // Prefer snapshotted steps from execution time, fall back to current definition
        $definitionSteps = $instance->getDefinitionSteps();

        if (empty($definitionSteps)) {
            $definition = $this->workflowManager->getDefinition($instance->getDefinitionName());
            $definitionSteps = $definition !== null
                ? array_values(array_map(static fn($step) => $step->toArray(), $definition->getSteps()))
                : [];
        }

        return Inertia::render('Station/WorkflowDetail', [
            'instance' => $instance->toArray(),
            'definitionSteps' => $definitionSteps,
        ]);
    }

    /**
     * Pause a workflow instance.
     */
    public function pause(string $id): JsonResponse
    {
        $success = $this->workflowManager->pause($id);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Workflow paused' : 'Unable to pause workflow',
        ]);
    }

    /**
     * Resume a workflow instance.
     */
    public function resume(string $id): JsonResponse
    {
        $success = $this->workflowManager->resume($id);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Workflow resumed' : 'Unable to resume workflow',
        ]);
    }

    /**
     * Cancel a workflow instance.
     */
    public function cancel(string $id): JsonResponse
    {
        $success = $this->workflowManager->cancel($id);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Workflow cancelled' : 'Unable to cancel workflow',
        ]);
    }

    /**
     * Bulk pause workflows.
     */
    public function bulkPause(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->workflowManager->pause($id));
    }

    /**
     * Bulk resume workflows.
     */
    public function bulkResume(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->workflowManager->resume($id));
    }

    /**
     * Bulk cancel workflows.
     */
    public function bulkCancel(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'string']);

        return $this->bulkAction($request->input('ids'), fn(string $id) => $this->workflowManager->cancel($id));
    }

    /**
     * Get workflow status.
     */
    public function status(string $name, string $id): JsonResponse
    {
        $status = $this->workflowManager->status($name, $id);

        if ($status === null) {
            return response()->json([
                'error' => 'Workflow instance not found',
            ], 404);
        }

        return response()->json($status);
    }

    /**
     * Build a pagination URL preserving current query params.
     */
    private function buildPageUrl(int $page, Request $request): string
    {
        $params = array_filter([
            'status' => $request->get('status'),
            'definition' => $request->get('definition'),
            'connection' => $request->get('connection'),
            'per_page' => $request->get('per_page'),
            'page' => $page,
        ]);

        return route('station.workflows', $params);
    }
}
