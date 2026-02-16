<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\MetricsCollectorInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;

final class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:status
                            {--queue= : Filter by queue name}';

    /**
     * The console command description.
     */
    protected $description = 'Display the status of Station queues and workers';

    public function __construct(
        private readonly JobRepositoryInterface $jobRepository,
        private readonly SupervisorRepositoryInterface $supervisorRepository,
        private readonly WorkerRepositoryInterface $workerRepository,
        private readonly MetricsCollectorInterface $metrics,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displaySupervisors();
        $this->displayWorkers();
        $this->displayQueues();
        $this->displayMetrics();

        return self::SUCCESS;
    }

    /**
     * Display supervisor status.
     */
    private function displaySupervisors(): void
    {
        $supervisors = $this->supervisorRepository->getActive();

        $this->components->info('Supervisors');

        if ($supervisors->isEmpty()) {
            $this->components->warn('No active supervisors found.');

            return;
        }

        $rows = [];
        foreach ($supervisors as $supervisor) {
            $rows[] = [
                $supervisor['id'],
                $supervisor['name'] ?? 'default',
                $supervisor['pid'],
                $supervisor['status'],
                $supervisor['started_at'],
            ];
        }

        $this->table(
            ['ID', 'Name', 'PID', 'Status', 'Started At'],
            $rows,
        );
    }

    /**
     * Display worker status.
     */
    private function displayWorkers(): void
    {
        $workers = $this->workerRepository->getActive();

        $this->components->info('Workers');

        if ($workers->isEmpty()) {
            $this->components->warn('No active workers found.');

            return;
        }

        $rows = [];
        foreach ($workers as $worker) {
            $rows[] = [
                $worker['id'],
                $worker['supervisor_id'],
                $worker['pid'],
                $worker['queue'],
                $worker['status'],
                $worker['jobs_processed'] ?? 0,
            ];
        }

        $this->table(
            ['ID', 'Supervisor', 'PID', 'Queue', 'Status', 'Jobs Processed'],
            $rows,
        );
    }

    /**
     * Display queue status.
     */
    private function displayQueues(): void
    {
        $queue = $this->option('queue');

        $this->components->info('Queue Status');

        $stats = $this->jobRepository->getStatsByQueue();

        if ($stats === []) {
            $this->components->warn('No queue statistics available.');

            return;
        }

        $rows = [];
        foreach ($stats as $queueName => $queueStats) {
            if ($queue !== null && $queueName !== $queue) {
                continue;
            }

            $rows[] = [
                $queueName,
                $queueStats->pending,
                $queueStats->processing,
                $queueStats->completed,
                $queueStats->failed,
            ];
        }

        $this->table(
            ['Queue', 'Pending', 'Processing', 'Completed', 'Failed'],
            $rows,
        );
    }

    /**
     * Display metrics summary.
     */
    private function displayMetrics(): void
    {
        $this->components->info('Metrics (Last Hour)');

        $throughput = $this->metrics->getThroughput();
        $avgWait = $this->metrics->getAverageWaitTime();
        $avgProcess = $this->metrics->getAverageProcessingTime();
        $failureRate = $this->metrics->getFailureRate();

        $this->components->twoColumnDetail('Throughput', $throughput . ' jobs/min');
        $this->components->twoColumnDetail('Avg Wait Time', round($avgWait, 2) . 's');
        $this->components->twoColumnDetail('Avg Processing Time', round($avgProcess, 2) . 's');
        $this->components->twoColumnDetail('Failure Rate', round($failureRate * 100, 2) . '%');
    }
}
