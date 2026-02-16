<?php

declare(strict_types=1);

namespace Station\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Station\Alerts\AlertManager;
use Station\Contracts\BatchRepositoryInterface;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;
use Station\Core\DriverInfoCollector;
use Station\Core\MetricsCollector;
use Station\Core\ProcessManager;
use Station\Core\QueueManager;
use Station\DTOs\PaginatedResult;
use Station\Enums\Driver;
use Station\Enums\MetricsPeriod;
use Throwable;

final class StationController extends Controller
{
    public function __construct(
        private readonly JobRepositoryInterface $jobRepository,
        private readonly BatchRepositoryInterface $batchRepository,
        private readonly SupervisorRepositoryInterface $supervisorRepository,
        private readonly WorkerRepositoryInterface $workerRepository,
        private readonly MetricsCollector $metrics,
        private readonly HealthCheckerInterface $healthChecker,
        private readonly QueueManager $queueManager,
        private readonly StuckJobDetectorInterface $stuckJobDetector,
        private readonly DriverInfoCollector $driverInfoCollector,
        private readonly ProcessManager $processManager,
        private readonly AlertManager $alertManager,
    ) {}

    /**
     * Display the dashboard.
     */
    public function index(): Response
    {
        return Inertia::render('Station/Dashboard', [
            'stats' => $this->getStats(),
            'health' => $this->getHealthSafe(),
            'pausedQueues' => $this->getPausedQueues(),
            'activeBatches' => $this->getActiveBatches(),
            'recentAlerts' => $this->getRecentAlerts(),
            'recentFailed' => $this->getRecentFailed(),
            'timeSeries' => $this->getTimeSeriesSafe(),
        ]);
    }

    /**
     * Display the jobs list.
     */
    public function jobs(Request $request): Response
    {
        $queue = $request->get('queue');
        $status = $request->get('status');
        $connection = $request->get('connection');
        $tag = $request->get('tag');
        $search = $request->get('search');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $silenced = $this->getSilencedClasses();
        $filters = array_filter([
            'queue' => $queue,
            'status' => $status,
            'connection' => $connection,
            'tag' => $tag,
            'search' => $search,
        ]);

        if ($silenced !== []) {
            $filters['exclude_classes'] = $silenced;
        }

        $jobs = $this->jobRepository->paginate($filters, $page, $perPage);

        $stats = $this->jobRepository->getStats();

        return Inertia::render('Station/Jobs', [
            'jobs' => $jobs,
            'stats' => $stats,
            'filters' => [
                'queue' => $queue,
                'status' => $status,
                'connection' => $connection,
                'tag' => $tag,
                'search' => $search,
            ],
            'queues' => $this->jobRepository->getQueues(),
            'connections' => $this->getAvailableConnections(),
            'availableTags' => $this->jobRepository->getDistinctTags(),
        ]);
    }

    /**
     * Display pending jobs.
     */
    public function pending(Request $request): Response
    {
        $queue = $request->get('queue');
        $connection = $request->get('connection');
        $tag = $request->get('tag');
        $search = $request->get('search');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $silenced = $this->getSilencedClasses();
        $filters = array_filter([
            'queue' => $queue,
            'connection' => $connection,
            'tag' => $tag,
            'search' => $search,
        ]);
        $filters['status'] = 'pending';

        if ($silenced !== []) {
            $filters['exclude_classes'] = $silenced;
        }

        $jobs = $this->jobRepository->paginate($filters, $page, $perPage);

        return Inertia::render('Station/Pending', [
            'jobs' => $jobs,
            'filters' => [
                'queue' => $queue,
                'connection' => $connection,
                'tag' => $tag,
                'search' => $search,
            ],
            'queues' => $this->jobRepository->getQueues(),
            'connections' => $this->getAvailableConnections(),
            'availableTags' => $this->jobRepository->getDistinctTags(),
        ]);
    }

    /**
     * Display completed jobs.
     */
    public function completed(Request $request): Response
    {
        $queue = $request->get('queue');
        $connection = $request->get('connection');
        $tag = $request->get('tag');
        $search = $request->get('search');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $silenced = $this->getSilencedClasses();
        $filters = array_filter([
            'queue' => $queue,
            'connection' => $connection,
            'tag' => $tag,
            'search' => $search,
        ]);
        $filters['status'] = 'completed';

        if ($silenced !== []) {
            $filters['exclude_classes'] = $silenced;
        }

        $jobs = $this->jobRepository->paginate($filters, $page, $perPage);

        return Inertia::render('Station/Completed', [
            'jobs' => $jobs,
            'filters' => [
                'queue' => $queue,
                'connection' => $connection,
                'tag' => $tag,
                'search' => $search,
            ],
            'queues' => $this->jobRepository->getQueues(),
            'connections' => $this->getAvailableConnections(),
            'availableTags' => $this->jobRepository->getDistinctTags(),
        ]);
    }

    /**
     * Display silenced jobs.
     */
    public function silenced(Request $request): Response
    {
        $queue = $request->get('queue');
        $status = $request->get('status');
        $connection = $request->get('connection');
        $tag = $request->get('tag');
        $search = $request->get('search');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $silenced = $this->getSilencedClasses();

        if ($silenced === []) {
            $jobs = $this->formatEmptyPagination($page, $perPage);
        } else {
            $filters = array_filter([
                'queue' => $queue,
                'status' => $status,
                'connection' => $connection,
                'tag' => $tag,
                'search' => $search,
            ]);
            $filters['only_classes'] = $silenced;

            $jobs = $this->jobRepository->paginate($filters, $page, $perPage);
        }

        return Inertia::render('Station/Silenced', [
            'jobs' => $jobs,
            'filters' => [
                'queue' => $queue,
                'status' => $status,
                'connection' => $connection,
                'tag' => $tag,
                'search' => $search,
            ],
            'queues' => $this->jobRepository->getQueues(),
            'connections' => $this->getAvailableConnections(),
            'availableTags' => $this->jobRepository->getDistinctTags(),
            'silencedClasses' => $silenced,
        ]);
    }

    /**
     * Display a single job.
     */
    public function job(string $id): Response
    {
        $job = $this->jobRepository->find($id);

        if ($job === null) {
            abort(404, 'Job not found');
        }

        return Inertia::render('Station/JobDetail', [
            'job' => $job,
            'events' => $this->jobRepository->getEvents($id),
        ]);
    }

    /**
     * Display failed jobs.
     */
    public function failed(Request $request): Response
    {
        $queue = $request->get('queue');
        $connection = $request->get('connection');
        $tag = $request->get('tag');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $failedJobs = $this->jobRepository->paginateFailed(array_filter([
            'queue' => $queue,
            'connection' => $connection,
            'tag' => $tag,
        ]), $page, $perPage);

        return Inertia::render('Station/Failed', [
            'jobs' => $failedJobs,
            'filters' => [
                'queue' => $queue,
                'connection' => $connection,
                'tag' => $tag,
            ],
            'queues' => $this->jobRepository->getQueues(),
            'connections' => $this->getAvailableConnections(),
            'availableTags' => $this->jobRepository->getDistinctTags(),
        ]);
    }

    /**
     * Display batches.
     */
    public function batches(Request $request): Response
    {
        $status = $request->get('status');
        $connection = $request->get('connection');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        $batches = $this->batchRepository->paginate([
            'status' => $status,
            'connection' => $connection,
        ], $page, $perPage);

        $batchStats = DB::table('station_batches')
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        return Inertia::render('Station/Batches', [
            'batches' => $batches,
            'stats' => [
                'pending' => $batchStats['pending'] ?? 0,
                'processing' => $batchStats['processing'] ?? 0,
                'completed' => $batchStats['completed'] ?? 0,
                'failed' => $batchStats['failed'] ?? 0,
                'cancelled' => $batchStats['cancelled'] ?? 0,
            ],
            'filters' => [
                'status' => $status,
                'connection' => $connection,
            ],
            'connections' => $this->getAvailableConnections(),
        ]);
    }

    /**
     * Display a single batch.
     */
    public function batch(string $id): Response
    {
        $batch = $this->batchRepository->find($id);

        if ($batch === null) {
            abort(404, 'Batch not found');
        }

        $jobs = $this->jobRepository->getByBatch($id);

        return Inertia::render('Station/BatchDetail', [
            'batch' => $batch,
            'jobs' => $jobs,
        ]);
    }

    /**
     * Display metrics.
     */
    public function metrics(Request $request): Response
    {
        $period = $request->get('period', '1h');
        $connection = $request->get('connection');

        // Get period-specific aggregated metrics
        $aggregated = $this->metrics->getAggregatedForPeriod($period, $connection);

        return Inertia::render('Station/Metrics', [
            'throughput' => $aggregated->throughput,
            'avgWaitTime' => $aggregated->avg_wait_time,
            'avgProcessingTime' => $aggregated->avg_processing_time,
            'failureRate' => $aggregated->failure_rate,
            'jobsProcessed' => $aggregated->jobs_processed,
            'jobsFailed' => $aggregated->jobs_failed,
            'period' => $period,
            'timeSeries' => $this->metrics->getTimeSeries($period, 30, $connection),
            'connections' => $this->getAvailableConnections(),
            'currentConnection' => $connection,
        ]);
    }

    /**
     * Display metric records (paginated detail page).
     */
    public function metricRecords(Request $request): Response
    {
        $period = $request->get('period', '1h');
        $connection = $request->get('connection');
        $page = (int) $request->get('page', 1);
        $perPage = $this->clampPerPage($request);

        return Inertia::render('Station/MetricRecords', [
            'metrics' => $this->metrics->paginateHistoricalMetrics($period, $page, $perPage, $connection),
            'period' => $period,
            'connections' => $this->getAvailableConnections(),
            'currentConnection' => $connection,
        ]);
    }

    /**
     * Display per-queue metrics breakdown.
     */
    public function metricQueues(Request $request): Response
    {
        $period = $request->get('period', '1h');
        $connectionFilter = $request->get('connection');

        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);

        // Build per-connection queue entries
        $entries = [];

        foreach ($queueConnections as $connName => $cfg) {
            if (!Driver::isStationDriver($cfg['driver'] ?? '')) {
                continue;
            }

            if ($connectionFilter !== null && $connName !== $connectionFilter) {
                continue;
            }

            $queue = $cfg['queue'] ?? 'default';

            $size = DB::table('station_jobs')
                ->where('queue', $queue)
                ->where('connection', $connName)
                ->where('status', 'pending')
                ->count();

            $queueStatus = DB::table('station_queue_status')
                ->where('queue', $queue)
                ->first();
            $paused = $queueStatus ? (bool) $queueStatus->paused : false;

            $minutes = $this->periodToMinutes($period);
            $since = now()->subMinutes($minutes)->toDateTimeString();

            $fiveMinutesAgo = now()->subMinutes(5)->toDateTimeString();
            $throughputResult = DB::table('station_metrics')
                ->where('queue', $queue)
                ->where('connection', $connName)
                ->where('recorded_at', '>=', $fiveMinutesAgo)
                ->selectRaw('SUM(jobs_processed) as processed')
                ->first();
            $throughput = round(((float) ($throughputResult->processed ?? 0)) / 5, 2);

            $agg = DB::table('station_metrics')
                ->where('queue', $queue)
                ->where('connection', $connName)
                ->where('recorded_at', '>=', $since)
                ->selectRaw('SUM(jobs_processed) as total_processed, SUM(jobs_failed) as total_failed')
                ->selectRaw('AVG(avg_processing_time) as avg_runtime, AVG(avg_wait_time) as avg_wait')
                ->first();

            $key = $connName . ':' . $queue;
            $entries[$key] = [
                'queue' => $queue,
                'connection' => $connName,
                'size' => $size,
                'paused' => $paused,
                'throughput' => $throughput,
                'processed' => (int) ($agg->total_processed ?? 0),
                'failed' => (int) ($agg->total_failed ?? 0),
                'avg_runtime' => round((float) ($agg->avg_runtime ?? 0), 2),
                'avg_wait' => round((float) ($agg->avg_wait ?? 0), 2),
            ];
        }

        $timeSeries = [];
        foreach ($entries as $key => $entry) {
            $timeSeries[$key] = $this->metrics->getTimeSeries($period, 30, $entry['connection'], $entry['queue']);
        }

        return Inertia::render('Station/MetricQueues', [
            'entries' => $entries,
            'timeSeries' => $timeSeries,
            'period' => $period,
            'connections' => $this->getAvailableConnections(),
            'currentConnection' => $connectionFilter,
        ]);
    }

    /**
     * Display stuck jobs.
     */
    public function stuckJobs(Request $request): Response
    {
        $threshold = (int) $request->get('threshold', 0);
        $options = $threshold > 0 ? ['threshold' => $threshold] : [];

        $jobs = $this->stuckJobDetector->detect($options);

        return Inertia::render('Station/StuckJobs', [
            'jobs' => $jobs->values()->all(),
            'threshold' => $threshold ?: (int) config('station.stuck_detection.thresholds.heartbeat_timeout', 90),
            'filters' => [
                'threshold' => $threshold ?: null,
            ],
        ]);
    }

    /**
     * Display queue connections (worker command center).
     */
    public function queues(): Response
    {
        $connections = $this->getQueueConnectionDetails();

        // Record an initial driver snapshot so graphs can appear after the first poll (~1 min)
        try {
            $this->driverInfoCollector->recordSnapshots();
        } catch (Throwable) {
            // Table may not exist yet
        }

        return Inertia::render('Station/Queues', [
            'connections' => $connections,
            'driverList' => array_map(
                static fn(Driver $d): array => ['value' => $d->value, 'label' => $d->label()],
                Driver::cases(),
            ),
            'health' => $this->getHealthSafe(),
            'driverInfo' => $this->getDriverInfoSafe(),
        ]);
    }

    /**
     * Display settings.
     */
    public function settings(): Response
    {
        $dashboardConfig = config('station.dashboard', []);

        $telemetryConfig = config('station.telemetry', []);
        $alertsConfig = config('station.alerts', []);

        return Inertia::render('Station/Settings', [
            'config' => [
                'driver' => config('station.default'),
                'dashboard' => [
                    'enabled' => $dashboardConfig['enabled'] ?? true,
                    'path' => $dashboardConfig['path'] ?? 'station',
                    'middleware' => $dashboardConfig['middleware'] ?? [],
                ],
                'supervisor' => config('station.supervisor'),
                'telemetry' => [
                    'enabled' => $telemetryConfig['enabled'] ?? false,
                    'driver' => $telemetryConfig['driver'] ?? 'internal',
                ],
                'alerts' => [
                    'enabled' => $alertsConfig['enabled'] ?? false,
                ],
            ],
        ]);
    }

    /**
     * Display tags with job counts.
     */
    public function tags(Request $request): Response
    {
        $connection = $request->get('connection');
        $search = $request->get('search');
        $page = (int) $request->get('page', 1);
        $perPage = 50;

        $query = DB::table('station_jobs')
            ->whereNotNull('tags')
            ->where('tags', '!=', '[]');

        if ($connection) {
            $query->where('connection', $connection);
        }

        $tags = $query->orderByDesc('created_at')->limit(5000)->pluck('tags')
            ->flatMap(static function ($tagJson) {
                $decoded = \is_string($tagJson) ? json_decode($tagJson, true) : $tagJson;

                return \is_array($decoded) ? $decoded : [];
            })
            ->countBy()
            ->map(static fn(int $count, string $tag): array => ['tag' => $tag, 'count' => $count])
            ->sortByDesc('count')
            ->values();

        if ($search) {
            $searchLower = mb_strtolower($search);
            $tags = $tags->filter(static fn(array $item): bool => str_contains(mb_strtolower($item['tag']), $searchLower))->values();
        }

        $total = $tags->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $items = $tags->slice($offset, $perPage)->values()->all();

        return Inertia::render('Station/Tags', [
            'tags' => [
                'data' => $items,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? $offset + 1 : null,
                'to' => $total > 0 ? min($offset + $perPage, $total) : null,
                'prev_page_url' => $page > 1 ? route('station.tags', array_filter(['connection' => $connection, 'search' => $search, 'page' => $page - 1])) : null,
                'next_page_url' => $page < $lastPage ? route('station.tags', array_filter(['connection' => $connection, 'search' => $search, 'page' => $page + 1])) : null,
                'links' => $this->buildPaginationLinks($page, $lastPage, array_filter(['connection' => $connection, 'search' => $search])),
            ],
            'filters' => [
                'connection' => $connection,
                'search' => $search,
            ],
            'connections' => $this->getAvailableConnections(),
        ]);
    }

    /**
     * Display audit log placeholder.
     */
    public function auditLog(): Response
    {
        return Inertia::render('Station/AuditLog');
    }

    /**
     * Build pagination links array matching Laravel's paginator format.
     *
     * @param array<string, string> $queryParams
     * @return array<int, array{url: ?string, label: string, active: bool}>
     */
    private function buildPaginationLinks(int $currentPage, int $lastPage, array $queryParams = []): array
    {
        $links = [];

        $links[] = [
            'url' => $currentPage > 1 ? route('station.tags', $queryParams + ['page' => $currentPage - 1]) : null,
            'label' => '&laquo; Previous',
            'active' => false,
        ];

        for ($i = 1; $i <= $lastPage; $i++) {
            $links[] = [
                'url' => route('station.tags', $queryParams + ['page' => $i]),
                'label' => (string) $i,
                'active' => $i === $currentPage,
            ];
        }

        $links[] = [
            'url' => $currentPage < $lastPage ? route('station.tags', $queryParams + ['page' => $currentPage + 1]) : null,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }

    /**
     * Get active batches for the dashboard widget.
     *
     * @return list<array<string, mixed>>
     */
    private function getActiveBatches(): array
    {
        try {
            return $this->batchRepository->getActive() // @phpstan-ignore return.type
                ->take(5)
                ->values()
                ->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get recent alerts for the dashboard widget.
     */
    private function getRecentAlerts(): PaginatedResult
    {
        try {
            return $this->alertManager->getHistory([], 1, 5);
        } catch (Throwable) {
            return PaginatedResult::empty(5);
        }
    }

    /**
     * Get recent failed jobs for the dashboard widget.
     */
    private function getRecentFailed(): PaginatedResult
    {
        try {
            return $this->jobRepository->paginateFailed([], 1, 5);
        } catch (Throwable) {
            return PaginatedResult::empty(5);
        }
    }

    /**
     * Get time series data for the dashboard throughput chart.
     *
     * @return list<array<string, mixed>>
     */
    private function getTimeSeriesSafe(): array
    {
        try {
            return $this->metrics->getTimeSeries('6h', 30); // @phpstan-ignore return.type
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get health check without blocking on unreachable drivers.
     *
     * Uses quick TCP checks for connection status instead of deep checks
     * that would hang if a driver (e.g. Redis) is down.
     *
     * @return array<string, mixed>
     */
    private function getHealthSafe(): array
    {
        try {
            // Run basic health checks (database, disk) without deep driver checks
            $health = [
                'status' => 'healthy',
                'timestamp' => now()->toIso8601String(),
                'checks' => [],
                'connections' => [],
            ];

            // Database check
            try {
                $dbCheck = $this->healthChecker->checkDatabase();
                $health['checks']['database'] = $dbCheck;
                if ($dbCheck['status'] !== 'healthy') {
                    $health['status'] = 'unhealthy';
                }
            } catch (Throwable) {
                $health['checks']['database'] = ['status' => 'unhealthy', 'latency_ms' => 0];
                $health['status'] = 'unhealthy';
            }

            // Quick TCP connectivity checks (never blocks)
            $health['connections'] = $this->healthChecker->checkConnectivityQuick();
        } catch (Throwable) {
            $health = [
                'status' => 'unhealthy',
                'timestamp' => now()->toIso8601String(),
                'checks' => [],
                'connections' => [],
            ];
        }

        return $health;
    }

    /**
     * Get driver info without blocking on unreachable drivers.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getDriverInfoSafe(): array
    {
        try {
            return $this->driverInfoCollector->getAll();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get dashboard statistics.
     *
     * @return array<string, mixed>
     */
    private function getStats(): array
    {
        $stats = $this->jobRepository->getStatsByQueue();

        $totals = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($stats as $queueStats) {
            $totals['pending'] += $queueStats->pending;
            $totals['processing'] += $queueStats->processing;
            $totals['completed'] += $queueStats->completed;
            $totals['failed'] += $queueStats->failed;
        }

        // Subtract silenced job counts from dashboard totals
        $silenced = $this->getSilencedClasses();

        if ($silenced !== []) {
            foreach (['pending', 'processing', 'completed', 'failed'] as $status) {
                $silencedCount = $this->jobRepository->count([
                    'status' => $status,
                    'only_classes' => $silenced,
                ]);
                $totals[$status] = max(0, $totals[$status] - $silencedCount);
            }
        }

        // Use fast DB counts for the dashboard (ProcessManager shells out to `ps` which is slow)
        $liveSupervisors = \count($this->supervisorRepository->getActive());
        $liveWorkers = \count($this->workerRepository->getActive());

        return [
            'totals' => $totals,
            'queues' => $stats,
            'throughput' => $this->metrics->getThroughput(),
            'failureRate' => $this->metrics->getFailureRate(),
            'activeSupervisors' => $liveSupervisors,
            'activeWorkers' => $liveWorkers,
        ];
    }

    /**
     * Return an empty pagination result matching the standard shape.
     *
     * @return array<string, mixed>
     */
    private function formatEmptyPagination(int $page, int $perPage): array
    {
        return [
            'data' => [],
            'total' => 0,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => 1,
            'from' => null,
            'to' => null,
            'prev_page_url' => null,
            'next_page_url' => null,
            'links' => [],
        ];
    }

    /**
     * Get silenced job classes from config.
     *
     * @return list<string>
     */
    private function getSilencedClasses(): array
    {
        return array_values(array_filter((array) config('station.silenced', [])));
    }

    /**
     * Clamp per_page parameter to a safe range.
     */
    private function clampPerPage(Request $request, int $default = 25, int $max = 100): int
    {
        $perPage = (int) $request->get('per_page', $default);

        return max(1, min($perPage, $max));
    }

    /**
     * Get paused queues for all connections.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getPausedQueues(): array
    {
        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);
        $statuses = [];

        foreach ($queueConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            try {
                $statuses[$name] = $this->queueManager->status($name);
            } catch (Throwable) {
                $statuses[$name] = ['paused' => false];
            }
        }

        return $statuses;
    }

    /**
     * Build queue connection details for the Queues page.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getQueueConnectionDetails(): array
    {
        /** @var array<string, array<string, mixed>> $queueConnections */
        $queueConnections = config('queue.connections', []);
        $connectivity = $this->healthChecker->checkConnectivityQuick();

        try {
            $workerStatus = $this->processManager->getWorkerStatus();
        } catch (Throwable) {
            $workerStatus = [];
        }

        $connections = [];
        $defaultConnection = config('queue.default');

        foreach ($queueConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'sync';

            if (!Driver::isStationDriver($driver)) {
                continue;
            }

            $canonicalDriver = str_replace('station-', '', $driver);

            try {
                $pauseStatus = $this->queueManager->status($name);
            } catch (Throwable) {
                $pauseStatus = [];
            }

            // status() returns array keyed by queue name, check if any queue is paused
            $isPaused = false;
            foreach ($pauseStatus as $queueStatus) {
                if (($queueStatus['paused'] ?? false)) {
                    $isPaused = true;

                    break;
                }
            }

            $connStatus = $connectivity[$name] ?? null;
            $connections[$name] = [
                'name' => $name,
                'driver' => $canonicalDriver,
                'is_default' => $name === $defaultConnection,
                'connected' => $connStatus !== null ? $connStatus->connected : false,
                'latency_ms' => $connStatus !== null ? $connStatus->latency_ms : 0,
                'dashboard_url' => $connStatus?->dashboard_url,
                'workers' => \count($workerStatus[$name]['workers'] ?? []),
                'paused' => $isPaused,
                'config' => $this->sanitizeConnectionConfig($connectionConfig, $canonicalDriver),
            ];
        }

        return $connections;
    }

    /**
     * Sanitize connection config to remove secrets.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function sanitizeConnectionConfig(array $config, string $driver): array
    {
        $allowedKeys = match ($driver) {
            'rabbitmq' => ['host', 'port', 'vhost', 'queue', 'exchange', 'heartbeat'],
            'redis' => ['connection', 'queue', 'retry_after', 'block_for'],
            'sqs' => ['region', 'prefix', 'queue', 'suffix', 'visibility_timeout', 'wait_time'],
            'beanstalkd' => ['host', 'port', 'queue', 'ttr', 'reserve_timeout'],
            'kafka' => ['brokers', 'topic', 'group_id', 'consumer_timeout'],
            default => ['queue'],
        };

        $sanitized = [];
        foreach ($allowedKeys as $key) {
            if (\array_key_exists($key, $config)) {
                $sanitized[$key] = $config[$key];
            }
        }

        return $sanitized;
    }

    /**
     * Convert period string to minutes.
     */
    private function periodToMinutes(string $period): int
    {
        $metricsPeriod = MetricsPeriod::tryFrom($period);

        return $metricsPeriod?->toMinutes() ?? MetricsPeriod::OneHour->toMinutes();
    }

    /**
     * Get available queue connections/drivers.
     *
     * @return list<string>
     */
    private function getAvailableConnections(): array
    {
        /** @var array<string, mixed> $queueConnections */
        $queueConnections = config('queue.connections', []);
        $connections = array_keys($queueConnections);

        // Filter to only Station-supported connections
        $stationDrivers = ['sync', ...Driver::values(), 'station', ...Driver::connectors()];

        $filtered = [];
        foreach ($connections as $connection) {
            $driver = config("queue.connections.{$connection}.driver");
            if (\in_array($driver, $stationDrivers, true)) {
                $filtered[] = (string) $connection;
            }
        }

        sort($filtered);

        return $filtered;
    }
}
