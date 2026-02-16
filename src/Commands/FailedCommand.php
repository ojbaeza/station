<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;

final class FailedCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:failed
                            {--queue= : Filter by queue name}
                            {--limit=50 : Maximum number of jobs to display}
                            {--show-exception : Show the full exception for each job}';

    /**
     * The console command description.
     */
    protected $description = 'List all failed jobs';

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
        $limit = (int) $this->option('limit');
        $showException = (bool) $this->option('show-exception');

        $failedJobs = $this->jobRepository->getFailed($queue, null, $limit);

        if ($failedJobs->isEmpty()) {
            $this->components->info('No failed jobs found.');

            return self::SUCCESS;
        }

        $this->components->info('Failed Jobs');

        if ($showException) {
            $this->displayWithExceptions($failedJobs);
        } else {
            $this->displayTable($failedJobs);
        }

        return self::SUCCESS;
    }

    /**
     * Display failed jobs in a table.
     *
     * @param Collection<int, Job> $failedJobs
     */
    private function displayTable(Collection $failedJobs): void
    {
        $rows = [];

        foreach ($failedJobs as $job) {
            /** @var Job $job */
            $rows[] = [
                $job->id,
                'station',
                $job->queue,
                Str::limit($job->jobClass, 40),
                $job->completedAt?->toDateTimeString() ?? 'N/A',
            ];
        }

        $this->table(
            ['ID', 'Connection', 'Queue', 'Job', 'Failed At'],
            $rows,
        );
    }

    /**
     * Display failed jobs with full exceptions.
     *
     * @param Collection<int, Job> $failedJobs
     */
    private function displayWithExceptions(Collection $failedJobs): void
    {
        foreach ($failedJobs as $job) {
            /** @var Job $job */
            $this->newLine();
            $this->components->twoColumnDetail('Job ID', $job->id);
            $this->components->twoColumnDetail('Name', $job->jobClass);
            $this->components->twoColumnDetail('Queue', $job->queue);
            $this->components->twoColumnDetail('Failed At', $job->completedAt?->toDateTimeString() ?? 'N/A');
            $this->components->twoColumnDetail('Attempts', (string) $job->attempts);

            $this->line(str_repeat('-', 80));
        }
    }
}
