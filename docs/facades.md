# Facades

Station provides seven facades for interacting with different subsystems. Each facade resolves to a singleton registered in the service container.

---

## Station Facade

**Resolves to:** `Station\Core\JobManager`

```php
use Station\Facades\Station;

// Fluent dispatch API
Station::job($job);                         // Create PendingDispatch with fluent API
Station::job($job)->onQueue('high')->tags(['orders'])->dispatch();

// Direct dispatch
Station::dispatch($job);                    // Dispatch a job, returns job ID (string)
Station::dispatch($job, 'high');            // Dispatch to specific queue
Station::dispatchSync($job);               // Execute synchronously (void)

// Job operations
Station::find($jobId);                      // Find job by ID, returns Job|null
Station::retry($jobId);                     // Retry a failed job, returns bool
Station::cancel($jobId);                    // Cancel a pending job, returns bool
Station::delete($jobId);                    // Delete a job record (void)
Station::complete($jobId, $time, $memory);  // Mark job completed (void)
Station::fail($jobId, $exception);          // Mark job failed (void)

// Queries
Station::getStats($queue);                  // Get job statistics (array)
Station::getByStatus($status, $queue, $limit); // Get jobs by status, returns Collection
Station::getRecent($limit, $queue);         // Recent jobs (default limit: 10), returns Collection
Station::search($filters, $limit, $offset); // Search jobs, returns Collection
Station::count($filters);                   // Count jobs matching filters (int)

// Bulk operations
Station::retryAll($queue);                  // Retry all failed jobs on queue, returns count
Station::retryAllFailed($queue);            // Retry all failed jobs, returns count
Station::pruneCompleted($hours);            // Prune completed jobs older than N hours, returns count
```

---

## Batch Facade

**Resolves to:** `Station\Core\BatchManager`

```php
use Station\Facades\Batch;

// Create and dispatch a batch
$batch = Batch::create(
    jobs: [new ProcessOrderJob($order1), new ProcessOrderJob($order2)],
    name: 'Daily Orders',
    queue: 'default',
    allowedFailures: 2,   // Integer threshold (not boolean)
    options: [],
    connection: null,
);

// Query
Batch::find($batchId);                     // Find batch by ID, returns Batch|null
Batch::getActive();                        // Get active batches, returns Collection
Batch::getRecent($limit);                  // Recent batches (default: 10), returns Collection
Batch::getByStatus($status, $limit);       // Get batches by status (default limit: 100), returns Collection

// Operations
Batch::cancel($batchId);                   // Cancel a batch, returns bool
Batch::retryFailed($batchId);              // Retry failed jobs in a batch, returns count
Batch::prune();                            // Prune old batches (reads retention from config), returns count

// Internal (called by event listeners)
Batch::recordJobCompletion($batchId);      // Record a job completion (void)
Batch::recordJobFailure($batchId, $jobId); // Record a job failure (void)
```

---

## Chain Facade

**Resolves to:** `Station\Core\Chain`

```php
use Station\Facades\Chain;

Chain::create([new Step1Job, new Step2Job, new Step3Job])
    ->name('my-chain')
    ->onConnection('redis')
    ->onQueue('high')
    ->catch(fn(Throwable $e) => Log::error($e->getMessage()))
    ->finally(fn() => Log::info('Chain finished'))
    ->dispatch();

// After dispatch
$chain->getId();                           // Get chain ID (string)
$chain->getJobs();                         // Get jobs array
```

---

## Queues Facade

**Resolves to:** `Station\Core\QueueManager`

```php
use Station\Facades\Queues;

Queues::pause($queue, $connection);         // Pause a queue (void)
Queues::resume($queue, $connection);        // Resume a paused queue (void)
Queues::isPaused($queue, $connection);      // Check if queue is paused (bool)
Queues::size($queue, $connection);          // Get queue depth (int)
Queues::clear($queue, $connection);         // Clear all jobs from queue, returns count
Queues::status($connection);                // Get status of all queues (array)
Queues::getAll($connection);                // Get all known queue names (array)
```

---

## Monitor Facade

**Resolves to:** `Station\Core\MetricsCollector`

```php
use Station\Facades\Monitor;

// Derived metrics
Monitor::getThroughput($queue);             // Jobs per minute (float)
Monitor::getAverageWaitTime($queue);        // Average wait time in ms (float)
Monitor::getAverageProcessingTime($queue);  // Average processing time in ms (float)
Monitor::getFailureRate($queue);            // Failure rate 0.0–1.0 (float)

// Aggregated metrics
Monitor::getMetrics($period);               // Metrics for period (default: '1h'), returns array
Monitor::getHistoricalMetrics($period, $limit); // Historical metrics (default limit: 100), returns array
Monitor::paginateHistoricalMetrics($period, $page, $perPage, $connection); // Paginated history, returns array
Monitor::getAggregatedForPeriod($period, $connection); // Aggregated for period, returns array
Monitor::getTimeSeries($period, $buckets, $connection); // Time series (default: 30 buckets), returns array
Monitor::getForRange($queue, $start, $end); // Metrics for date range, returns Collection

// Snapshots and stats
Monitor::getSnapshot();                     // Current snapshot of all queues (array)
Monitor::getQueueStats($additionalQueues);  // Per-queue stats (array)
Monitor::stats();                           // Summary stats (array)

// Recording (called by event listeners)
Monitor::recordJobCompletion($queue, $processingTime, $waitTime, $memoryUsed, $connection); // void
Monitor::recordJobFailure($queue, $connection); // void
Monitor::record($queue, $jobsProcessed, $jobsFailed, $jobsPending, ...); // Full metric record (void)

// Maintenance
Monitor::isEnabled();                       // Check if metrics collection is enabled (bool)
Monitor::flush();                           // Clear all metrics (void)
Monitor::prune($hours);                     // Prune metrics older than N hours, returns count
```

---

## Recovery Facade

**Resolves to:** `Station\Recovery\JobResumer`

```php
use Station\Facades\Recovery;

Recovery::isEnabled();                      // Check if recovery is enabled (bool)
Recovery::resume($jobId, $strategy);        // Resume a stuck job (default: 'graceful'), returns bool
Recovery::resumeJob($job, $strategy);       // Resume a Job object (default: 'graceful'), returns bool
Recovery::recoverAll($strategy);            // Recover all stuck jobs (default: 'graceful'), returns count
Recovery::health();                         // Get the HealthChecker instance
```

The `health()` method returns a `HealthCheckerInterface` with its own methods:

```php
$health = Recovery::health();

$health->isEnabled();                       // Check if health checks are enabled (bool)
$health->check();                           // Run all health checks (array)
$health->checkConnections();                // Check all driver connections (array)
$health->checkConnectivityQuick();          // Quick connectivity probe (array)
$health->checkDatabase();                   // Check database accessibility (array)
$health->checkDisk();                       // Check disk space (array)
$health->getResponse();                     // Full health response (array)
```

---

## Workflow Facade

**Resolves to:** `Station\Workflows\WorkflowManager`

```php
use Station\Facades\Workflow;

// Simple workflows (DAG wrapper around Bus::batch)
Workflow::create($name);                                      // Create a SimpleWorkflow

// Definitions
Workflow::define($name);                                      // Create and register a WorkflowDefinition
Workflow::register($definition);                              // Register an externally-created definition
Workflow::getDefinition($name);                               // Get a definition by name
Workflow::getDefinitions();                                   // Get all definitions

// Execution
Workflow::run($name, $input);                                 // Synchronous execution
Workflow::runAsync($name, $input, $connection);               // Asynchronous execution (recommended)
Workflow::executeExistingInstance($name, $id);                 // Internal: used by RunWorkflowJob
Workflow::executeAsyncStep($id, $step, $name);                // Internal: used by WorkflowStepJob
Workflow::handleAsyncStepFailure($id, $step, $error);         // Internal: used by WorkflowStepJob::failed()

// Instance queries
Workflow::getInstance($instanceId);                           // Get an instance by ID
Workflow::status($name, $instanceId);                         // Get workflow status
Workflow::getInstances($name, $limit);                        // List instances (default: 50)

// Control
Workflow::cancel($instanceId);                                // Cancel an instance
Workflow::pause($instanceId);                                 // Pause an instance
Workflow::resume($instanceId);                                // Resume an instance

// Recovery
Workflow::recoverStuckWorkflows($threshold);                   // Recover stuck workflows (default: 300s)
```
