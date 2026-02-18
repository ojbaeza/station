<?php

declare(strict_types=1);

namespace Station;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Notification as NotificationManager;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Station\Alerts\AlertManager;
use Station\Alerts\Channels\StationDiscordChannel;
use Station\Alerts\Channels\StationGoogleChatChannel;
use Station\Alerts\Channels\StationLogChannel;
use Station\Alerts\Channels\StationSlackChannel;
use Station\Alerts\Channels\StationTeamsChannel;
use Station\Alerts\Channels\StationWebhookChannel;
use Station\Alerts\Evaluators\HighFailureRateEvaluator;
use Station\Alerts\Evaluators\QueueBackupEvaluator;
use Station\Alerts\Evaluators\StuckJobsEvaluator;
use Station\Alerts\Evaluators\WorkerDownEvaluator;
use Station\Commands\AlertsCheckCommand;
use Station\Commands\FailedCommand;
use Station\Commands\FlushCommand;
use Station\Commands\HealthCommand;
use Station\Commands\InstallCommand;
use Station\Commands\PauseCommand;
use Station\Commands\PruneCommand;
use Station\Commands\PublishSupervisorCommand;
use Station\Commands\RecoverCommand;
use Station\Commands\ResumeCommand;
use Station\Commands\RetryCommand;
use Station\Commands\StatusCommand;
use Station\Commands\TerminateCommand;
use Station\Commands\WorkCommand;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\Contracts\BatchRepositoryInterface;
use Station\Contracts\CheckpointManagerInterface;
use Station\Contracts\CheckpointRepositoryInterface;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\MetricsCollectorInterface;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;
use Station\Contracts\WorkerSupervisorInterface;
use Station\Core\BatchManager;
use Station\Core\DriverInfoCollector;
use Station\Core\JobManager;
use Station\Core\MetricsCollector;
use Station\Core\ProcessManager;
use Station\Core\QueueManager as StationQueueManager;
use Station\Core\WorkerSupervisor;
use Station\Dashboard\Http\Middleware\Authorize;
use Station\Dashboard\Http\Middleware\SecurityHeaders;
use Station\Dashboard\Middleware\ValidateApiToken;
use Station\Drivers\Beanstalkd\BeanstalkdConnector;
use Station\Drivers\Kafka\KafkaConnector;
use Station\Drivers\RabbitMQ\RabbitMQConnector;
use Station\Drivers\Redis\RedisConnector;
use Station\Drivers\Sqs\SqsConnector;
use Station\Enums\AlertType;
use Station\Events\JobStarted;
use Station\Events\WorkerStopped;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStepCompleted;
use Station\Recovery\CheckpointManager;
use Station\Recovery\HealthChecker;
use Station\Recovery\JobResumer;
use Station\Recovery\StuckJobDetector;
use Station\Repositories\DatabaseAlertChannelRepository;
use Station\Repositories\DatabaseAlertRepository;
use Station\Repositories\DatabaseBatchRepository;
use Station\Repositories\DatabaseCheckpointRepository;
use Station\Repositories\DatabaseJobRepository;
use Station\Repositories\DatabaseMetricsRepository;
use Station\Repositories\DatabaseSupervisorRepository;
use Station\Repositories\DatabaseWorkerRepository;
use Station\Scaling\AutoScaler;
use Station\Telemetry\TelemetryManager;
use Station\Workflows\WorkflowManager;
use Throwable;

class StationServiceProvider extends ServiceProvider
{
    /** @var array<string, float> Process-local job start times (keyed by tracking ID) */
    private static array $jobStartTimes = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/station.php', 'station');

        $this->registerRepositories();
        $this->registerCoreServices();
        $this->registerRecoveryServices();
        $this->registerWorkflows();
        $this->registerScaling();
        $this->registerTelemetry();
        $this->registerAlerts();
        $this->registerQueueConnector();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerRoutes();
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerMiddleware();
        $this->registerQueueEventListeners();
        $this->registerWorkflowEventListeners();
        $this->registerAlertEventListeners();

        // Embed tracking data into every queue payload at push time.
        // At this point $payload['data']['command'] is the live job object (not yet serialized),
        // so we can read properties directly — zero unserialize cost.
        BaseQueue::createPayloadUsing(static function ($connection, $queue, $payload) {
            $data = ['pushedAt' => microtime(true)];

            $job = $payload['data']['command'] ?? null;
            if (\is_object($job)) {
                $data['stationJobId'] = $job->stationJobId ?? null;
                $data['stationBatchId'] = property_exists($job, 'batchId') ? $job->batchId : null;
                $data['stationTags'] = method_exists($job, 'tags') ? $job->tags() : [];
                $data['stationWorkflowInstanceId'] = $job->stationWorkflowInstanceId ?? null;
                $data['stationWorkflowStepName'] = $job->stationWorkflowStepName ?? null;
            }

            return $data;
        });

        // Flush buffered metrics when the application terminates
        $this->app->terminating(function (): void {
            try {
                $this->app->make(MetricsCollectorInterface::class)->flush();
            } catch (Throwable) {
            }
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            JobManager::class,
            BatchManager::class,
            MetricsCollector::class,
            WorkerSupervisor::class,
            CheckpointManager::class,
            StuckJobDetector::class,
            JobResumer::class,
            HealthChecker::class,
            WorkflowManager::class,
            AutoScaler::class,
            TelemetryManager::class,
            JobRepositoryInterface::class,
            BatchRepositoryInterface::class,
            CheckpointRepositoryInterface::class,
            MetricsRepositoryInterface::class,
            SupervisorRepositoryInterface::class,
            WorkerRepositoryInterface::class,
            StationQueueManager::class,
            'station',
            'station.batch',
            'station.metrics',
            'station.supervisor',
            'station.checkpoints',
            'station.stuck_detector',
            'station.resumer',
            'station.health',
            'station.queues',
            'station.workflows',
            'station.scaler',
            'station.telemetry',
            AlertRepositoryInterface::class,
            AlertChannelRepositoryInterface::class,
            AlertManager::class,
            'station.alerts',
        ];
    }

    /**
     * Register repository bindings.
     */
    protected function registerRepositories(): void
    {
        $this->app->singleton(JobRepositoryInterface::class, static function (Application $app): JobRepositoryInterface {
            $config = $app['config']['station.storage'];

            return new DatabaseJobRepository(
                $app['db']->connection($config['database']['connection']),
                $config['database']['table_prefix'],
            );
        });

        $this->app->singleton(BatchRepositoryInterface::class, static function (Application $app): BatchRepositoryInterface {
            $config = $app['config']['station.storage'];

            return new DatabaseBatchRepository(
                $app['db']->connection($config['database']['connection']),
                $config['database']['table_prefix'],
            );
        });

        $this->app->singleton(CheckpointRepositoryInterface::class, static function (Application $app): CheckpointRepositoryInterface {
            $config = $app['config']['station'];

            return new DatabaseCheckpointRepository(
                $app['db']->connection($config['storage']['database']['connection']),
                $config['checkpoints']['table'],
                $config['checkpoints']['encrypt'],
                $app['encrypter'],
            );
        });

        $this->app->singleton(MetricsRepositoryInterface::class, static function (Application $app): MetricsRepositoryInterface {
            $config = $app['config']['station.storage'];

            return new DatabaseMetricsRepository(
                $app['db']->connection($config['database']['connection']),
                $config['database']['table_prefix'],
            );
        });

        $this->app->singleton(SupervisorRepositoryInterface::class, static function (Application $app): SupervisorRepositoryInterface {
            $config = $app['config']['station.storage'];

            return new DatabaseSupervisorRepository(
                $app['db']->connection($config['database']['connection']),
                $config['database']['table_prefix'],
            );
        });

        $this->app->singleton(WorkerRepositoryInterface::class, static function (Application $app): WorkerRepositoryInterface {
            $config = $app['config']['station.storage'];

            return new DatabaseWorkerRepository(
                $app['db']->connection($config['database']['connection']),
                $config['database']['table_prefix'],
            );
        });
    }

    /**
     * Register core services.
     */
    protected function registerCoreServices(): void
    {
        $this->app->singleton(JobManager::class, static fn(Application $app): JobManager => new JobManager(
            $app[JobRepositoryInterface::class],
            $app['queue'],
            $app['events'],
            $app['config']['station'],
        ));

        $this->app->singleton(BatchManager::class, static fn(Application $app): BatchManager => new BatchManager(
            $app[BatchRepositoryInterface::class],
            $app['events'],
            $app['config']['station.batches'],
        ));

        $this->app->singleton(MetricsCollector::class, static fn(Application $app): MetricsCollector => new MetricsCollector(
            $app[MetricsRepositoryInterface::class],
            $app['config']['station.monitoring'],
        ));

        $this->app->singleton(WorkerSupervisor::class, static function (Application $app): WorkerSupervisor {
            $supervisor = new WorkerSupervisor(
                $app['events'],
                $app['config']['station'],
            );

            if ($app['config']['station.scaling.enabled'] ?? false) {
                $supervisor->setAutoScaler($app->make(AutoScaler::class));
            }

            return $supervisor;
        });

        $this->app->singleton(StationQueueManager::class, static fn(Application $app): StationQueueManager => new StationQueueManager($app['queue']));

        $this->app->singleton(ProcessManager::class, static fn(Application $app): ProcessManager => new ProcessManager(
            $app['config']['station.process_management'] ?? [],
        ));

        $this->app->singleton(DriverInfoCollector::class, static fn(Application $app): DriverInfoCollector => new DriverInfoCollector(
            $app['queue'],
        ));

        $this->app->alias(JobManager::class, 'station');
        $this->app->alias(BatchManager::class, 'station.batch');
        $this->app->alias(MetricsCollector::class, 'station.metrics');
        $this->app->alias(WorkerSupervisor::class, 'station.supervisor');
        $this->app->alias(StationQueueManager::class, 'station.queues');

        // Interface bindings (alias to singleton)
        $this->app->alias(JobManager::class, JobManagerInterface::class);
        $this->app->alias(MetricsCollector::class, MetricsCollectorInterface::class);
        $this->app->alias(WorkerSupervisor::class, WorkerSupervisorInterface::class);
    }

    /**
     * Register recovery services.
     */
    protected function registerRecoveryServices(): void
    {
        $this->app->singleton(CheckpointManager::class, static fn(Application $app): CheckpointManager => new CheckpointManager(
            $app[CheckpointRepositoryInterface::class],
            $app['config']['station.checkpoints'],
        ));

        $this->app->singleton(StuckJobDetector::class, static fn(Application $app): StuckJobDetector => new StuckJobDetector(
            $app[JobRepositoryInterface::class],
            $app['config']['station.stuck_detection'],
        ));

        $this->app->singleton(JobResumer::class, static fn(Application $app): JobResumer => new JobResumer(
            $app[JobManager::class],
            $app[JobRepositoryInterface::class],
            $app[CheckpointManager::class],
            $app['events'],
            $app['config']['station.recovery'],
            $app[HealthChecker::class],
        ));

        $this->app->singleton(HealthChecker::class, static fn(Application $app): HealthChecker => new HealthChecker(
            $app['db'],
            $app['queue'],
            $app['config']['station.health'],
        ));

        $this->app->alias(CheckpointManager::class, 'station.checkpoints');
        $this->app->alias(StuckJobDetector::class, 'station.stuck_detector');
        $this->app->alias(JobResumer::class, 'station.resumer');
        $this->app->alias(HealthChecker::class, 'station.health');

        // Interface bindings (alias to singleton)
        $this->app->alias(CheckpointManager::class, CheckpointManagerInterface::class);
        $this->app->alias(HealthChecker::class, HealthCheckerInterface::class);
        $this->app->alias(StuckJobDetector::class, StuckJobDetectorInterface::class);
        $this->app->alias(JobResumer::class, JobResumerInterface::class);
    }

    /**
     * Register workflow services.
     */
    protected function registerWorkflows(): void
    {
        $this->app->singleton(WorkflowManager::class, static fn(Application $app): WorkflowManager => new WorkflowManager(
            $app['events'],
            $app['config']['station.workflows'] ?? [],
        ));

        $this->app->alias(WorkflowManager::class, 'station.workflows');
    }

    /**
     * Register auto-scaling services.
     */
    protected function registerScaling(): void
    {
        $this->app->singleton(AutoScaler::class, static fn(Application $app): AutoScaler => new AutoScaler(
            $app[MetricsRepositoryInterface::class],
            $app['events'],
            $app['config']['station.scaling'] ?? [],
        ));

        $this->app->alias(AutoScaler::class, 'station.scaler');
    }

    /**
     * Register telemetry services.
     */
    protected function registerTelemetry(): void
    {
        $this->app->singleton(TelemetryManager::class, static fn(Application $app): TelemetryManager => new TelemetryManager(
            $app['events'],
            $app['config']['station.telemetry'] ?? [],
        ));

        $this->app->alias(TelemetryManager::class, 'station.telemetry');
    }

    /**
     * Register alert services.
     */
    protected function registerAlerts(): void
    {
        $this->app->singleton(AlertRepositoryInterface::class, static function (Application $app): AlertRepositoryInterface {
            $config = $app['config']['station.storage'];

            return new DatabaseAlertRepository(
                $app['db']->connection($config['database']['connection']),
                $config['database']['table_prefix'],
            );
        });

        $this->app->singleton(AlertChannelRepositoryInterface::class, static function (Application $app): AlertChannelRepositoryInterface {
            $config = $app['config']['station.storage'];

            return new DatabaseAlertChannelRepository(
                $app['db']->connection($config['database']['connection']),
                $config['database']['table_prefix'],
            );
        });

        $this->app->singleton(AlertManager::class, static function (Application $app): AlertManager {
            $manager = new AlertManager(
                $app[AlertRepositoryInterface::class],
                $app[AlertChannelRepositoryInterface::class],
                $app['events'],
                $app['config']['station.alerts'] ?? [],
            );

            $manager->registerEvaluator(
                AlertType::HighFailureRate,
                new HighFailureRateEvaluator($app[MetricsCollectorInterface::class]),
            );

            $manager->registerEvaluator(
                AlertType::QueueBackup,
                new QueueBackupEvaluator($app[MetricsCollectorInterface::class]),
            );

            $manager->registerEvaluator(
                AlertType::StuckJobs,
                new StuckJobsEvaluator($app[StuckJobDetectorInterface::class]),
            );

            $manager->registerEvaluator(
                AlertType::WorkerDown,
                new WorkerDownEvaluator($app[WorkerRepositoryInterface::class]),
            );

            return $manager;
        });

        $this->app->alias(AlertManager::class, 'station.alerts');

        // Register custom notification channels
        NotificationManager::extend('station-slack', static fn(Application $app): StationSlackChannel => new StationSlackChannel());
        NotificationManager::extend('station-log', static fn(Application $app): StationLogChannel => new StationLogChannel());
        NotificationManager::extend('station-discord', static fn(Application $app): StationDiscordChannel => new StationDiscordChannel());
        NotificationManager::extend('station-teams', static fn(Application $app): StationTeamsChannel => new StationTeamsChannel());
        NotificationManager::extend('station-google-chat', static fn(Application $app): StationGoogleChatChannel => new StationGoogleChatChannel());
        NotificationManager::extend('station-webhook', static fn(Application $app): StationWebhookChannel => new StationWebhookChannel());
    }

    /**
     * Register alert event listeners for reactive evaluation.
     */
    protected function registerAlertEventListeners(): void
    {
        if (!config('station.alerts.enabled', false)) {
            return;
        }

        $events = $this->app->make('events');

        $events->listen(JobFailed::class, function (): void {
            try {
                $this->app->make(AlertManager::class)->evaluateType(AlertType::HighFailureRate);
            } catch (Throwable) {
            }
        });

        $events->listen(WorkerStopped::class, function (): void {
            try {
                $this->app->make(AlertManager::class)->evaluateType(AlertType::WorkerDown);
            } catch (Throwable) {
            }
        });
    }

    /**
     * Register queue connectors for all supported drivers.
     */
    protected function registerQueueConnector(): void
    {
        $this->app->resolving(QueueManager::class, function (QueueManager $manager): void {
            // RabbitMQ connector
            $manager->addConnector('rabbitmq', static fn(): RabbitMQConnector => new RabbitMQConnector());

            // Station alias for RabbitMQ (default driver)
            $manager->addConnector('station', static fn(): RabbitMQConnector => new RabbitMQConnector());

            // Redis connector (needs $this->app, so cannot be static)
            $manager->addConnector('station-redis', fn(): RedisConnector => new RedisConnector($this->app->make('redis')));

            // Amazon SQS connector
            $manager->addConnector('station-sqs', static fn(): SqsConnector => new SqsConnector());

            // Beanstalkd connector
            $manager->addConnector('station-beanstalkd', static fn(): BeanstalkdConnector => new BeanstalkdConnector());

            // Apache Kafka connector
            $manager->addConnector('station-kafka', static fn(): KafkaConnector => new KafkaConnector());
        });
    }

    /**
     * Register queue event listeners to track Laravel queue jobs.
     */
    protected function registerQueueEventListeners(): void
    {
        if (!config('station.tracking.enabled', true)) {
            return;
        }

        $events = $this->app->make('events');

        // Track when jobs are dispatched
        $events->listen(JobQueued::class, function ($event): void {
            try {
                $payload = $event->payload();

                // Skip if already tracked by JobManager::dispatch() (prevents double-tracking)
                if (!empty($payload['stationJobId'])) {
                    return;
                }

                $repository = $this->app->make(JobRepositoryInterface::class);

                $repository->trackQueued(
                    $payload['uuid'] ?? $payload['id'] ?? uniqid('job_', true),
                    $payload['displayName'] ?? 'Unknown',
                    $event->queue ?? 'default',
                    $event->connectionName ?? config('queue.default'),
                    $payload,
                    $payload['stationBatchId'] ?? $this->extractBatchId($payload),
                    $payload['stationTags'] ?? [],
                );
            } catch (Throwable $e) {
                logger()->debug('Station: Failed to track queued job', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        // Track when jobs start processing
        $events->listen(JobProcessing::class, function ($event): void {
            try {
                $repository = $this->app->make(JobRepositoryInterface::class);
                $payload = $event->job->payload();
                $jobId = $payload['uuid'] ?? $event->job->getJobId();
                $stationJobId = $payload['stationJobId'] ?? $this->extractStationJobId($payload);

                $trackingId = $stationJobId ?? $jobId;

                $repository->trackProcessing(
                    $trackingId,
                    $event->job->getQueue() ?? 'default',
                );

                // Store start time in process-local static array (no cache round-trip)
                self::$jobStartTimes[$trackingId] = microtime(true);

                $this->app->make('events')->dispatch(new JobStarted(
                    $trackingId,
                    $payload['displayName'] ?? $event->job->resolveName(),
                    $event->job->getQueue() ?? 'default',
                    $event->connectionName ?? config('queue.default'),
                ));
            } catch (Throwable) {
            }
        });

        // Track when jobs complete successfully
        $events->listen(JobProcessed::class, function ($event): void {
            $payload = $event->job->payload();
            $jobId = $payload['uuid'] ?? $event->job->getJobId();
            $stationJobId = $payload['stationJobId'] ?? $this->extractStationJobId($payload);

            $trackingId = $stationJobId ?? $jobId;

            // Calculate processing time from process-local start time
            $processingTimeMs = 0;
            $waitTimeMs = 0;

            $startTime = self::$jobStartTimes[$trackingId] ?? null;
            unset(self::$jobStartTimes[$trackingId]);

            if ($startTime) {
                $processingTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            }

            $pushedAt = $payload['pushedAt'] ?? null;
            if ($pushedAt && $startTime) {
                $waitTimeMs = max(0, (int) (($startTime - $pushedAt) * 1000));
            }

            // Wrap all DB writes in a single transaction to reduce fsyncs
            try {
                $dbConnection = $this->app->make('db')->connection(
                    config('station.storage.database.connection'),
                );

                $dbConnection->transaction(function () use ($trackingId, $payload): void {
                    $repository = $this->app->make(JobRepositoryInterface::class);
                    $repository->trackCompleted($trackingId);

                    $batchId = $payload['stationBatchId'] ?? $this->extractBatchId($payload);
                    if ($batchId !== null) {
                        $this->app->make(BatchManager::class)->recordJobCompletion($batchId);
                    }
                });
            } catch (Throwable $e) {
                logger()->debug('Station: Failed to track completed job', ['error' => $e->getMessage()]);
            }

            // Metrics are buffered in memory — no DB write here, no transaction needed
            try {
                $metrics = $this->app->make(MetricsCollectorInterface::class);
                $metrics->recordJobCompletion(
                    $event->job->getQueue() ?? 'default',
                    $processingTimeMs,
                    $waitTimeMs,
                    memory_get_peak_usage(true),
                    $event->connectionName ?? config('queue.default'),
                );
            } catch (Throwable $e) {
                logger()->debug('Station: Failed to record metrics', ['error' => $e->getMessage()]);
            }
        });

        // Track when jobs fail
        $events->listen(JobFailed::class, function ($event): void {
            $payload = $event->job->payload();
            $jobId = $payload['uuid'] ?? $event->job->getJobId();
            $stationJobId = $payload['stationJobId'] ?? $this->extractStationJobId($payload);

            $trackingId = $stationJobId ?? $jobId;

            // Clean up process-local start time (fixes cache leak)
            unset(self::$jobStartTimes[$trackingId]);

            $failContext = [
                'job_class' => (string) ($payload['displayName'] ?? $event->job->resolveName()),
                'queue' => (string) ($event->job->getQueue() ?? 'default'),
                'connection' => (string) ($event->connectionName ?? config('queue.default')),
                'payload' => json_encode($payload) ?: '',
                'attempts' => (int) $event->job->attempts(),
                'batch_id' => isset($payload['stationBatchId']) ? (string) $payload['stationBatchId'] : $this->extractBatchId($payload),
                'tags' => \is_array($payload['stationTags'] ?? null) ? $payload['stationTags'] : [],
            ];

            // Wrap all DB writes in a single transaction to reduce fsyncs
            try {
                $dbConnection = $this->app->make('db')->connection(
                    config('station.storage.database.connection'),
                );

                $dbConnection->transaction(function () use ($trackingId, $event, $payload, $failContext): void {
                    $repository = $this->app->make(JobRepositoryInterface::class);

                    $repository->trackFailed(
                        $trackingId,
                        $event->exception?->getMessage() ?? 'Unknown error',
                        $failContext,
                    );

                    $batchId = $payload['stationBatchId'] ?? $this->extractBatchId($payload);
                    if ($batchId !== null) {
                        $this->app->make(BatchManager::class)->recordJobFailure($batchId, $trackingId);
                    }
                });
            } catch (Throwable) {
            }

            // Metrics are buffered in memory — no DB write here, no transaction needed
            try {
                $metrics = $this->app->make(MetricsCollectorInterface::class);
                $metrics->recordJobFailure(
                    $event->job->getQueue() ?? 'default',
                    $event->connectionName ?? config('queue.default'),
                );
            } catch (Throwable) {
            }
        });
    }

    /**
     * Register workflow event listeners for metrics recording.
     */
    protected function registerWorkflowEventListeners(): void
    {
        if (!config('station.tracking.enabled', true)) {
            return;
        }

        $events = $this->app->make('events');

        $events->listen(WorkflowStepCompleted::class, function ($event): void {
            try {
                $metrics = $this->app->make(MetricsCollectorInterface::class);
                $queue = 'workflow:' . $event->instance->getDefinitionName();
                $metrics->recordJobCompletion($queue, 0, 0, memory_get_peak_usage(true));
            } catch (Throwable) {
            }
        });

        $events->listen(WorkflowFailed::class, function ($event): void {
            try {
                $metrics = $this->app->make(MetricsCollectorInterface::class);
                $queue = 'workflow:' . $event->instance->getDefinitionName();
                $metrics->recordJobFailure($queue);
            } catch (Throwable) {
            }
        });
    }

    /**
     * Register artisan commands.
     */
    protected function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            WorkCommand::class,
            StatusCommand::class,
            PauseCommand::class,
            ResumeCommand::class,
            TerminateCommand::class,
            RecoverCommand::class,
            RetryCommand::class,
            FlushCommand::class,
            FailedCommand::class,
            PruneCommand::class,
            HealthCommand::class,
            PublishSupervisorCommand::class,
            InstallCommand::class,
            AlertsCheckCommand::class,
        ]);
    }

    /**
     * Register routes.
     */
    protected function registerRoutes(): void
    {
        if (!config('station.dashboard.enabled')) {
            return;
        }

        if (class_exists(Inertia::class)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        if (config('station.api.enabled')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }
    }

    /**
     * Register migrations.
     */
    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Register publishing.
     */
    protected function registerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/station.php' => config_path('station.php'),
        ], 'station-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'station-migrations');

        $this->publishes([
            __DIR__ . '/../public/vendor/station' => public_path('vendor/station'),
        ], 'station-assets');

        $this->publishes([
            __DIR__ . '/../config/station.php' => config_path('station.php'),
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'station');
    }

    /**
     * Register middleware.
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app->make('router');

        $router->aliasMiddleware('station.auth', Authorize::class);
        $router->aliasMiddleware('station.security', SecurityHeaders::class);
        $router->aliasMiddleware('station.api.auth', ValidateApiToken::class);

        RateLimiter::for('station', static function (Request $request) {
            $limit = (int) config('station.api.rate_limit', 240);

            return Limit::perMinute($limit)->by($request->ip());
        });
    }

    /**
     * Extract the Station job ID from a queue payload.
     *
     * Station tags dispatched jobs with a stationJobId property that gets
     * serialized into the queue payload command.
     */
    private function extractStationJobId(array $payload): ?string
    {
        try {
            $command = $payload['data']['command'] ?? null;

            if ($command === null) {
                return null;
            }

            $job = \is_string($command) ? unserialize($command) : $command;

            return $job->stationJobId ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract the batch ID from a queue payload.
     *
     * Jobs using the Batchable trait have a batchId property that gets
     * serialized into the queue payload command.
     */
    private function extractBatchId(array $payload): ?string
    {
        try {
            $command = $payload['data']['command'] ?? null;

            if ($command === null) {
                return null;
            }

            $job = \is_string($command) ? unserialize($command) : $command;

            // Check for Batchable trait's batchId property
            if (property_exists($job, 'batchId') && $job->batchId !== null) {
                return $job->batchId;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
