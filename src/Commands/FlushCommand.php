<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\JobRepositoryInterface;

final class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:flush
                            {--queue= : Only flush failed jobs from a specific queue}
                            {--hours= : Only flush jobs older than specified hours}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Flush all failed jobs';

    public function __construct(
        private readonly JobRepositoryInterface $jobRepository,
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
        $hoursOption = $this->option('hours');
        $hours = $hoursOption !== null ? (int) $hoursOption : null;
        $force = (bool) $this->option('force');

        $failedJobs = $this->jobRepository->getFailed($queue, $hours);
        $count = \count($failedJobs);

        if ($count === 0) {
            $this->components->info('No failed jobs to flush.');

            return self::SUCCESS;
        }

        $this->components->warn("Found {$count} failed job(s) to flush.");

        if (!$force && !$this->confirm('Are you sure you want to delete these failed jobs? This cannot be undone.')) {
            return self::SUCCESS;
        }

        $deleted = $this->jobRepository->flushFailed($queue, $hours);

        $this->components->info("Successfully flushed {$deleted} failed job(s).");

        return self::SUCCESS;
    }
}
