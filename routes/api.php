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

$middleware = config('station.api.middleware', []);
$middleware[] = 'station.security';
$middleware[] = 'throttle:station';

if (config('station.api.auth') === 'token') {
    $middleware[] = 'station.api.auth';
}

// External API routes (token-authenticated, accessed by URL).
// The dashboard uses session-authenticated web routes (routes/web.php) instead.
Route::middleware($middleware)
    ->prefix(config('station.api.prefix', 'api/station'))
    ->group(static function (): void {
        // Overview
        Route::get('/stats', [MetricsController::class, 'stats']);
        Route::get('/monitoring', [MetricsController::class, 'monitoring']);
        Route::get('/health', [MetricsController::class, 'health']);
        Route::get('/drivers', [MetricsController::class, 'drivers']);
        Route::get('/tags', [JobController::class, 'tags']);

        // Jobs
        Route::prefix('jobs')->group(static function (): void {
            Route::get('/', [JobController::class, 'jobs']);
            Route::post('/bulk/cancel', [JobController::class, 'bulkCancelJobs']);
            Route::post('/bulk/retry', [JobController::class, 'bulkRetryJobs']);
            Route::post('/bulk/delete', [JobController::class, 'bulkDeleteJobs']);
            Route::get('/{id}', [JobController::class, 'job']);
            Route::post('/{id}/retry', [JobController::class, 'retryJob']);
            Route::post('/{id}/cancel', [JobController::class, 'cancelJob']);
            Route::post('/{id}/tags', [JobController::class, 'addJobTag']);
            Route::delete('/{id}/tags/{tag}', [JobController::class, 'removeJobTag']);
        });

        // Failed jobs
        Route::prefix('failed')->group(static function (): void {
            Route::get('/', [JobController::class, 'failedJobs']);
            Route::post('/retry-all', [JobController::class, 'retryAllFailed']);
            Route::delete('/', [JobController::class, 'flushFailed']);
            Route::post('/bulk/retry', [JobController::class, 'bulkRetryFailed']);
            Route::post('/bulk/delete', [JobController::class, 'bulkDeleteFailed']);
            Route::post('/{id}/retry', [JobController::class, 'retryFailedJob']);
            Route::delete('/{id}', [JobController::class, 'deleteFailedJob']);
        });

        // Batches
        Route::prefix('batches')->group(static function (): void {
            Route::get('/', [BatchController::class, 'batches']);
            Route::post('/bulk/cancel', [BatchController::class, 'bulkCancelBatches']);
            Route::post('/bulk/retry', [BatchController::class, 'bulkRetryBatches']);
            Route::post('/bulk/delete', [BatchController::class, 'bulkDeleteBatches']);
            Route::get('/{id}', [BatchController::class, 'batch']);
            Route::post('/{id}/cancel', [BatchController::class, 'cancelBatch']);
            Route::post('/{id}/retry', [BatchController::class, 'retryBatch']);
        });

        // Workers
        Route::prefix('workers')->group(static function (): void {
            Route::get('/dashboard-status', [WorkerController::class, 'workerDashboardStatus']);
            Route::get('/status', [WorkerController::class, 'workerStatus']);
            Route::post('/start', [WorkerController::class, 'startWorker']);
            Route::post('/stop', [WorkerController::class, 'stopWorker']);
            Route::post('/stop-external', [WorkerController::class, 'stopExternalWorker']);
        });

        // Supervisor
        Route::prefix('supervisor')->group(static function (): void {
            Route::get('/status', [WorkerController::class, 'supervisorStatus']);
            Route::post('/start', [WorkerController::class, 'startSupervisor']);
            Route::post('/stop', [WorkerController::class, 'stopSupervisor']);
        });

        // Queues
        Route::prefix('queues')->group(static function (): void {
            Route::get('/connections', [WorkerController::class, 'queueConnections']);
            Route::post('/pause', [WorkerController::class, 'pauseQueue']);
            Route::post('/resume', [WorkerController::class, 'resumeQueue']);
            Route::get('/pause-status', [WorkerController::class, 'queuePauseStatus']);
        });

        // Metrics
        Route::prefix('metrics')->group(static function (): void {
            Route::get('/', [MetricsController::class, 'metrics']);
            Route::get('/time-series', [MetricsController::class, 'metricsTimeSeries']);
            Route::get('/driver-info', [MetricsController::class, 'metricsDriverInfo']);
        });

        // Stuck jobs
        Route::prefix('stuck')->group(static function (): void {
            Route::get('/', [RecoveryController::class, 'stuckJobs']);
            Route::post('/{id}/recover', [RecoveryController::class, 'recoverJob']);
            Route::post('/bulk/recover', [RecoveryController::class, 'bulkRecoverJobs']);
        });
        Route::post('/recover', [RecoveryController::class, 'recoverStuck']);

        // Workflows
        Route::prefix('workflows')->group(static function (): void {
            Route::post('/run', [WorkflowController::class, 'run']);
            Route::post('/bulk/pause', [WorkflowController::class, 'bulkPause']);
            Route::post('/bulk/resume', [WorkflowController::class, 'bulkResume']);
            Route::post('/bulk/cancel', [WorkflowController::class, 'bulkCancel']);
            Route::get('/{name}/{id}/status', [WorkflowController::class, 'status']);
            Route::post('/{id}/pause', [WorkflowController::class, 'pause']);
            Route::post('/{id}/resume', [WorkflowController::class, 'resume']);
            Route::post('/{id}/cancel', [WorkflowController::class, 'cancel']);
        });

        // Alerts
        Route::prefix('alerts')->group(static function (): void {
            // Channels
            Route::get('/channels', [AlertController::class, 'channels']);
            Route::post('/channels', [AlertController::class, 'storeChannel']);
            Route::put('/channels/{id}', [AlertController::class, 'updateChannel']);
            Route::delete('/channels/{id}', [AlertController::class, 'destroyChannel']);
            Route::post('/channels/{id}/test', [AlertController::class, 'testChannel']);

            // Rules
            Route::get('/rules', [AlertController::class, 'rules']);
            Route::get('/rules/{id}', [AlertController::class, 'showRule']);
            Route::post('/rules', [AlertController::class, 'storeRule']);
            Route::put('/rules/{id}', [AlertController::class, 'updateRule']);
            Route::delete('/rules/{id}', [AlertController::class, 'destroyRule']);
            Route::post('/rules/{id}/toggle', [AlertController::class, 'toggleRule']);
            Route::post('/rules/{id}/test', [AlertController::class, 'testRule']);
            Route::get('/history', [AlertController::class, 'alertHistory']);
            Route::post('/history/{id}/resolve', [AlertController::class, 'resolveAlert']);
        });
    });
