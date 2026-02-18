# Station

[![CI](https://github.com/ojbaeza/station/workflows/CI/badge.svg)](https://github.com/ojbaeza/station/actions)
[![Coverage](https://codecov.io/gh/ojbaeza/station/branch/main/graph/badge.svg)](https://codecov.io/gh/ojbaeza/station)
[![Latest Version](https://img.shields.io/packagist/v/ojbaeza/station.svg)](https://packagist.org/packages/ojbaeza/station)
[![License](https://img.shields.io/github/license/ojbaeza/station)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/ojbaeza/station.svg)](composer.json)

**A Laravel Horizon alternative with multi-driver support and enhanced job recovery.**

Station replaces Horizon as your queue management and monitoring layer. Your existing Laravel queue code works unchanged — the only difference is running `php artisan station:work` instead of `php artisan horizon`.

Station hooks into Laravel's queue event system to transparently track every job, batch, and workflow through a real-time dashboard.

**Resources:** [Contributing](CONTRIBUTING.md) | [Security Policy](SECURITY.md) | [Changelog](CHANGELOG.md) | [Upgrade Guide](UPGRADE.md)

![Dashboard](docs/screenshots/dashboard.png)

![Dashboard Light](docs/screenshots/dashboard-light.png)

---

## What Station Provides

- **Multi-driver support** — RabbitMQ, Redis, SQS, Beanstalkd, Kafka
- **Seamless job tracking** — Every `dispatch()` call is recorded automatically
- **Batch monitoring** — Real-time progress for `Bus::batch()` with integer failure thresholds
- **Workflow engine** — Multi-step DAG workflows with conditions, branching, and pause/resume
- **Job recovery** — Checkpointing for long-running jobs, stuck job detection, automatic recovery
- **Real-time dashboard** — Inertia.js + Vue 3 UI with 3-second auto-refresh
- **Built-in observability** — Prometheus metrics, health checks, multi-channel alerting (Slack, Discord, Teams, email, webhooks)
- **Alerting** — Configurable alert rules with multi-channel notifications (Slack, Discord, Teams, email, webhooks, log)
- **Security** — Job encryption, payload masking, audit logging, gate-based authorization

For a detailed comparison with Horizon, see [Station vs Horizon](docs/architecture.md#station-vs-horizon).

---

## Requirements

- PHP 8.3+ (8.4 recommended)
- Laravel 11.x or 12.x
- ext-pcntl, ext-posix

**Driver-specific:** ext-amqp (RabbitMQ), ext-redis or predis (Redis), aws/aws-sdk-php (SQS), pda/pheanstalk (Beanstalkd), ext-rdkafka (Kafka)

**Dashboard:** inertiajs/inertia-laravel (optional — required only for the web dashboard)

---

## Installation

```bash
composer require ojbaeza/station
php artisan vendor:publish --provider="Station\StationServiceProvider"
php artisan migrate
php artisan station:install
```

Set your queue connection in `.env`:

```bash
QUEUE_CONNECTION=station
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=your-rabbitmq-username
RABBITMQ_PASSWORD=your-secure-password
RABBITMQ_VHOST=/
```

Start the supervisor:

```bash
php artisan station:work
```

Access the dashboard at `/station`.

---

## Jobs

![Jobs](docs/screenshots/jobs.png)

Standard Laravel dispatch is tracked automatically — no code changes needed:

```php
// All of these are tracked by Station
ProcessOrderJob::dispatch($order);
ProcessOrderJob::dispatch($order)->onQueue('high');
ProcessOrderJob::dispatch($order)->delay(now()->addMinutes(5));
```

Station also provides a fluent API with tags and priority:

```php
use Station\Facades\Station;

Station::job(new ProcessOrderJob($order))
    ->onQueue('high')
    ->delay(now()->addMinutes(5))
    ->tags(['orders', 'payments'])
    ->dispatch();
```

Station records processing time, memory usage, attempts, wait time, and status for every job.

---

## Batches

![Batches](docs/screenshots/batches.png)

Station wraps Laravel's native `Bus::batch()` with enhanced tracking. Jobs must use the `Batchable` trait:

```php
use Illuminate\Bus\Batchable;

class ProcessOrderJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Do work...
    }
}
```

Create and monitor batches:

```php
use Station\Facades\Batch;

$batch = Batch::create(
    jobs: [
        new ProcessOrderJob($order1),
        new ProcessOrderJob($order2),
        new ProcessOrderJob($order3),
    ],
    name: 'Daily Orders',
    allowedFailures: 2,  // Allow up to 2 jobs to fail (integer, not boolean)
);

// Check progress
$batch = Batch::find($batch->id);
$batch->progress();    // Percentage (0-100)

// Operations
Batch::cancel($batch->id);         // Cancel remaining jobs
Batch::retryFailed($batch->id);    // Retry failed jobs
```

For details on the overlay table strategy and atomic counter tracking, see [Batches Architecture](docs/architecture.md#batches).

---

## Workflows

![Workflows](docs/screenshots/workflows.png)

### Simple Workflows

A DAG wrapper around `Bus::batch()` — define steps with dependencies, Station resolves execution order:

```php
use Station\Core\Workflow;

Workflow::create('order-pipeline')
    ->add('validate', new ValidateOrderJob($order))
    ->add('payment', new ProcessPaymentJob($order), ['validate'])
    ->add('inventory', new ReserveInventoryJob($order), ['validate'])
    ->add('ship', new ShipOrderJob($order), ['payment', 'inventory'])
    ->onQueue('high')
    ->dispatch();
```

Steps with no dependencies between them run in parallel.

### Full Workflow Engine

Persistent workflows with conditional steps, branching, and pause/resume:

```php
use Station\Facades\Workflow;

// Define a reusable workflow
Workflow::define('payment-processing')
    ->addStep('validate', ValidatePaymentJob::class)
    ->addStep('charge', ChargeCardJob::class, ['validate'])
    ->addConditionalStep('notify',
        SendNotificationJob::class,
        fn($context) => $context['charge_successful'] ?? false,
        ['charge']
    )
    ->timeout(3600);

// Run synchronously
$instance = Workflow::run('payment-processing', [
    'amount' => 99.99,
    'user_id' => 42,
]);

// Or run asynchronously (recommended for production)
$instance = Workflow::runAsync('payment-processing', [
    'amount' => 99.99,
    'user_id' => 42,
]);
```

Workflow instances are stored in the database with full state tracking, and can be paused, resumed, or cancelled from the dashboard.

For details on execution model, branching, and context propagation, see [Workflows Architecture](docs/architecture.md#workflows).

![Workflow Definitions](docs/screenshots/workflow-definitions.png)

### Workflows vs Laravel Bus

Station's workflow engine provides DAG orchestration beyond what Laravel's built-in `Bus::batch()` and `Bus::chain()` can express:

| Feature                       | Station Workflows |   Bus::batch()    |   Bus::chain()   |
| ----------------------------- | :---------------: | :---------------: | :--------------: |
| DAG dependencies              |        Yes        |        No         | No (linear only) |
| Parallel execution            |    Yes (async)    |        Yes        |        No        |
| Conditional steps             |   Yes (runtime)   |        No         |        No        |
| Dynamic branching             |        Yes        |        No         |        No        |
| Pause / Resume                |        Yes        |        No         |        No        |
| Context passing between steps |        Yes        |        No         |        No        |
| Per-step status tracking      |        Yes        | Batch-level only  |        No        |
| Per-step retry / timeout      |    Yes (async)    |      Per-job      |     Per-job      |
| Progress tracking             |   Yes (step %)    | Yes (job count %) |        No        |
| Cancellation                  |        Yes        |        Yes        |   Stops chain    |
| Stuck step recovery           |        Yes        |        No         |        No        |
| Persisted state               |     Yes (DB)      | Yes (job_batches) |        No        |

Station workflows support both synchronous (`Workflow::run()`) and asynchronous (`Workflow::runAsync()`) execution. In async mode, each step is dispatched as a standard `ShouldQueue` job processed by Laravel workers, enabling true parallel execution across multiple workers. The orchestration layer (dependency resolution, conditional evaluation, context propagation) is where Station adds value on top of Laravel's primitives.

---

## Job Recovery

### Checkpointing

Long-running jobs can save progress and resume after failures:

```php
use Station\Contracts\Checkpointable;

class ImportUsersJob implements ShouldQueue, Checkpointable
{
    private int $lastId = 0;

    public function handle(): void
    {
        User::where('id', '>', $this->lastId)->orderBy('id')
            ->chunk(100, function ($users) {
                foreach ($users as $user) {
                    $this->processUser($user);
                }
                $this->lastId = $users->last()->id;
            });
    }

    public function checkpoint(): array
    {
        return ['last_id' => $this->lastId];
    }

    public function restore(array $data): void
    {
        $this->lastId = $data['last_id'] ?? 0;
    }

    public function hasMoreWork(): bool
    {
        return User::where('id', '>', $this->lastId)->exists();
    }
}
```

### Stuck Job Detection

Station detects hung jobs using weighted scoring (heartbeat, runtime, memory, process state) and can recover them automatically:

```bash
php artisan station:recover
php artisan station:recover --dry-run        # Preview without acting
php artisan station:recover --strategy=graceful --workflows
```

For details on scoring, strategies, and health checks, see [Recovery System](docs/architecture.md#recovery-system).

---

## Artisan Commands

| Command                      | Description                                                                             |
| ---------------------------- | --------------------------------------------------------------------------------------- |
| `station:work`               | Start the supervisor (manages multiple workers)                                         |
| `station:status`             | Show queue and worker status                                                            |
| `station:pause {queue}`      | Pause processing for a specific queue                                                   |
| `station:resume {queue}`     | Resume a paused queue                                                                   |
| `station:terminate`          | Gracefully stop all workers                                                             |
| `station:recover`            | Detect and recover stuck jobs (`--strategy`, `--threshold`, `--dry-run`, `--workflows`) |
| `station:retry {id}`         | Retry a failed job (`--all` for all)                                                    |
| `station:failed`             | List failed jobs                                                                        |
| `station:flush`              | Delete all failed jobs                                                                  |
| `station:prune`              | Clean up old data                                                                       |
| `station:health`             | Run health checks                                                                       |
| `station:install`            | Install Station                                                                         |
| `station:publish-supervisor` | Generate a Supervisor config file (`--workers`, `--user`, `--path`)                     |
| `station:alerts:check`       | Evaluate alert rules and send notifications (`--seed` to seed from config)              |

---

## Dashboard

Real-time monitoring UI at `/station` with auto-refresh every 3 seconds:

![Connections](docs/screenshots/connections.png)

- **Overview** — Job throughput, failure rate, average processing time, active workers
- **Jobs** — Paginated list with status/queue/class filters, retry and delete actions
- **Failed Jobs** — Exception details, retry individually or in bulk
- **Batches** — Progress bars, status tracking, cancel and retry
- **Workflows** — Definitions, running instances, step-by-step progress
- **Monitoring** — Queue depths, worker counts, throughput charts
- **Metrics** — Historical data with time range selection

![Metrics](docs/screenshots/metrics.png)

### Authorization

By default, the dashboard is accessible in `local` environment. In other environments, configure the `authorization` gate in `config/station.php`:

```php
// config/station.php
'dashboard' => [
    'authorization' => 'viewStation', // Gate name to check
],
```

Then define the gate in your `AuthServiceProvider`:

```php
Gate::define('viewStation', function ($user) {
    return in_array($user->email, ['admin@example.com']);
});
```

### API

Full REST API at `/api/station/` (configurable) with Bearer token auth. See [`docs/openapi.yaml`](docs/openapi.yaml) for the OpenAPI 3.1 specification.

---

## Configuration

```php
// config/station.php (key sections)
return [
    'default' => 'rabbitmq',

    'connections' => [
        'rabbitmq' => [
            'driver' => 'rabbitmq',
            'hosts' => [[ 'host' => env('RABBITMQ_HOST', 'localhost'), /* ... */ ]],
        ],
    ],

    'supervisors' => [
        'default' => [
            'queues' => ['default'],
            'processes' => 2,
            'timeout' => 60,
            'memory' => 128,
        ],
    ],

    'dashboard' => ['enabled' => true, 'path' => 'station', 'middleware' => ['web', 'auth']],
    'recovery' => ['enabled' => true, 'stuck_job_timeout' => 900, 'auto_resume' => true],
    'checkpoints' => ['enabled' => true, 'storage' => 'database'],
];
```

For the full configuration reference, see [Configuration](docs/configuration.md).

---

## Documentation

For in-depth documentation, see the [`docs/`](docs/) directory:

- **[Architecture](docs/architecture.md)** — Internal architecture: jobs, batches, workflows, workers, recovery, metrics, database schema, Station vs Horizon
- **[Queue Drivers](docs/drivers.md)** — Feature matrix, per-driver configs, known limitations
- **[Facades](docs/facades.md)** — All 7 facades with every public method
- **[Security & Resilience](docs/security.md)** — API auth, encryption, masking, alerting, circuit breaker
- **[Configuration](docs/configuration.md)** — Full config reference and environment variables
- **[Migrating from Horizon](docs/migration.md)** — Step-by-step migration guide and feature mapping
- **[API Reference](docs/openapi.yaml)** — OpenAPI 3.1 specification

---

## Development Setup

Station includes a Docker environment with all queue drivers pre-configured:

```bash
docker compose up -d     # Start all services
make test                # Run tests
make quality             # Run all quality checks (tests + PHPStan + code style)
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full development guide, [`docker/README.md`](docker/README.md) for Docker environment details, and the `Makefile` for all available commands.

---

## Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, code style guidelines (PER-CS 3.0), and testing requirements (95% coverage).

## Security

For security vulnerabilities, please see [SECURITY.md](SECURITY.md). **Do not** report security issues via GitHub Issues.

## License

Station is open-sourced software licensed under the [MIT license](LICENSE).
