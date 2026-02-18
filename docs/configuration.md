# Configuration & Environment Variables

---

## Configuration Reference

The full `config/station.php` is published via `php artisan vendor:publish`. Below is a summary of every section.

| Section | Key | Description |
|---|---|---|
| **Driver** | `default` | Default queue driver (`rabbitmq`, `redis`, `sqs`, `beanstalkd`, `kafka`) |
| **Connections** | `connections.*` | Per-driver connection settings (see [Queue Drivers](drivers.md)) |
| **Delayed** | `delayed` | Delayed message exchange config (RabbitMQ plugin) |
| **Dead Letter** | `dead_letter` | DLQ exchange, TTL (7 days), max size (10,000) |
| **Supervisors** | `supervisors.*` | Worker pools: queues, processes, tries, timeout, memory, balance strategy |
| **Balancing** | `balancing` | Auto-balance cooldown, max shift, load threshold, metric weights |
| **Backpressure** | `backpressure` | Queue size limits and overflow behavior |
| **Tracking** | `tracking` | Enable/disable automatic job tracking |
| **Storage** | `storage` | Storage driver (database/redis), table prefix, retention periods |
| **Dashboard** | `dashboard` | Path, domain, middleware, theme, refresh interval, driver URLs, realtime config |
| **API** | `api` | API prefix, auth method, token, rate limit |
| **Process Management** | `process_management` | Enable start/stop workers from dashboard (requires POSIX) |
| **Monitoring** | `monitoring` | Metrics collection toggle and sample rate |
| **Alerts** | `alerts` | Alert channels (Slack, Discord, Teams, Google Chat, email, webhook, log) and rules |
| **Recovery** | `recovery` | Stuck job timeout, health check interval, strategies, heartbeat |
| **Stuck Detection** | `stuck_detection` | Scoring weights, thresholds, confirmation checks |
| **Health** | `health` | Health check endpoint, per-service checks and thresholds |
| **Checkpoints** | `checkpoints` | Checkpoint storage, auto-save interval, encryption, compression |
| **Tags** | `tags` | Tag limits (10 per job, 100 chars), auto-tagging |
| **Unique Jobs** | `unique_jobs` | Unique job driver, lock timeout, release on failure |
| **Encryption** | `encryption` | Encryption key and cipher (AES-256-GCM) |
| **Rate Limiting** | `rate_limiting` | Rate limit driver, on-limit behavior, release delay |
| **Maintenance** | `maintenance` | Maintenance mode, quiet hours, notification channels |
| **Shutdown** | `shutdown` | Graceful shutdown timeout and signals |
| **Logging** | `logging` | Log channel, structured logging, per-event log levels |
| **Audit** | `audit` | Audit driver, retention (90 days) |
| **Batches** | `batches` | Batch table name and pruning retention |
| **Masking** | `masking` | Sensitive fields and replacement string |
| **Security Headers** | `security_headers` | Enable/disable security headers on dashboard |
| **Circuit Breaker** | `circuit_breaker` | Per-service thresholds, recovery timeout, fallback strategy |
| **Scaling** | `scaling` | Auto-scaling policies: min/max workers, scale thresholds, strategies |
| **Telemetry** | `telemetry` | OpenTelemetry tracing and metrics export (OTLP, Jaeger, Zipkin) |
| **Workflows** | `workflows` | Workflow table, retention, execution limits, context size |
| **Coexistence** | `coexistence` | Run Horizon and Station side-by-side during migration |
| **Middleware** | `middleware` | Global and per-queue job middleware |
| **Silenced** | `silenced` | Job classes excluded from dashboard tracking (e.g., heartbeat jobs) |

### Storage Retention Defaults

| Data | Retention |
|---|---|
| Completed jobs | 24 hours |
| Failed jobs | 7 days |
| Metrics | 7 days |
| Checkpoints | 24 hours |
| Completed batches | 24 hours |
| Cancelled batches | 72 hours |
| Failed batches | 7 days |
| Audit logs | 90 days |
| Completed workflows | 24 hours |
| Failed workflows | 7 days |

---

## Environment Variables

### Core

| Variable | Default | Description |
|---|---|---|
| `STATION_DRIVER` | `rabbitmq` | Default queue driver |
| `STATION_TRACKING_ENABLED` | `true` | Enable automatic job tracking |
| `STATION_PROCESSES` | `1` | Number of worker processes |
| `STATION_TRIES` | `3` | Max retry attempts per job |
| `STATION_TIMEOUT` | `60` | Job timeout in seconds |
| `STATION_MEMORY` | `128` | Worker memory limit (MB) |
| `STATION_MAX_TIME` | `0` | Worker max runtime (0 = unlimited) |
| `STATION_MAX_JOBS` | `0` | Worker max jobs (0 = unlimited) |

### Storage

| Variable | Default | Description |
|---|---|---|
| `STATION_STORAGE_DRIVER` | `database` | Storage backend (`database` or `redis`) |
| `STATION_DB_CONNECTION` | *app default* | Database connection for Station tables |
| `STATION_REDIS_CONNECTION` | `default` | Redis connection for Station data |

### Dashboard & API

| Variable | Default | Description |
|---|---|---|
| `STATION_DASHBOARD` | `true` | Enable the web dashboard |
| `STATION_DASHBOARD_PATH` | `station` | URL path for the dashboard |
| `STATION_DOMAIN` | *null* | Restrict dashboard to specific domain |
| `STATION_REALTIME` | `false` | Enable WebSocket real-time updates |
| `STATION_API_AUTH` | `token` | API auth method (`token` or `none`) |
| `STATION_API_TOKEN` | *null* | Bearer token for API access |

### Security

| Variable | Default | Description |
|---|---|---|
| `STATION_ENCRYPTION_KEY` | *null* | Encryption key (falls back to `APP_KEY`) |
| `STATION_MASKING_ENABLED` | `true` | Enable payload masking |
| `STATION_SECURITY_HEADERS` | `true` | Add security headers to dashboard |
| `STATION_CHECKPOINT_ENCRYPT` | `true` | Encrypt checkpoint data |
| `STATION_CHECKPOINT_COMPRESSION` | `false` | Compress checkpoint data |

### Monitoring & Alerting

| Variable | Default | Description |
|---|---|---|
| `STATION_MONITORING` | `true` | Enable metrics collection |
| `STATION_ALERTS_ENABLED` | `false` | Enable alerting |
| `STATION_ALERT_SLACK_WEBHOOK` | *null* | Slack incoming webhook URL |
| `STATION_ALERT_EMAIL` | *null* | Comma-separated alert recipients |
| `STATION_LOG_CHANNEL` | `stack` | Log channel for Station events |
| `STATION_AUDIT_ENABLED` | `true` | Enable audit logging |
| `STATION_AUDIT_DRIVER` | `database` | Audit storage driver |
| `STATION_AUDIT_RETENTION_DAYS` | `90` | Days to retain audit logs |

### Recovery & Checkpoints

| Variable | Default | Description |
|---|---|---|
| `STATION_CHECKPOINT_ENABLED` | `true` | Enable job checkpointing |
| `STATION_CHECKPOINT_STORAGE` | `database` | Checkpoint storage backend |

### Telemetry

| Variable | Default | Description |
|---|---|---|
| `STATION_TELEMETRY_ENABLED` | `false` | Enable OpenTelemetry |
| `STATION_TELEMETRY_SERVICE` | `station` | Service name for traces |
| `STATION_TRACING_DRIVER` | `internal` | Tracing driver (`internal` or `opentelemetry`) |
| `STATION_TRACING_SAMPLE_RATE` | `1.0` | Trace sample rate (0.0 to 1.0) |
| `STATION_METRICS_DRIVER` | `internal` | Metrics driver |
| `STATION_PROMETHEUS_ENDPOINT` | `/station/metrics` | Prometheus scrape endpoint |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | *null* | OTLP collector endpoint |
| `JAEGER_ENDPOINT` | *null* | Jaeger collector endpoint |
| `ZIPKIN_ENDPOINT` | *null* | Zipkin collector endpoint |

### Other

| Variable | Default | Description |
|---|---|---|
| `STATION_PROCESS_MANAGEMENT` | `false` | Enable process management (start/stop workers from dashboard) |
| `STATION_SCALING_ENABLED` | `false` | Enable auto-scaling |
| `STATION_WORKFLOWS_ENABLED` | `true` | Enable workflow engine |
| `STATION_UNIQUE_DRIVER` | `cache` | Unique job lock driver |
| `STATION_RATE_LIMIT_DRIVER` | `cache` | Rate limiting driver |

### RabbitMQ

| Variable | Default | Description |
|---|---|---|
| `RABBITMQ_HOST` | `rabbitmq` | RabbitMQ server host |
| `RABBITMQ_PORT` | `5672` | RabbitMQ server port |
| `RABBITMQ_USER` | *null* | RabbitMQ username |
| `RABBITMQ_PASSWORD` | *null* | RabbitMQ password |
| `RABBITMQ_VHOST` | `station` | RabbitMQ virtual host |
| `RABBITMQ_EXCHANGE` | `station.direct` | Exchange name |
| `RABBITMQ_EXCHANGE_TYPE` | `direct` | Exchange type |
| `RABBITMQ_SSL_CAFILE` | *null* | CA certificate file path |
| `RABBITMQ_SSL_LOCALCERT` | *null* | Client certificate file path |
| `RABBITMQ_SSL_LOCALKEY` | *null* | Client key file path |
| `RABBITMQ_SSL_VERIFY_PEER` | `true` | Verify server certificate |
| `RABBITMQ_SSL_PASSPHRASE` | *null* | Certificate passphrase |

### Redis

| Variable | Default | Description |
|---|---|---|
| `STATION_REDIS_CONNECTION` | `default` | Laravel Redis connection name |
| `STATION_REDIS_QUEUE` | `default` | Default queue name |
| `STATION_REDIS_RETRY_AFTER` | `90` | Seconds before job is retried |
| `STATION_REDIS_BLOCK_FOR` | `5` | Seconds to block waiting for jobs |

### Amazon SQS

| Variable | Default | Description |
|---|---|---|
| `AWS_ACCESS_KEY_ID` | *null* | AWS access key |
| `AWS_SECRET_ACCESS_KEY` | *null* | AWS secret key |
| `AWS_SESSION_TOKEN` | *null* | AWS session token (temporary credentials) |
| `AWS_DEFAULT_REGION` | `us-east-1` | AWS region |
| `SQS_PREFIX` | *null* | SQS queue URL prefix |
| `SQS_SUFFIX` | *null* | SQS queue URL suffix |
| `SQS_QUEUE` | `default` | Default queue name |
| `SQS_ENDPOINT` | *null* | Custom endpoint (for LocalStack) |
| `SQS_WAIT_TIME` | `20` | Long polling wait time (seconds) |
| `SQS_VISIBILITY_TIMEOUT` | `30` | Message visibility timeout (seconds) |
| `SQS_FIFO` | `false` | Use FIFO queue |
| `SQS_MESSAGE_GROUP_ID` | `default` | FIFO message group ID |
| `SQS_CONTENT_DEDUP` | `false` | Enable content-based deduplication |

### Beanstalkd

| Variable | Default | Description |
|---|---|---|
| `BEANSTALKD_HOST` | `127.0.0.1` | Server host |
| `BEANSTALKD_PORT` | `11300` | Server port |
| `BEANSTALKD_QUEUE` | `default` | Default tube name |
| `BEANSTALKD_TIMEOUT` | `10` | Connection timeout (seconds) |
| `BEANSTALKD_TTR` | `60` | Time-to-run per job (seconds) |
| `BEANSTALKD_RESERVE_TIMEOUT` | `5` | Reserve timeout (seconds) |
| `BEANSTALKD_PRIORITY` | `1024` | Default job priority |
| `BEANSTALKD_RETRY_DELAY` | `60` | Delay before retry (seconds) |

### Apache Kafka

| Variable | Default | Description |
|---|---|---|
| `KAFKA_BROKERS` | `127.0.0.1:9092` | Comma-separated broker list |
| `KAFKA_TOPIC` | `default` | Default topic name |
| `KAFKA_GROUP_ID` | `station` | Consumer group ID |
| `KAFKA_AUTO_OFFSET_RESET` | `earliest` | Where to start reading (`earliest` or `latest`) |
| `KAFKA_AUTO_COMMIT` | `false` | Auto-commit offsets |
| `KAFKA_CONSUME_TIMEOUT` | `5000` | Consumer poll timeout (ms) |
| `KAFKA_FLUSH_TIMEOUT` | `10000` | Producer flush timeout (ms) |
| `KAFKA_SESSION_TIMEOUT` | `30000` | Consumer session timeout (ms) |
| `KAFKA_HEARTBEAT_INTERVAL` | `3000` | Consumer heartbeat interval (ms) |
| `KAFKA_MESSAGE_TIMEOUT` | `30000` | Message delivery timeout (ms) |
| `KAFKA_ACKS` | `-1` | Acknowledgment level (-1 = all replicas) |
| `KAFKA_IDEMPOTENT` | `false` | Enable idempotent producer |
| `KAFKA_SECURITY_PROTOCOL` | *null* | Security protocol (`SASL_SSL`, `SASL_PLAINTEXT`) |
| `KAFKA_SASL_MECHANISMS` | *null* | SASL mechanism (`PLAIN`, `SCRAM-SHA-256`) |
| `KAFKA_SASL_USERNAME` | *null* | SASL username |
| `KAFKA_SASL_PASSWORD` | *null* | SASL password |
