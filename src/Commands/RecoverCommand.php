<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Core\Job;
use Station\Workflows\WorkflowManager;
use Throwable;

final class RecoverCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:recover
                            {--queue= : Only recover jobs from a specific queue}
                            {--strategy=graceful : Recovery strategy (graceful, restart, checkpoint)}
                            {--dry-run : Show what would be recovered without taking action}
                            {--threshold=300 : Seconds before a job is considered stuck}
                            {--workflows : Also recover stuck workflow steps}';

    /**
     * The console command description.
     */
    protected $description = 'Detect and recover stuck jobs';

    public function __construct(
        private readonly StuckJobDetectorInterface $detector,
        private readonly JobResumerInterface $resumer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queueOption = $this->option('queue');
        $queue = \is_string($queueOption) ? $queueOption : null;
        $strategyOption = $this->option('strategy');
        $strategy = \is_string($strategyOption) ? $strategyOption : 'graceful';
        $dryRun = (bool) $this->option('dry-run');
        $threshold = (int) $this->option('threshold');

        $this->components->info('Scanning for stuck jobs...');

        $stuckJobs = $this->detector->detect([
            'queue' => $queue,
            'threshold' => $threshold,
        ]);

        if ($stuckJobs->isEmpty()) {
            $this->components->info('No stuck jobs found.');
        } else {
            $this->components->warn('Found ' . $stuckJobs->count() . ' stuck job(s).');

            $rows = [];
            foreach ($stuckJobs as $job) {
                /** @var Job $job */
                $stuckDuration = $job->startedAt !== null
                    ? $job->startedAt->diffInSeconds(now())
                    : 0;

                $rows[] = [
                    $job->id,
                    $job->jobClass,
                    $job->queue,
                    $stuckDuration . 's',
                    $job->attempts,
                ];
            }

            $this->table(
                ['Job ID', 'Name', 'Queue', 'Stuck Duration', 'Attempts'],
                $rows,
            );

            if (!$dryRun) {
                if ($this->confirm('Do you want to recover these jobs?', true)) {
                    $this->components->info("Recovering jobs with strategy: {$strategy}");

                    $recovered = 0;
                    $failed = 0;

                    foreach ($stuckJobs as $job) {
                        /** @var Job $job */
                        try {
                            $this->resumer->resume($job->id, $strategy);
                            $recovered++;
                            $this->components->twoColumnDetail($job->id, '<fg=green>Recovered</>');
                        } catch (Throwable $e) {
                            $failed++;
                            $this->components->twoColumnDetail($job->id, '<fg=red>Failed: ' . $e->getMessage() . '</>');
                        }
                    }

                    $this->newLine();
                    $this->components->info("Recovery complete: {$recovered} recovered, {$failed} failed.");

                    if ($failed > 0 && !$this->option('workflows')) {
                        return self::FAILURE;
                    }
                }
            } else {
                $this->components->info('Dry run - no action taken.');
            }
        }

        // Workflow recovery
        if ($this->option('workflows')) {
            $this->recoverWorkflows($threshold, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Recover stuck workflow steps.
     */
    private function recoverWorkflows(int $threshold, bool $dryRun): void
    {
        $this->newLine();
        $this->components->info('Scanning for stuck workflow steps...');

        try {
            $manager = app(WorkflowManager::class);

            if ($dryRun) {
                $this->components->info('Dry run - workflow recovery would run with threshold: ' . $threshold . 's');

                return;
            }

            $recovered = $manager->recoverStuckWorkflows($threshold);

            if (empty($recovered)) {
                $this->components->info('No stuck workflow steps found.');

                return;
            }

            $this->components->warn('Recovered ' . \count($recovered) . ' workflow step(s).');

            $this->table(
                ['Workflow ID', 'Action', 'Step'],
                array_map(static fn(array $r) => [$r['id'], $r['action'], $r['step']], $recovered),
            );
        } catch (Throwable $e) {
            $this->components->error('Workflow recovery failed: ' . $e->getMessage());
        }
    }
}
