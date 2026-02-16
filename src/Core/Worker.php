<?php

declare(strict_types=1);

namespace Station\Core;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Events\JobFailed as JobFailedEvent;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Station\Events\WorkerLoopIteration;
use Throwable;

final class Worker
{
    private string $id;

    private bool $shouldQuit = false;

    private bool $paused = false;

    private int $jobsProcessed = 0;

    private string $connection = 'default';

    public function __construct(
        private readonly QueueFactory $queueManager,
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
        $this->id = 'worker-' . Uuid::uuid7()->toString();
    }

    /**
     * Get the worker ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Run the worker loop.
     *
     * @param array<int, string> $queues
     * @param array<string, mixed> $options
     */
    public function run(array $queues, array $options = []): void
    {
        $this->registerSignalHandlers();

        $this->connection = $options['connection'] ?? $this->config['default'] ?? 'database';
        $memory = $options['memory'] ?? $this->config['supervisors']['default']['memory'] ?? 128;
        $timeout = $options['timeout'] ?? $this->config['supervisors']['default']['timeout'] ?? 60;
        $sleep = $options['sleep'] ?? $this->config['supervisors']['default']['sleep'] ?? 3;
        $maxJobs = $options['max_jobs'] ?? $this->config['supervisors']['default']['max_jobs'] ?? 0;
        $maxTime = $options['max_time'] ?? $this->config['supervisors']['default']['max_time'] ?? 0;

        $startTime = time();

        while (!$this->shouldQuit) {
            // Check if paused
            if ($this->paused) {
                usleep(100000); // 100ms

                continue;
            }

            // Check memory limit
            if ($this->memoryExceeded($memory)) {
                $this->shouldQuit = true;

                continue;
            }

            // Check max jobs
            if ($maxJobs > 0 && $this->jobsProcessed >= $maxJobs) {
                $this->shouldQuit = true;

                continue;
            }

            // Check max time
            if ($maxTime > 0 && (time() - $startTime) >= $maxTime) {
                $this->shouldQuit = true;

                continue;
            }

            // Try to process a job from the queues
            $processed = $this->processNextJob($queues, $timeout);

            if (!$processed) {
                // No job available, sleep
                sleep($sleep);
            }

            $this->events->dispatch(new WorkerLoopIteration($this->id));
        }
    }

    /**
     * Stop the worker.
     */
    public function stop(): void
    {
        $this->shouldQuit = true;
    }

    /**
     * Pause the worker.
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    /**
     * Resume the worker.
     */
    public function resume(): void
    {
        $this->paused = false;
    }

    /**
     * Process the next available job from the queue driver.
     *
     * @param array<int, string> $queues
     */
    private function processNextJob(array $queues, int $timeout): bool
    {
        try {
            $queueConnection = $this->queueManager->connection($this->connection);

            // Try each queue in order (priority order)
            foreach ($queues as $queue) {
                $job = $queueConnection->pop($queue);

                if ($job !== null) {
                    $this->processJob($job, $queue, $timeout);

                    return true;
                }
            }
        } catch (Throwable $e) {
            // Log error but continue processing
            logger()->error("Station worker error popping job: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Process a job from the queue driver.
     */
    private function processJob(QueueJob $job, string $queue, int $timeout): void
    {
        $jobId = $job->getJobId();

        // Fire Laravel's standard JobProcessing event
        // (StationServiceProvider listeners handle DB tracking and metrics)
        $this->events->dispatch(new JobProcessing($this->connection, $job));

        try {
            // Set timeout alarm
            pcntl_alarm($timeout);

            // Fire the job (Laravel's Job class handles execution)
            $job->fire();

            // Clear timeout
            pcntl_alarm(0);

            // Mark job as deleted (acknowledged) - this calls ack on the driver
            if (!$job->isDeleted() && !$job->isReleased() && !$job->hasFailed()) {
                $job->delete();
            }

            // Fire Laravel's standard JobProcessed event
            // (StationServiceProvider listeners handle DB tracking, batch counters, and metrics)
            $this->events->dispatch(new JobProcessed($this->connection, $job));

            $this->jobsProcessed++;
        } catch (Throwable $e) {
            // Clear timeout
            pcntl_alarm(0);

            // Fire Laravel's standard JobFailed event
            // (StationServiceProvider listeners handle DB tracking, batch counters, and metrics)
            $this->events->dispatch(new JobFailedEvent($this->connection, $job, $e));

            $this->handleJobFailure($job, $jobId, $queue, $e);
        }
    }

    /**
     * Handle a job failure.
     */
    private function handleJobFailure(QueueJob $job, string $jobId, string $queue, Throwable $e): void
    {
        // Check if job should be retried
        $maxTries = $job->maxTries() ?? 3;

        try {
            if ($job->attempts() < $maxTries) {
                // Release back to queue with exponential backoff delay
                $delay = $this->calculateRetryDelay($job->attempts());
                $job->release($delay);
            } else {
                // Max attempts reached, fail the job
                $job->fail($e);
            }
        } catch (Throwable $releaseException) {
            // If we can't release/fail the job (e.g., channel corrupted),
            // log the error but don't let it crash the worker
            logger()->error("Station worker error releasing/failing job: " . $releaseException->getMessage(), [
                'original_error' => $e->getMessage(),
                'job_id' => $jobId,
                'queue' => $queue,
            ]);
        }
    }

    /**
     * Calculate retry delay using exponential backoff.
     */
    private function calculateRetryDelay(int $attempt): int
    {
        // Exponential backoff: 2^attempt * base_delay (with some randomness)
        $baseDelay = 10; // seconds
        $maxDelay = 3600; // 1 hour max

        $delay = (int) min($baseDelay * (2 ** ($attempt - 1)), $maxDelay);

        // Add jitter (±20%)
        $jitter = (int) ($delay * 0.2);
        $delay += random_int(-$jitter, $jitter);

        return max($delay, 0);
    }

    /**
     * Check if the worker has exceeded its memory limit.
     */
    private function memoryExceeded(int $memoryLimit): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Register signal handlers.
     */
    private function registerSignalHandlers(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function (): void {
            $this->stop();
        });

        pcntl_signal(SIGINT, function (): void {
            $this->stop();
        });

        pcntl_signal(SIGUSR1, function (): void {
            $this->pause();
        });

        pcntl_signal(SIGUSR2, function (): void {
            $this->resume();
        });

        pcntl_signal(SIGALRM, static function (): void {
            throw new RuntimeException('Job timed out');
        });
    }
}
