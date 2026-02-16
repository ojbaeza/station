<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Throwable;

final class RetryCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:retry
                            {id?* : The IDs of the jobs to retry}
                            {--all : Retry all failed jobs}
                            {--queue= : Retry failed jobs from a specific queue}
                            {--range= : Retry a range of job IDs (e.g., 1-100)}';

    /**
     * The console command description.
     */
    protected $description = 'Retry failed jobs';

    public function __construct(
        private readonly JobRepositoryInterface $jobRepository,
        private readonly JobManagerInterface $jobManager,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $idsArg = $this->argument('id');
        $ids = \is_array($idsArg) ? array_map('strval', $idsArg) : [];
        $all = (bool) $this->option('all');
        $queueOption = $this->option('queue');
        $queue = \is_string($queueOption) ? $queueOption : null;
        $rangeOption = $this->option('range');
        $range = \is_string($rangeOption) ? $rangeOption : null;

        if ($all) {
            return $this->retryAll($queue);
        }

        if ($range !== null) {
            return $this->retryRange($range);
        }

        if ($ids === []) {
            $this->components->error('Please specify job IDs, use --all, or use --range.');

            return self::FAILURE;
        }

        return $this->retryJobs($ids);
    }

    /**
     * Retry specific jobs by ID.
     *
     * @param array<string> $ids
     */
    private function retryJobs(array $ids): int
    {
        $retried = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $this->jobManager->retry($id);
                $retried++;
                $this->components->twoColumnDetail($id, '<fg=green>Retried</>');
            } catch (Throwable $e) {
                $failed++;
                $this->components->twoColumnDetail($id, '<fg=red>Failed: ' . $e->getMessage() . '</>');
            }
        }

        $this->components->info("Retried: {$retried}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Retry all failed jobs.
     */
    private function retryAll(?string $queue): int
    {
        $failedJobs = $this->jobRepository->getFailed($queue);

        if ($failedJobs->isEmpty()) {
            $this->components->info('No failed jobs found.');

            return self::SUCCESS;
        }

        $this->components->warn('Found ' . $failedJobs->count() . ' failed job(s).');

        if (!$this->confirm('Do you want to retry all failed jobs?', true)) {
            return self::SUCCESS;
        }

        $ids = $failedJobs->pluck('id')->all();

        return $this->retryJobs($ids);
    }

    /**
     * Retry a range of job IDs.
     */
    private function retryRange(string $range): int
    {
        if (!preg_match('/^(\d+)-(\d+)$/', $range, $matches)) {
            $this->components->error('Invalid range format. Use format: start-end (e.g., 1-100)');

            return self::FAILURE;
        }

        $start = (int) $matches[1];
        $end = (int) $matches[2];

        if ($start > $end) {
            $this->components->error('Start of range must be less than or equal to end.');

            return self::FAILURE;
        }

        $ids = array_map('strval', range($start, $end));

        return $this->retryJobs($ids);
    }
}
