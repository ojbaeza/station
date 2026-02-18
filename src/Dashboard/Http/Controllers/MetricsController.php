<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;
use Station\Core\DriverInfoCollector;
use Station\Core\MetricsCollector;
use Station\Core\ProcessManager;
use Station\Dashboard\Http\Controllers\Traits\ApiHelpers;
use Station\Enums\HealthStatus;
use Station\Telemetry\InternalMeter;
use Station\Telemetry\TelemetryManager;
use Throwable;

final class MetricsController extends Controller
{
    use ApiHelpers;

    public function __construct(
        private readonly MetricsCollector $metrics,
        private readonly HealthCheckerInterface $healthChecker,
        private readonly DriverInfoCollector $driverInfoCollector,
        private readonly JobRepositoryInterface $jobRepository,
        private readonly SupervisorRepositoryInterface $supervisorRepository,
        private readonly WorkerRepositoryInterface $workerRepository,
        private readonly ProcessManager $processManager,
    ) {}

    /**
     * Get dashboard stats.
     */
    public function stats(): JsonResponse
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

        return response()->json([
            'totals' => $totals,
            'queues' => $stats,
            'throughput' => $this->metrics->getThroughput(),
            'failureRate' => $this->metrics->getFailureRate(),
            'activeSupervisors' => \count($this->supervisorRepository->getActive()),
            'activeWorkers' => \count($this->workerRepository->getActive()),
        ]);
    }

    /**
     * Get monitoring data.
     */
    public function monitoring(): JsonResponse
    {
        return response()->json([
            'supervisors' => $this->supervisorRepository->getActive(),
            'workers' => $this->workerRepository->getActive(),
            'health' => $this->getHealthSafe(),
        ]);
    }

    /**
     * Get health check.
     */
    public function health(): JsonResponse
    {
        $health = $this->getHealthSafe();

        $statusCode = match ($health['status']) {
            HealthStatus::Healthy->value, HealthStatus::Degraded->value => 200,
            HealthStatus::Unhealthy->value => 503,
            default => 500,
        };

        return response()->json($health, $statusCode);
    }

    /**
     * Get metrics.
     */
    public function metrics(Request $request): JsonResponse
    {
        $period = $request->get('period', '1h');

        return response()->json([
            'metrics' => $this->metrics->getMetrics($period),
            'throughput' => $this->metrics->getThroughput(),
            'avgWaitTime' => $this->metrics->getAverageWaitTime(),
            'avgProcessingTime' => $this->metrics->getAverageProcessingTime(),
            'failureRate' => $this->metrics->getFailureRate(),
        ]);
    }

    /**
     * Get time-series metrics for a period.
     */
    public function metricsTimeSeries(Request $request): JsonResponse
    {
        $period = $request->get('period', '1h');
        $buckets = (int) $request->get('buckets', 30);

        return response()->json($this->metrics->getTimeSeries($period, min($buckets, 100)));
    }

    /**
     * Get per-driver detailed info.
     */
    public function metricsDriverInfo(): JsonResponse
    {
        return response()->json($this->driverInfoCollector->getAll());
    }

    /**
     * Get driver time-series data for performance graphs.
     */
    public function driverTimeSeries(Request $request): JsonResponse
    {
        $connection = $request->get('connection');

        if (!$connection) {
            return response()->json(['error' => 'Connection is required'], 400);
        }

        $period = $request->get('period', '1h');

        try {
            return response()->json($this->driverInfoCollector->getTimeSeries($connection, $period));
        } catch (Throwable $e) {
            return $this->errorResponse($e, 'Failed to get driver time series');
        }
    }

    /**
     * Get driver connectivity status (lightweight TCP check) with worker counts.
     */
    public function drivers(): JsonResponse
    {
        $connectivity = $this->healthChecker->checkConnectivityQuick();

        try {
            $workerStatus = $this->processManager->getWorkerStatus();
        } catch (Throwable) {
            $workerStatus = [];
        }

        $default = config('queue.default');

        $result = [];
        foreach ($connectivity as $name => $info) {
            $allWorkers = $workerStatus[$name]['workers'] ?? [];
            $entry = $info->toArray();
            $entry['workers'] = \count(array_filter($allWorkers, static fn($w) => $w['role'] !== 'supervisor'));
            $entry['is_default'] = $name === $default;
            $result[$name] = $entry;
        }

        return response()->json($result);
    }

    /**
     * Export metrics in Prometheus format.
     */
    public function prometheus(): Response
    {
        try {
            $telemetry = app(TelemetryManager::class);
            $meter = $telemetry->getMeter();

            if (!$meter instanceof InternalMeter) {
                return response('Prometheus export requires the internal meter driver.', 501)
                    ->header('Content-Type', 'text/plain');
            }

            return response($meter->exportPrometheus(), 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        } catch (Throwable $e) {
            return response('Failed to export metrics: ' . $e->getMessage(), 500)
                ->header('Content-Type', 'text/plain');
        }
    }

    /**
     * Get health data using quick TCP checks instead of deep driver checks.
     *
     * Prevents the request from hanging when a queue driver is unreachable.
     *
     * @return array<string, mixed>
     */
    private function getHealthSafe(): array
    {
        try {
            $health = [
                'status' => HealthStatus::Healthy->value,
                'timestamp' => now()->toIso8601String(),
                'checks' => [],
                'connections' => [],
            ];

            try {
                $dbCheck = $this->healthChecker->checkDatabase();
                $health['checks']['database'] = $dbCheck;
                if ($dbCheck['status'] !== HealthStatus::Healthy->value) {
                    $health['status'] = HealthStatus::Unhealthy->value;
                }
            } catch (Throwable) {
                $health['checks']['database'] = ['status' => HealthStatus::Unhealthy->value, 'latency_ms' => 0];
                $health['status'] = HealthStatus::Unhealthy->value;
            }

            $health['connections'] = $this->healthChecker->checkConnectivityQuick();

            return $health;
        } catch (Throwable) {
            return [
                'status' => HealthStatus::Unhealthy->value,
                'timestamp' => now()->toIso8601String(),
                'checks' => [],
                'connections' => [],
            ];
        }
    }
}
