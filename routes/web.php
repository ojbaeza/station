<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Station\Dashboard\Http\Controllers\AlertController;
use Station\Dashboard\Http\Controllers\BatchController;
use Station\Dashboard\Http\Controllers\JobController;
use Station\Dashboard\Http\Controllers\MetricsController;
use Station\Dashboard\Http\Controllers\RecoveryController;
use Station\Dashboard\Http\Controllers\WorkerController;
use Station\Dashboard\Http\Controllers\WorkflowController;
use Station\Dashboard\StationController;

$middleware = config('station.dashboard.middleware', ['web']);
$middleware[] = 'station.security';
$middleware[] = 'station.auth';

Route::middleware($middleware)
    ->prefix(config('station.dashboard.path', 'station'))
    ->name('station.')
    ->group(static function (): void {
        // Dashboard pages (Inertia)
        Route::get('/', [StationController::class, 'index'])->name('dashboard');
        Route::get('/connections', [StationController::class, 'queues'])->name('connections');
        Route::get('/jobs', [StationController::class, 'jobs'])->name('jobs');
        Route::get('/jobs/{id}', [StationController::class, 'job'])->name('jobs.show');
        Route::get('/pending', [StationController::class, 'pending'])->name('pending');
        Route::get('/failed', [StationController::class, 'failed'])->name('failed');
        Route::get('/stuck', [StationController::class, 'stuckJobs'])->name('stuck');
        Route::get('/completed', [StationController::class, 'completed'])->name('completed');
        Route::get('/silenced', [StationController::class, 'silenced'])->name('silenced');
        Route::get('/metrics', [StationController::class, 'metrics'])->name('metrics');
        Route::get('/metrics/queues', [StationController::class, 'metricQueues'])->name('metrics.queues');
        Route::get('/metrics/records', [StationController::class, 'metricRecords'])->name('metrics.records');
        Route::get('/settings', [StationController::class, 'settings'])->name('settings');

        // Batches routes - index before show to prevent route conflicts
        Route::get('/batches', [StationController::class, 'batches'])->name('batches');
        Route::get('/batches/{id}', [StationController::class, 'batch'])->name('batches.show');

        // Workflow routes - specific paths before wildcard to prevent route conflicts
        Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows');
        Route::get('/workflows/definitions', [WorkflowController::class, 'definitions'])->name('workflows.definitions');
        Route::get('/workflows/{id}', [WorkflowController::class, 'show'])->name('workflows.show');

        // Alert routes
        Route::get('/alerts', [AlertController::class, 'index'])->name('alerts');
        Route::get('/alerts/rules', [AlertController::class, 'rulesPage'])->name('alerts.rules');
        Route::get('/alerts/channels', [AlertController::class, 'channelsPage'])->name('alerts.channels');

        // Tags
        Route::get('/tags', [StationController::class, 'tags'])->name('tags');

        // Audit Log
        Route::get('/audit-log', [StationController::class, 'auditLog'])->name('audit-log');

        // Dashboard internal API (session-authenticated)
        // Vue components use these named routes for fetch() and router.post() calls.
        // The external API (routes/api.php) is separate, token-authenticated, and unnamed.
        Route::prefix('api')->group(static function (): void {
            // Stats
            Route::get('/stats', [MetricsController::class, 'stats'])->name('api.stats');

            // Jobs
            Route::get('/jobs', [JobController::class, 'jobs'])->name('api.jobs');
            Route::get('/jobs/{id}', [JobController::class, 'job'])->name('api.jobs.show');
            Route::post('/jobs/{id}/retry', [JobController::class, 'retryJob'])->name('api.jobs.retry');
            Route::post('/jobs/{id}/cancel', [JobController::class, 'cancelJob'])->name('api.jobs.cancel');

            // Failed jobs
            Route::get('/failed', [JobController::class, 'failedJobs'])->name('api.failed');
            Route::post('/failed/{id}/retry', [JobController::class, 'retryFailedJob'])->name('api.failed.retry');
            Route::delete('/failed/{id}', [JobController::class, 'deleteFailedJob'])->name('api.failed.delete');
            Route::post('/failed/retry-all', [JobController::class, 'retryAllFailed'])->name('api.failed.retry-all');
            Route::delete('/failed', [JobController::class, 'flushFailed'])->name('api.failed.flush');

            // Batches
            Route::get('/batches', [BatchController::class, 'batches'])->name('api.batches');
            Route::get('/batches/{id}', [BatchController::class, 'batch'])->name('api.batches.show');
            Route::post('/batches/{id}/cancel', [BatchController::class, 'cancelBatch'])->name('api.batches.cancel');
            Route::post('/batches/{id}/retry', [BatchController::class, 'retryBatch'])->name('api.batches.retry');

            // Health
            Route::get('/health', [MetricsController::class, 'health'])->name('api.health');
            Route::get('/metrics', [MetricsController::class, 'metrics'])->name('api.metrics');

            // Drivers
            Route::get('/drivers', [MetricsController::class, 'drivers'])->name('api.drivers');

            // Workers
            Route::get('/workers/dashboard-status', [WorkerController::class, 'workerDashboardStatus'])->name('api.workers.dashboard-status');
            Route::get('/workers/status', [WorkerController::class, 'workerStatus'])->name('api.workers.status');
            Route::post('/workers/start', [WorkerController::class, 'startWorker'])->name('api.workers.start');
            Route::post('/workers/stop', [WorkerController::class, 'stopWorker'])->name('api.workers.stop');
            Route::post('/workers/stop-external', [WorkerController::class, 'stopExternalWorker'])->name('api.workers.stop-external');

            // Supervisor
            Route::get('/supervisor/status', [WorkerController::class, 'supervisorStatus'])->name('api.supervisor.status');
            Route::post('/supervisor/start', [WorkerController::class, 'startSupervisor'])->name('api.supervisor.start');
            Route::post('/supervisor/stop', [WorkerController::class, 'stopSupervisor'])->name('api.supervisor.stop');

            // Queue management
            Route::get('/queues/connections', [WorkerController::class, 'queueConnections'])->name('api.queues.connections');
            Route::post('/queues/pause', [WorkerController::class, 'pauseQueue'])->name('api.queues.pause');
            Route::post('/queues/resume', [WorkerController::class, 'resumeQueue'])->name('api.queues.resume');
            Route::get('/queues/pause-status', [WorkerController::class, 'queuePauseStatus'])->name('api.queues.pause-status');

            // Bulk operations - Jobs
            Route::post('/jobs/bulk/cancel', [JobController::class, 'bulkCancelJobs'])->name('api.jobs.bulk.cancel');
            Route::post('/jobs/bulk/retry', [JobController::class, 'bulkRetryJobs'])->name('api.jobs.bulk.retry');
            Route::post('/jobs/bulk/delete', [JobController::class, 'bulkDeleteJobs'])->name('api.jobs.bulk.delete');

            // Bulk operations - Failed
            Route::post('/failed/bulk/retry', [JobController::class, 'bulkRetryFailed'])->name('api.failed.bulk.retry');
            Route::post('/failed/bulk/delete', [JobController::class, 'bulkDeleteFailed'])->name('api.failed.bulk.delete');

            // Bulk operations - Batches
            Route::post('/batches/bulk/cancel', [BatchController::class, 'bulkCancelBatches'])->name('api.batches.bulk.cancel');
            Route::post('/batches/bulk/retry', [BatchController::class, 'bulkRetryBatches'])->name('api.batches.bulk.retry');
            Route::post('/batches/bulk/delete', [BatchController::class, 'bulkDeleteBatches'])->name('api.batches.bulk.delete');

            // Recovery
            Route::post('/recover', [RecoveryController::class, 'recoverStuck'])->name('api.recover');

            // Enhanced metrics
            Route::get('/metrics/time-series', [MetricsController::class, 'metricsTimeSeries'])->name('api.metrics.time-series');
            Route::get('/metrics/driver-info', [MetricsController::class, 'metricsDriverInfo'])->name('api.metrics.driver-info');
            Route::get('/metrics/driver-time-series', [MetricsController::class, 'driverTimeSeries'])->name('api.metrics.driver-time-series');

            // Tags
            Route::get('/tags', [JobController::class, 'tags'])->name('api.tags');
            Route::post('/jobs/{id}/tags', [JobController::class, 'addJobTag'])->name('api.jobs.tags.add');
            Route::delete('/jobs/{id}/tags/{tag}', [JobController::class, 'removeJobTag'])->name('api.jobs.tags.remove');

            // Stuck jobs
            Route::get('/stuck', [RecoveryController::class, 'stuckJobs'])->name('api.stuck');
            Route::post('/stuck/{id}/recover', [RecoveryController::class, 'recoverJob'])->name('api.stuck.recover');
            Route::post('/stuck/bulk/recover', [RecoveryController::class, 'bulkRecoverJobs'])->name('api.stuck.bulk.recover');

            // Alerts - Channels
            Route::get('/alerts/channels', [AlertController::class, 'channels'])->name('api.alerts.channels');
            Route::post('/alerts/channels', [AlertController::class, 'storeChannel'])->name('api.alerts.channels.store');
            Route::put('/alerts/channels/{id}', [AlertController::class, 'updateChannel'])->name('api.alerts.channels.update');
            Route::delete('/alerts/channels/{id}', [AlertController::class, 'destroyChannel'])->name('api.alerts.channels.destroy');
            Route::post('/alerts/channels/{id}/test', [AlertController::class, 'testChannel'])->name('api.alerts.channels.test');

            // Alerts - Rules
            Route::get('/alerts/rules', [AlertController::class, 'rules'])->name('api.alerts.rules');
            Route::get('/alerts/rules/{id}', [AlertController::class, 'showRule'])->name('api.alerts.rules.show');
            Route::post('/alerts/rules', [AlertController::class, 'storeRule'])->name('api.alerts.rules.store');
            Route::put('/alerts/rules/{id}', [AlertController::class, 'updateRule'])->name('api.alerts.rules.update');
            Route::delete('/alerts/rules/{id}', [AlertController::class, 'destroyRule'])->name('api.alerts.rules.destroy');
            Route::post('/alerts/rules/{id}/toggle', [AlertController::class, 'toggleRule'])->name('api.alerts.rules.toggle');
            Route::post('/alerts/rules/{id}/test', [AlertController::class, 'testRule'])->name('api.alerts.rules.test');
            Route::get('/alerts/history', [AlertController::class, 'alertHistory'])->name('api.alerts.history');
            Route::post('/alerts/history/{id}/resolve', [AlertController::class, 'resolveAlert'])->name('api.alerts.history.resolve');

            // Workflows
            Route::post('/workflows/run', [WorkflowController::class, 'run'])->name('api.workflows.run');
            Route::get('/workflows/{name}/{id}/status', [WorkflowController::class, 'status'])->name('api.workflows.status');
            Route::post('/workflows/{id}/pause', [WorkflowController::class, 'pause'])->name('api.workflows.pause');
            Route::post('/workflows/{id}/resume', [WorkflowController::class, 'resume'])->name('api.workflows.resume');
            Route::post('/workflows/{id}/cancel', [WorkflowController::class, 'cancel'])->name('api.workflows.cancel');

            // Bulk operations - Workflows
            Route::post('/workflows/bulk/pause', [WorkflowController::class, 'bulkPause'])->name('api.workflows.bulk.pause');
            Route::post('/workflows/bulk/resume', [WorkflowController::class, 'bulkResume'])->name('api.workflows.bulk.resume');
            Route::post('/workflows/bulk/cancel', [WorkflowController::class, 'bulkCancel'])->name('api.workflows.bulk.cancel');
        });
    });
