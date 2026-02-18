<?php

declare(strict_types=1);

namespace Station\Core;

use Illuminate\Contracts\Events\Dispatcher;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Station\Contracts\WorkerSupervisorInterface;
use Station\Events\SupervisorStarted;
use Station\Events\SupervisorStopped;
use Station\Events\WorkerStarted;
use Station\Events\WorkerStopped;
use Station\Scaling\AutoScaler;
use Throwable;

final class WorkerSupervisor implements WorkerSupervisorInterface
{
    private string $id;

    private string $name;

    private bool $shouldQuit = false;

    private bool $paused = false;

    /** @var array<int, int> Worker PIDs */
    private array $workerPids = [];

    private int $jobsProcessed = 0;

    /** @var array<int, string> Current queues being worked */
    private array $currentQueues = ['default'];

    /** @var array<string, mixed> Current worker options */
    private array $currentOptions = [];

    private int $targetProcesses = 1;

    private ?AutoScaler $autoScaler = null;

    private int $loopIteration = 0;

    public function __construct(
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $this->config['supervisors']['default']['name'] ?? 'default';
    }

    /**
     * Get the supervisor ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the supervisor name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Start the supervisor.
     *
     * @param array<int, string> $queues
     * @param array<string, mixed> $options
     */
    public function start(array $queues, array $options = []): void
    {
        $this->registerSignalHandlers();

        // Store current configuration for worker pool maintenance
        $this->currentQueues = $queues;
        $this->currentOptions = $options;

        $processes = $options['processes'] ?? $this->config['supervisors']['default']['processes'] ?? 1;
        $this->targetProcesses = $processes;

        $this->events->dispatch(new SupervisorStarted(
            $this->id,
            $this->name,
            $queues,
            $options,
        ));

        // Fork worker processes
        for ($i = 0; $i < $processes; $i++) {
            $this->startWorker($queues, $options);
        }

        // Supervisor loop
        while (!$this->shouldQuit) {
            $this->loop();
            usleep(100000); // 100ms
        }

        // Wait for workers to finish
        $this->terminateWorkers();

        $this->events->dispatch(new SupervisorStopped(
            $this->id,
            $this->name,
            'shutdown',
            $this->jobsProcessed,
        ));
    }

    /**
     * Pause the supervisor.
     */
    public function pause(): void
    {
        $this->paused = true;

        // Send SIGUSR1 to workers to pause them
        foreach ($this->workerPids as $pid) {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGUSR1);
            }
        }
    }

    /**
     * Resume the supervisor.
     */
    public function resume(): void
    {
        $this->paused = false;

        // Send SIGUSR2 to workers to resume them
        foreach ($this->workerPids as $pid) {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGUSR2);
            }
        }
    }

    /**
     * Terminate the supervisor gracefully.
     */
    public function terminate(): void
    {
        $this->shouldQuit = true;
    }

    /**
     * Check if the supervisor is paused.
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * Get the number of active workers.
     */
    public function getWorkerCount(): int
    {
        return \count($this->workerPids);
    }

    /**
     * Scale workers to the given count (bounded by config min/max).
     */
    public function scaleWorkers(int $count): void
    {
        $min = (int) ($this->config['scaling']['policies']['default']['min_workers'] ?? 1);
        $max = (int) ($this->config['scaling']['policies']['default']['max_workers'] ?? 10);

        $this->targetProcesses = max($min, min($max, $count));
    }

    /**
     * Set the auto-scaler for dynamic worker scaling.
     */
    public function setAutoScaler(AutoScaler $autoScaler): void
    {
        $this->autoScaler = $autoScaler;
    }

    /**
     * Main supervisor loop.
     */
    private function loop(): void
    {
        $this->loopIteration++;

        // Reap dead workers
        $this->reapWorkers();

        // Evaluate auto-scaling every 10 iterations (~1 second)
        if ($this->autoScaler !== null && $this->loopIteration % 10 === 0) {
            $this->evaluateScaling();
        }

        // Check if we need to restart any workers
        $this->maintainWorkerPool();

        // Collect metrics
        $this->collectMetrics();
    }

    /**
     * Start a worker process.
     *
     * @param array<int, string> $queues
     * @param array<string, mixed> $options
     */
    private function startWorker(array $queues, array $options): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Failed to fork worker process');
        }

        if ($pid === 0) {
            // Child process - run the worker
            try {
                $this->runWorker($queues, $options);
            } catch (Throwable $e) {
                logger()->error("Station worker error: " . $e->getMessage());
            }
            exit(0);
        }

        // Parent process - track the worker
        $this->workerPids[] = $pid;

        $this->events->dispatch(new WorkerStarted(
            "worker-{$pid}",
            $queues,
            $options,
        ));
    }

    /**
     * Run a worker (called in forked process).
     *
     * @param array<int, string> $queues
     * @param array<string, mixed> $options
     */
    private function runWorker(array $queues, array $options): void
    {
        // After fork, we need fresh instances from the container
        // to avoid connection issues with database and queue drivers
        $app = app();

        // Reconnect database
        $app['db']->reconnect();

        // Get fresh instances from the container
        $worker = new Worker(
            $app['queue'],
            $app['events'],
            $this->config,
        );

        $worker->run($queues, $options);
    }

    /**
     * Reap dead workers.
     */
    private function reapWorkers(): void
    {
        foreach ($this->workerPids as $index => $pid) {
            $status = 0;
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid) {
                // Worker has exited
                unset($this->workerPids[$index]);

                $this->events->dispatch(new WorkerStopped(
                    "worker-{$pid}",
                    'exited',
                    0,
                ));
            }
        }

        $this->workerPids = array_values($this->workerPids);
    }

    /**
     * Maintain the worker pool (restart dead workers, scale up/down).
     */
    private function maintainWorkerPool(): void
    {
        if ($this->shouldQuit) {
            return;
        }

        // Scale down: gracefully terminate excess workers (youngest first)
        while (\count($this->workerPids) > $this->targetProcesses) {
            $pid = array_pop($this->workerPids);

            if ($pid !== null && posix_kill($pid, 0)) {
                posix_kill($pid, SIGTERM);
            }
        }

        // Scale up: fork new workers
        while (\count($this->workerPids) < $this->targetProcesses) {
            $this->startWorker($this->currentQueues, $this->currentOptions);
        }
    }

    /**
     * Terminate all workers.
     */
    private function terminateWorkers(): void
    {
        $timeout = $this->config['shutdown']['timeout'] ?? 30;

        // Send SIGTERM to all workers
        foreach ($this->workerPids as $pid) {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGTERM);
            }
        }

        // Wait for workers to finish with timeout
        $start = time();
        while (\count($this->workerPids) > 0 && (time() - $start) < $timeout) {
            $this->reapWorkers();
            usleep(100000); // 100ms
        }

        // Force kill remaining workers
        foreach ($this->workerPids as $pid) {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
            }
        }

        // Final reap
        $this->reapWorkers();
    }

    /**
     * Evaluate auto-scaling decisions and adjust target processes.
     */
    private function evaluateScaling(): void
    {
        if ($this->autoScaler === null || !$this->autoScaler->isEnabled()) {
            return;
        }

        $results = $this->autoScaler->scale();

        foreach ($results as $result) {
            if ($result['action'] !== '') {
                $this->scaleWorkers($result['to']);
            }
        }
    }

    /**
     * Collect and record metrics.
     */
    private function collectMetrics(): void
    {
        // @todo Collect worker-level metrics (memory, job count, uptime)
    }

    /**
     * Register signal handlers.
     */
    private function registerSignalHandlers(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function (): void {
            $this->terminate();
        });

        pcntl_signal(SIGINT, function (): void {
            $this->terminate();
        });

        pcntl_signal(SIGQUIT, function (): void {
            $this->terminate();
        });

        pcntl_signal(SIGUSR1, function (): void {
            $this->pause();
        });

        pcntl_signal(SIGUSR2, function (): void {
            $this->resume();
        });
    }
}
