<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection
    |--------------------------------------------------------------------------
    |
    | The default queue driver to use for Station. Supports: 'rabbitmq',
    | 'station-redis', 'station-sqs', 'station-beanstalkd', and 'station-kafka'.
    |
    */

    'default' => env('STATION_DRIVER', 'rabbitmq'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Configure your queue driver connections here. Station uses RabbitMQ
    | as its primary driver with support for clustering and SSL.
    |
    */

    'connections' => [
        'rabbitmq' => [
            'driver' => 'rabbitmq',
            'hosts' => [
                [
                    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
                    'port' => (int) env('RABBITMQ_PORT', 5672),
                    'username' => env('RABBITMQ_USER', 'station'),
                    'password' => env('RABBITMQ_PASSWORD', 'station'),
                    'vhost' => env('RABBITMQ_VHOST', 'station'),
                ],
            ],
            'options' => [
                'ssl_options' => [
                    'cafile' => env('RABBITMQ_SSL_CAFILE'),
                    'local_cert' => env('RABBITMQ_SSL_LOCALCERT'),
                    'local_key' => env('RABBITMQ_SSL_LOCALKEY'),
                    'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
                    'passphrase' => env('RABBITMQ_SSL_PASSPHRASE'),
                ],
                'heartbeat' => 60,
                'connection_timeout' => 10.0,
                'read_write_timeout' => 30.0,
            ],
            'exchange' => [
                'name' => env('RABBITMQ_EXCHANGE', 'station.direct'),
                'type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct'),
                'durable' => true,
                'auto_delete' => false,
            ],
        ],

        'redis' => [
            'driver' => 'station-redis',
            'connection' => env('STATION_REDIS_CONNECTION', 'default'),
            'queue' => env('STATION_REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('STATION_REDIS_RETRY_AFTER', 90),
            'block_for' => (int) env('STATION_REDIS_BLOCK_FOR', 5),
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'station-sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'token' => env('AWS_SESSION_TOKEN'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'prefix' => env('SQS_PREFIX', ''),
            'suffix' => env('SQS_SUFFIX'),
            'queue' => env('SQS_QUEUE', 'default'),
            'endpoint' => env('SQS_ENDPOINT'), // For LocalStack
            'wait_time' => (int) env('SQS_WAIT_TIME', 20),
            'visibility_timeout' => (int) env('SQS_VISIBILITY_TIMEOUT', 30),
            'fifo' => (bool) env('SQS_FIFO', false),
            'message_group_id' => env('SQS_MESSAGE_GROUP_ID', 'default'),
            'content_based_deduplication' => (bool) env('SQS_CONTENT_DEDUP', false),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'station-beanstalkd',
            'host' => env('BEANSTALKD_HOST', '127.0.0.1'),
            'port' => (int) env('BEANSTALKD_PORT', 11300),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'timeout' => (int) env('BEANSTALKD_TIMEOUT', 10),
            'ttr' => (int) env('BEANSTALKD_TTR', 60),
            'reserve_timeout' => (int) env('BEANSTALKD_RESERVE_TIMEOUT', 5),
            'priority' => (int) env('BEANSTALKD_PRIORITY', 1024),
            'retry_delay' => (int) env('BEANSTALKD_RETRY_DELAY', 60),
            'after_commit' => false,
        ],

        'kafka' => [
            'driver' => 'station-kafka',
            'brokers' => env('KAFKA_BROKERS', '127.0.0.1:9092'),
            'queue' => env('KAFKA_TOPIC', 'default'),
            'group_id' => env('KAFKA_GROUP_ID', 'station'),
            'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'earliest'),
            'auto_commit' => (bool) env('KAFKA_AUTO_COMMIT', false),
            'consume_timeout' => (int) env('KAFKA_CONSUME_TIMEOUT', 5000),
            'flush_timeout' => (int) env('KAFKA_FLUSH_TIMEOUT', 10000),
            'session_timeout' => (int) env('KAFKA_SESSION_TIMEOUT', 30000),
            'heartbeat_interval' => (int) env('KAFKA_HEARTBEAT_INTERVAL', 3000),
            'message_timeout' => (int) env('KAFKA_MESSAGE_TIMEOUT', 30000),
            'acks' => (int) env('KAFKA_ACKS', -1), // -1 = all replicas
            'idempotent' => (bool) env('KAFKA_IDEMPOTENT', false),
            'security_protocol' => env('KAFKA_SECURITY_PROTOCOL'), // SASL_SSL, SASL_PLAINTEXT
            'sasl_mechanisms' => env('KAFKA_SASL_MECHANISMS'), // PLAIN, SCRAM-SHA-256, etc.
            'sasl_username' => env('KAFKA_SASL_USERNAME'),
            'sasl_password' => env('KAFKA_SASL_PASSWORD'),
            'after_commit' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delayed Jobs (requires rabbitmq_delayed_message_exchange plugin)
    |--------------------------------------------------------------------------
    */

    'delayed' => [
        'exchange' => 'station.delayed',
        'exchange_type' => 'x-delayed-message',
        'max_delay' => 86400000, // Maximum delay in ms (24 hours)
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Queues
    |--------------------------------------------------------------------------
    */

    'dead_letter' => [
        'enabled' => true,
        'exchange' => 'station.dlx',
        'ttl' => 604800000, // 7 days in ms
        'max_size' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisors
    |--------------------------------------------------------------------------
    |
    | Define worker supervisor configurations. Each supervisor manages
    | a pool of worker processes for specific queues.
    |
    */

    'supervisors' => [
        'default' => [
            'connection' => 'rabbitmq',
            'queues' => ['default'],
            'balance' => 'auto', // 'auto', 'simple', 'priority'
            'processes' => (int) env('STATION_PROCESSES', 1),
            'tries' => (int) env('STATION_TRIES', 3),
            'timeout' => (int) env('STATION_TIMEOUT', 60),
            'memory' => (int) env('STATION_MEMORY', 128),
            'sleep' => 3,
            'rest' => 0,
            'max_time' => (int) env('STATION_MAX_TIME', 0),
            'max_jobs' => (int) env('STATION_MAX_JOBS', 0),
            'force' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Balancing Configuration
    |--------------------------------------------------------------------------
    */

    'balancing' => [
        'strategy' => 'auto',
        'auto' => [
            'cooldown' => 3,
            'max_shift' => 2,
            'min_workers_per_queue' => 1,
            'load_threshold' => 0.8,
        ],
        'metrics' => [
            'queue_size_weight' => 0.6,
            'throughput_weight' => 0.3,
            'failure_rate_weight' => 0.1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backpressure
    |--------------------------------------------------------------------------
    */

    'backpressure' => [
        'enabled' => false,
        'default_max_size' => 100000,
        'default_on_full' => 'reject', // 'reject', 'drop_oldest', 'overflow_to_dlq'
        'limits' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Tracking
    |--------------------------------------------------------------------------
    |
    | Enable or disable automatic tracking of Laravel queue jobs. When enabled,
    | Station will listen to Laravel's queue events and track all jobs in the
    | Station dashboard, regardless of how they were dispatched.
    |
    */

    'tracking' => [
        'enabled' => env('STATION_TRACKING_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Configure where Station stores job history, metrics, and checkpoints.
    |
    */

    'storage' => [
        'driver' => env('STATION_STORAGE_DRIVER', 'database'),

        'database' => [
            'connection' => env('STATION_DB_CONNECTION'),
            'table_prefix' => 'station_',
        ],

        'redis' => [
            'connection' => env('STATION_REDIS_CONNECTION', 'default'),
            'prefix' => 'station:',
        ],

        'retention' => [
            'completed_jobs' => 24, // hours
            'failed_jobs' => 168, // hours (7 days)
            'metrics' => 168, // hours
            'checkpoints' => 24, // hours
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    'dashboard' => [
        'enabled' => env('STATION_DASHBOARD', true),
        'path' => env('STATION_DASHBOARD_PATH', 'station'),
        'domain' => env('STATION_DOMAIN'),
        'middleware' => ['web', 'auth'],
        'theme' => 'auto', // 'light', 'dark', 'auto'
        'refresh_interval' => 3000, // ms
        'route_prefix' => 'station.',

        'driver_urls' => [
            'rabbitmq' => env('RABBITMQ_DASHBOARD_URL', 'http://localhost:15672'),
            'beanstalkd' => env('BEANSTALKD_DASHBOARD_URL', 'http://localhost:2080'),
            'kafka' => env('KAFKA_DASHBOARD_URL', 'http://localhost:8080'),
        ],

        'realtime' => [
            'enabled' => env('STATION_REALTIME', false),
            'driver' => 'pusher', // 'pusher', 'ably', 'soketi'
        ],

        'authorization' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    */

    'api' => [
        'enabled' => true,
        'prefix' => 'api/station',
        'middleware' => ['api'],
        'auth' => env('STATION_API_AUTH', 'token'), // 'token', 'none'
        'token' => env('STATION_API_TOKEN'),
        'rate_limit' => 240, // requests per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Process Management
    |--------------------------------------------------------------------------
    |
    | Enable process management to start/stop workers and supervisors from
    | the dashboard. Requires POSIX (Linux/macOS). Not supported on Windows.
    |
    */

    'process_management' => [
        'enabled' => env('STATION_PROCESS_MANAGEMENT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    */

    'monitoring' => [
        'enabled' => env('STATION_MONITORING', true),
        'metrics' => [
            'enabled' => true,
            'sample_rate' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    */

    'alerts' => [
        'enabled' => env('STATION_ALERTS_ENABLED', false),

        'channels' => [
            ['name' => 'Default Slack', 'type' => 'slack', 'config' => ['webhook_url' => env('STATION_ALERT_SLACK_WEBHOOK', '')]],
            ['name' => 'Default Email', 'type' => 'email', 'config' => ['recipients' => array_filter(explode(',', env('STATION_ALERT_EMAIL', '')))]],
            ['name' => 'Station Log', 'type' => 'log', 'config' => ['channel' => 'station-alerts']],
            ['name' => 'Default Discord', 'type' => 'discord', 'config' => ['webhook_url' => env('STATION_ALERT_DISCORD_WEBHOOK', '')]],
            ['name' => 'Default Teams', 'type' => 'teams', 'config' => ['webhook_url' => env('STATION_ALERT_TEAMS_WEBHOOK', '')]],
            ['name' => 'Default Google Chat', 'type' => 'google_chat', 'config' => ['webhook_url' => env('STATION_ALERT_GOOGLE_CHAT_WEBHOOK', '')]],
            ['name' => 'Default Webhook', 'type' => 'webhook', 'config' => ['url' => env('STATION_ALERT_WEBHOOK_URL', ''), 'secret' => env('STATION_ALERT_WEBHOOK_SECRET')]],
        ],

        'rules' => [
            'high_failure_rate' => [
                'enabled' => true,
                'condition' => 'failure_rate > 10',
                'window' => 300,
                'channels' => ['slack', 'email'],
                'cooldown' => 900,
            ],
            'queue_backup' => [
                'enabled' => true,
                'condition' => 'queue_size > 10000',
                'queues' => ['high', 'default'],
                'channels' => ['slack'],
                'cooldown' => 600,
            ],
            'stuck_jobs' => [
                'enabled' => true,
                'condition' => 'stuck_count > 0',
                'channels' => ['slack', 'email'],
                'cooldown' => 300,
            ],
            'worker_down' => [
                'enabled' => true,
                'condition' => 'active_workers < 1',
                'channels' => ['slack', 'email'],
                'cooldown' => 60,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    */

    'recovery' => [
        'enabled' => true,
        'stuck_job_timeout' => 900, // 15 minutes
        'health_check_interval' => 30,
        'auto_resume' => true,
        'max_recovery_attempts' => 3,

        'strategies' => [
            'graceful_restart' => true,
            'forced_restart' => true,
            'partial_recovery' => true,
        ],

        'heartbeat' => [
            'enabled' => true,
            'interval' => 30,
            'timeout' => 90,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stuck Job Detection
    |--------------------------------------------------------------------------
    */

    'stuck_detection' => [
        'enabled' => true,

        'thresholds' => [
            'heartbeat_timeout' => 90,
            'runtime_multiplier' => 1.5,
            'memory_threshold' => 0.9,
            'check_interval' => 30,
        ],

        'weights' => [
            'heartbeat' => 0.4,
            'runtime' => 0.3,
            'memory' => 0.15,
            'process_state' => 0.15,
        ],

        'stuck_threshold' => 0.7,

        'confirmation' => [
            'enabled' => true,
            'checks' => 3,
            'interval' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Checks
    |--------------------------------------------------------------------------
    */

    'health' => [
        'enabled' => true,
        'endpoint' => '/station/health',

        'checks' => [
            'rabbitmq' => [
                'enabled' => true,
                'interval' => 10,
                'timeout' => 5,
                'on_failure' => 'reconnect',
            ],
            'database' => [
                'enabled' => true,
                'interval' => 30,
                'timeout' => 5,
                'on_failure' => 'pause',
            ],
            'redis' => [
                'enabled' => false,
                'interval' => 30,
                'on_failure' => 'alert',
            ],
            'disk' => [
                'enabled' => true,
                'path' => storage_path(),
                'warning_threshold' => 90,
                'critical_threshold' => 95,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkpoints
    |--------------------------------------------------------------------------
    */

    'checkpoints' => [
        'enabled' => env('STATION_CHECKPOINT_ENABLED', true),
        'storage' => env('STATION_CHECKPOINT_STORAGE', 'database'),
        'table' => 'station_checkpoints',
        'auto_save_interval' => 60,
        'retention' => 24,
        'encrypt' => env('STATION_CHECKPOINT_ENCRYPT', true),
        'compression' => env('STATION_CHECKPOINT_COMPRESSION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    */

    'tags' => [
        'enabled' => true,
        'max_per_job' => 10,
        'max_length' => 100,
        'index_for_search' => true,

        'auto_tags' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Unique Jobs
    |--------------------------------------------------------------------------
    */

    'unique_jobs' => [
        'enabled' => true,
        'driver' => env('STATION_UNIQUE_DRIVER', 'cache'),
        'lock_timeout' => 3600,
        'release_on_failure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    */

    'encryption' => [
        'enabled' => true,
        'key' => env('STATION_ENCRYPTION_KEY'),
        'cipher' => 'aes-256-gcm',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limiting' => [
        'enabled' => true,
        'driver' => env('STATION_RATE_LIMIT_DRIVER', 'cache'),
        'limits' => [],
        'on_limit' => 'release',
        'release_delay' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode
    |--------------------------------------------------------------------------
    */

    'maintenance' => [
        'enabled' => false,
        'allow_job_completion' => true,
        'timeout' => 300,
        'message' => null,
        'resume_at' => null,
        'paused_queues' => [],
        'notify_on_pause' => true,
        'notify_channels' => ['slack'],

        'quiet_hours' => [
            'enabled' => false,
            'schedule' => [],
            'timezone' => 'UTC',
            'queues' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shutdown
    |--------------------------------------------------------------------------
    */

    'shutdown' => [
        'enabled' => true,
        'timeout' => 30,
        'signals' => ['SIGTERM', 'SIGINT', 'SIGQUIT'],
        'finish_current_job' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'channel' => env('STATION_LOG_CHANNEL', 'stack'),
        'context' => true,
        'structured' => true,
        'levels' => [
            'job_started' => 'debug',
            'job_completed' => 'debug',
            'job_failed' => 'error',
            'job_retried' => 'warning',
            'job_timeout' => 'error',
            'checkpoint_saved' => 'debug',
            'worker_started' => 'info',
            'worker_stopped' => 'info',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */

    'audit' => [
        'enabled' => env('STATION_AUDIT_ENABLED', true),
        'driver' => env('STATION_AUDIT_DRIVER', 'database'),
        'retention_days' => (int) env('STATION_AUDIT_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batches
    |--------------------------------------------------------------------------
    */

    'batches' => [
        'table' => 'station_batches',
        'pruning' => [
            'completed_after' => 24,
            'cancelled_after' => 72,
            'failed_after' => 168,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Masking
    |--------------------------------------------------------------------------
    */

    'masking' => [
        'enabled' => env('STATION_MASKING_ENABLED', true),
        'fields' => [
            'password',
            'secret',
            'token',
            'api_key',
            'credit_card',
            'ssn',
        ],
        'replacement' => '[REDACTED]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    */

    'security_headers' => env('STATION_SECURITY_HEADERS', true),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    */

    'circuit_breaker' => [
        'enabled' => true,

        'services' => [
            'rabbitmq' => [
                'failure_threshold' => 5,
                'success_threshold' => 3,
                'recovery_timeout' => 30,
                'timeout' => 5,
            ],
            'database' => [
                'failure_threshold' => 3,
                'success_threshold' => 2,
                'recovery_timeout' => 15,
            ],
        ],

        'fallback' => [
            'strategy' => 'queue_locally',
            'local_storage' => storage_path('station/fallback'),
            'max_local_jobs' => 10000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Scaling
    |--------------------------------------------------------------------------
    |
    | Configure automatic worker scaling based on queue metrics.
    |
    */

    'scaling' => [
        'enabled' => env('STATION_SCALING_ENABLED', false),

        'policies' => [
            'default' => [
                'min_workers' => 1,
                'max_workers' => 10,
                'cooldown' => 60,
                'scale_up_threshold' => 0.8,
                'scale_down_threshold' => 0.2,
            ],
        ],

        'strategies' => [
            // Queue size based scaling
            'queue_size' => [
                'enabled' => true,
                'high_watermark' => 1000,
                'low_watermark' => 100,
            ],

            // Throughput based scaling
            'throughput' => [
                'enabled' => false,
                'target_jobs_per_minute' => 100,
            ],

            // Wait time based scaling
            'wait_time' => [
                'enabled' => false,
                'max_wait_seconds' => 30,
            ],
        ],

        'metrics_window' => 300, // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry (OpenTelemetry)
    |--------------------------------------------------------------------------
    |
    | Configure distributed tracing and metrics collection.
    |
    */

    'telemetry' => [
        'enabled' => env('STATION_TELEMETRY_ENABLED', false),
        'service_name' => env('STATION_TELEMETRY_SERVICE', 'station'),

        'tracing' => [
            'enabled' => true,
            'driver' => env('STATION_TRACING_DRIVER', 'internal'), // 'internal', 'opentelemetry'
            'sample_rate' => (float) env('STATION_TRACING_SAMPLE_RATE', 1.0),
            'propagation' => 'w3c', // 'w3c', 'b3', 'jaeger'
        ],

        'metrics' => [
            'enabled' => true,
            'driver' => env('STATION_METRICS_DRIVER', 'internal'), // 'internal', 'opentelemetry'
            'export_interval' => 60, // seconds
            'prometheus_endpoint' => env('STATION_PROMETHEUS_ENDPOINT', '/station/metrics'),
        ],

        'exporters' => [
            'otlp' => [
                'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
                'headers' => [],
                'timeout' => 10,
            ],
            'jaeger' => [
                'endpoint' => env('JAEGER_ENDPOINT'),
            ],
            'zipkin' => [
                'endpoint' => env('ZIPKIN_ENDPOINT'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflows
    |--------------------------------------------------------------------------
    |
    | Configure workflow orchestration settings.
    |
    */

    'workflows' => [
        'enabled' => env('STATION_WORKFLOWS_ENABLED', true),
        'table' => 'station_workflows',

        'retention' => [
            'completed_hours' => 24,
            'failed_hours' => 168, // 7 days
        ],

        'execution' => [
            'max_parallel_steps' => 10,
            'step_timeout' => 3600, // 1 hour
            'retry_failed_steps' => true,
            'max_step_retries' => 3,
        ],

        'context' => [
            'max_size' => 65535, // 64KB
            'encrypt' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Coexistence (Horizon Migration)
    |--------------------------------------------------------------------------
    */

    'coexistence' => [
        'enabled' => false,
        'horizon_queues' => [],
        'station_queues' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'global' => [],
        'queues' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Job classes listed here are hidden from the main Jobs page and Dashboard
    | stats. They still appear on the dedicated Silenced page and in Failed
    | Jobs if they fail.
    |
    */

    'silenced' => [
        // App\Jobs\HeartbeatJob::class,
    ],
];
