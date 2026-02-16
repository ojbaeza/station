<?php

declare(strict_types=1);

namespace Station\Repositories;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Station\Contracts\MetricsRepositoryInterface;
use Station\DTOs\MetricsAggregation;
use Station\DTOs\MetricsSnapshot;
use Station\DTOs\PaginatedResult;
use Station\DTOs\QueueStats;
use Station\DTOs\TimeSeriesPoint;

final class DatabaseMetricsRepository implements MetricsRepositoryInterface
{
    private string $metricsTable;

    private string $jobsTable;

    private string $workersTable;

    private string $queueStatusTable;

    public function __construct(
        private readonly ConnectionInterface $connection,
        string $tablePrefix = 'station_',
    ) {
        $this->metricsTable = $tablePrefix . 'metrics';
        $this->jobsTable = $tablePrefix . 'jobs';
        $this->workersTable = $tablePrefix . 'workers';
        $this->queueStatusTable = $tablePrefix . 'queue_status';
    }

    public function record(string $queue, array $metrics, ?string $connection = null): void
    {
        $this->connection->table($this->metricsTable)->insert([
            'queue' => $queue,
            'connection' => $connection,
            'jobs_processed' => $metrics['jobs_processed'],
            'jobs_failed' => $metrics['jobs_failed'],
            'jobs_pending' => $metrics['jobs_pending'],
            'avg_processing_time' => $metrics['avg_processing_time'],
            'avg_wait_time' => $metrics['avg_wait_time'],
            'peak_memory' => $metrics['peak_memory'],
            'active_workers' => $metrics['active_workers'],
            'recorded_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    public function recordBatch(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $now = CarbonImmutable::now()->toDateTimeString();

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                'queue' => $entry['queue'],
                'connection' => $entry['connection'],
                'jobs_processed' => $entry['metrics']['jobs_processed'],
                'jobs_failed' => $entry['metrics']['jobs_failed'],
                'jobs_pending' => $entry['metrics']['jobs_pending'],
                'avg_processing_time' => $entry['metrics']['avg_processing_time'],
                'avg_wait_time' => $entry['metrics']['avg_wait_time'],
                'peak_memory' => $entry['metrics']['peak_memory'],
                'active_workers' => $entry['metrics']['active_workers'],
                'recorded_at' => $now,
            ];
        }

        $this->connection->table($this->metricsTable)->insert($rows);
    }

    public function getForRange(
        string $queue,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?string $connection = null,
    ): Collection {
        $query = $this->connection->table($this->metricsTable)
            ->where('queue', $queue)
            ->whereBetween('recorded_at', [
                CarbonImmutable::instance($start)->toDateTimeString(),
                CarbonImmutable::instance($end)->toDateTimeString(),
            ]);

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        return $query->orderBy('recorded_at', 'asc')
            ->get()
            ->map(static fn($row): array => (array) $row);
    }

    public function getAggregated(string $queue, int $minutes = 60, ?string $connection = null): MetricsAggregation
    {
        $since = CarbonImmutable::now()->subMinutes($minutes);

        $query = $this->connection->table($this->metricsTable)
            ->where('queue', $queue)
            ->where('recorded_at', '>=', $since->toDateTimeString());

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        $result = $query->selectRaw('
                SUM(jobs_processed) as total_processed,
                SUM(jobs_failed) as total_failed,
                AVG(avg_processing_time) as avg_processing_time,
                AVG(avg_wait_time) as avg_wait_time
            ')
            ->first();

        $totalProcessed = (int) ($result->total_processed ?? 0);
        $totalFailed = (int) ($result->total_failed ?? 0);
        $total = $totalProcessed + $totalFailed;

        return new MetricsAggregation(
            jobs_processed: $totalProcessed,
            jobs_failed: $totalFailed,
            avg_processing_time: (float) ($result->avg_processing_time ?? 0),
            avg_wait_time: (float) ($result->avg_wait_time ?? 0),
            failure_rate: $total > 0 ? round(($totalFailed / $total) * 100, 2) : 0.0,
        );
    }

    public function getSnapshot(): MetricsSnapshot
    {
        $now = CarbonImmutable::now();
        $oneHourAgo = $now->subHour();
        $fiveMinutesAgo = $now->subMinutes(5);

        // Jobs processed in last hour
        $lastHourMetrics = $this->connection->table($this->metricsTable)
            ->where('recorded_at', '>=', $oneHourAgo->toDateTimeString())
            ->selectRaw('
                SUM(jobs_processed) as processed,
                SUM(jobs_failed) as failed,
                AVG(avg_processing_time) as avg_time
            ')
            ->first();

        // Jobs per minute (average over last 5 minutes for smoother metric)
        $last5MinMetrics = $this->connection->table($this->metricsTable)
            ->where('recorded_at', '>=', $fiveMinutesAgo->toDateTimeString())
            ->selectRaw('SUM(jobs_processed) as processed')
            ->first();

        // Pending jobs
        $pendingJobs = $this->connection->table($this->jobsTable)
            ->where('status', 'pending')
            ->count();

        // Active workers
        $activeWorkers = $this->connection->table($this->workersTable)
            ->where('status', 'processing')
            ->count();

        $processed = (int) ($lastHourMetrics->processed ?? 0);
        $failed = (int) ($lastHourMetrics->failed ?? 0);
        $total = $processed + $failed;

        // Calculate throughput as jobs per minute over last 5 minutes
        $jobsInLast5Min = (int) ($last5MinMetrics->processed ?? 0);
        $throughput = round($jobsInLast5Min / 5, 2);

        return new MetricsSnapshot(
            jobs_per_minute: $throughput,
            jobs_processed_last_hour: $processed,
            failed_jobs: $failed,
            failed_rate_percent: $total > 0 ? round(($failed / $total) * 100, 2) : 0.0,
            average_processing_time_ms: (int) ($lastHourMetrics->avg_time ?? 0),
            active_workers: $activeWorkers,
            pending_jobs: $pendingJobs,
        );
    }

    public function getQueueStats(array $additionalQueues = []): array
    {
        // Get queues from jobs table
        $jobQueues = $this->connection->table($this->jobsTable)
            ->select('queue')
            ->distinct()
            ->pluck('queue')
            ->all();

        // Get queues from metrics table (queues that had activity)
        $metricsQueues = $this->connection->table($this->metricsTable)
            ->select('queue')
            ->distinct()
            ->pluck('queue')
            ->all();

        // Merge all discovered queue names
        $queues = collect(array_merge($jobQueues, $metricsQueues, $additionalQueues))
            ->filter()
            ->unique()
            ->values();

        $stats = [];

        foreach ($queues as $queue) {
            // Queue size (pending jobs)
            $size = $this->connection->table($this->jobsTable)
                ->where('queue', $queue)
                ->where('status', 'pending')
                ->count();

            // Workers on this queue
            $workers = $this->connection->table($this->workersTable)
                ->where('queue', $queue)
                ->where('status', '!=', 'stopped')
                ->count();

            // Check if paused
            $queueStatus = $this->connection->table($this->queueStatusTable)
                ->where('queue', $queue)
                ->first();
            $paused = $queueStatus ? (bool) $queueStatus->paused : false;

            // Throughput (jobs per minute in last 5 minutes)
            $fiveMinutesAgo = CarbonImmutable::now()->subMinutes(5);
            $throughputResult = $this->connection->table($this->metricsTable)
                ->where('queue', $queue)
                ->where('recorded_at', '>=', $fiveMinutesAgo->toDateTimeString())
                ->selectRaw('SUM(jobs_processed) as processed')
                ->first();
            $throughput = ((float) ($throughputResult->processed ?? 0)) / 5; // per minute

            $stats[$queue] = new QueueStats(
                size: $size,
                paused: $paused,
                workers: $workers,
                throughput: round($throughput, 2),
            );
        }

        return $stats;
    }

    public function getRecent(string $queue, int $minutes, ?string $connection = null): array
    {
        $since = CarbonImmutable::now()->subMinutes($minutes);

        $query = $this->connection->table($this->metricsTable)
            ->where('queue', $queue)
            ->where('recorded_at', '>=', $since->toDateTimeString());

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        return $query->orderBy('recorded_at', 'desc')
            ->get()
            ->map(static fn($row): array => (array) $row)
            ->all();
    }

    /**
     * Get all recent metrics across all queues.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllRecent(int $minutes, int $limit = 100, ?string $connection = null): array
    {
        $since = CarbonImmutable::now()->subMinutes($minutes);

        $query = $this->connection->table($this->metricsTable)
            ->where('recorded_at', '>=', $since->toDateTimeString());

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        return $query->orderBy('recorded_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(static fn($row): array => (array) $row)
            ->all();
    }

    /**
     * Paginate all recent metrics across all queues.
     */
    public function paginateAllRecent(int $minutes, int $page = 1, int $perPage = 25, ?string $connection = null): PaginatedResult
    {
        $since = CarbonImmutable::now()->subMinutes($minutes);

        $countQuery = $this->connection->table($this->metricsTable)
            ->where('recorded_at', '>=', $since->toDateTimeString());

        if ($connection !== null) {
            $countQuery->where('connection', $connection);
        }

        $total = $countQuery->count();

        $offset = ($page - 1) * $perPage;

        $dataQuery = $this->connection->table($this->metricsTable)
            ->where('recorded_at', '>=', $since->toDateTimeString());

        if ($connection !== null) {
            $dataQuery->where('connection', $connection);
        }

        /** @var Collection<int, array<string, mixed>> $data */
        $data = $dataQuery->orderBy('recorded_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(static fn($row): array => (array) $row);

        return $this->formatPaginationResult($data, $total, $page, $perPage);
    }

    /**
     * Get global aggregated metrics for a period (across all queues).
     */
    public function getGlobalAggregated(int $minutes = 60, ?string $connection = null): MetricsAggregation
    {
        $since = CarbonImmutable::now()->subMinutes($minutes);

        $query = $this->connection->table($this->metricsTable)
            ->where('recorded_at', '>=', $since->toDateTimeString());

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        $result = $query->selectRaw('
                SUM(jobs_processed) as total_processed,
                SUM(jobs_failed) as total_failed,
                AVG(avg_processing_time) as avg_processing_time,
                AVG(avg_wait_time) as avg_wait_time
            ')
            ->first();

        $totalProcessed = (int) ($result->total_processed ?? 0);
        $totalFailed = (int) ($result->total_failed ?? 0);
        $total = $totalProcessed + $totalFailed;

        // Calculate throughput (jobs per minute)
        $throughput = $minutes > 0 ? round($totalProcessed / $minutes, 2) : 0.0;

        return new MetricsAggregation(
            jobs_processed: $totalProcessed,
            jobs_failed: $totalFailed,
            avg_processing_time: (float) ($result->avg_processing_time ?? 0),
            avg_wait_time: (float) ($result->avg_wait_time ?? 0),
            failure_rate: $total > 0 ? round($totalFailed / $total, 4) : 0.0,
            throughput: $throughput,
        );
    }

    public function getTimeSeries(int $minutes, int $buckets = 30, ?string $connection = null, ?string $queue = null): array
    {
        $since = CarbonImmutable::now()->subMinutes($minutes);
        $bucketSeconds = max(60, (int) floor($minutes / $buckets) * 60);
        $driver = $this->connection->getDriverName(); // @phpstan-ignore method.notFound

        // Use database-appropriate timestamp function
        if ($driver === 'sqlite') {
            $bucketExpr = "CAST(strftime('%s', recorded_at) AS INTEGER) / ? * ?";
        } else {
            $bucketExpr = 'FLOOR(UNIX_TIMESTAMP(recorded_at) / ?) * ?';
        }

        $query = $this->connection->table($this->metricsTable)
            ->where('recorded_at', '>=', $since->toDateTimeString());

        if ($connection !== null) {
            $query->where('connection', $connection);
        }

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        $rows = $query
            ->selectRaw("
                {$bucketExpr} as bucket_ts,
                SUM(jobs_processed) as jobs_processed,
                SUM(jobs_failed) as jobs_failed,
                AVG(avg_wait_time) as avg_wait_time,
                AVG(avg_processing_time) as avg_processing_time
            ", [$bucketSeconds, $bucketSeconds])
            ->groupByRaw('bucket_ts')
            ->orderByRaw('bucket_ts ASC')
            ->get();

        // Index actual data by bucket timestamp
        $dataByBucket = [];
        foreach ($rows as $row) {
            $dataByBucket[(int) $row->bucket_ts] = $row;
        }

        // Generate all buckets for the full time range and backfill with zeros
        $now = CarbonImmutable::now();
        $startTs = (int) (floor((int) $since->timestamp / $bucketSeconds) * $bucketSeconds);
        $endTs = (int) (floor((int) $now->timestamp / $bucketSeconds) * $bucketSeconds);

        $result = [];
        for ($ts = $startTs; $ts <= $endTs; $ts += $bucketSeconds) {
            if (isset($dataByBucket[$ts])) {
                $row = $dataByBucket[$ts];
                $result[] = new TimeSeriesPoint(
                    timestamp: date('Y-m-d\TH:i:s\Z', $ts),
                    jobs_processed: (int) ($row->jobs_processed ?? 0),
                    jobs_failed: (int) ($row->jobs_failed ?? 0),
                    avg_wait_time: round((float) ($row->avg_wait_time ?? 0), 2),
                    avg_processing_time: round((float) ($row->avg_processing_time ?? 0), 2),
                );
            } else {
                $result[] = new TimeSeriesPoint(
                    timestamp: date('Y-m-d\TH:i:s\Z', $ts),
                    jobs_processed: 0,
                    jobs_failed: 0,
                    avg_wait_time: 0.0,
                    avg_processing_time: 0.0,
                );
            }
        }

        return $result;
    }

    public function prune(int $hours): int
    {
        $threshold = CarbonImmutable::now()->subHours($hours);

        return $this->connection->table($this->metricsTable)
            ->where('recorded_at', '<', $threshold->toDateTimeString())
            ->delete();
    }

    /**
     * Format pagination result with Laravel-compatible metadata.
     *
     * @param Collection<int, array<string, mixed>> $data
     */
    private function formatPaginationResult(Collection $data, int $total, int $page, int $perPage): PaginatedResult
    {
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $from = $total > 0 ? (($page - 1) * $perPage) + 1 : null;
        $to = $total > 0 ? min($page * $perPage, $total) : null;
        $urlBuilder = fn(int $p): string => $this->buildPageUrl($p);

        return new PaginatedResult(
            data: $data,
            total: $total,
            per_page: $perPage,
            current_page: $page,
            last_page: $lastPage,
            from: $from,
            to: $to,
            links: PaginatedResult::buildLinks($page, $lastPage, $urlBuilder),
            prev_page_url: $page > 1 ? $this->buildPageUrl($page - 1) : null,
            next_page_url: $page < $lastPage ? $this->buildPageUrl($page + 1) : null,
            first_page_url: $this->buildPageUrl(1),
            last_page_url: $this->buildPageUrl($lastPage),
            path: url()->current(),
        );
    }

    /**
     * Build URL for a specific page.
     */
    private function buildPageUrl(int $page): string
    {
        $query = request()->query();
        $query['page'] = $page;

        return url()->current() . '?' . http_build_query($query);
    }
}
