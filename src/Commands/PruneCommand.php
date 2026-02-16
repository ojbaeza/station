<?php

declare(strict_types=1);

namespace Station\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:prune
                            {--hours=24 : Prune records older than specified hours}
                            {--completed : Only prune completed jobs}
                            {--failed : Only prune failed jobs}
                            {--metrics : Only prune metrics data}
                            {--checkpoints : Only prune checkpoints}
                            {--all : Prune all prunable data}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Prune old Station records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $force = (bool) $this->option('force');
        $before = now()->subHours($hours);

        $pruneCompleted = (bool) $this->option('completed');
        $pruneFailed = (bool) $this->option('failed');
        $pruneMetrics = (bool) $this->option('metrics');
        $pruneCheckpoints = (bool) $this->option('checkpoints');
        $pruneAll = (bool) $this->option('all');

        // If no specific option, prune all by default
        if (!$pruneCompleted && !$pruneFailed && !$pruneMetrics && !$pruneCheckpoints && !$pruneAll) {
            $pruneAll = true;
        }

        $this->components->info("Pruning records older than {$hours} hours (before {$before->toDateTimeString()})");

        if (!$force && !$this->confirm('Are you sure you want to prune old records? This cannot be undone.')) {
            return self::SUCCESS;
        }

        $totalDeleted = 0;

        if ($pruneAll || $pruneCompleted) {
            $deleted = $this->pruneCompletedJobs($before);
            $this->components->twoColumnDetail('Completed jobs pruned', (string) $deleted);
            $totalDeleted += $deleted;
        }

        if ($pruneAll || $pruneFailed) {
            $deleted = $this->pruneFailedJobs($before);
            $this->components->twoColumnDetail('Failed jobs pruned', (string) $deleted);
            $totalDeleted += $deleted;
        }

        if ($pruneAll || $pruneMetrics) {
            $deleted = $this->pruneMetrics($before);
            $this->components->twoColumnDetail('Metrics records pruned', (string) $deleted);
            $totalDeleted += $deleted;
        }

        if ($pruneAll || $pruneCheckpoints) {
            $deleted = $this->pruneCheckpoints($before);
            $this->components->twoColumnDetail('Checkpoints pruned', (string) $deleted);
            $totalDeleted += $deleted;
        }

        if ($pruneAll || $pruneMetrics) {
            $deleted = $this->pruneDriverSnapshots($before);
            $this->components->twoColumnDetail('Driver snapshots pruned', (string) $deleted);
            $totalDeleted += $deleted;
        }

        // Always prune old job events
        $deleted = $this->pruneJobEvents($before);
        $this->components->twoColumnDetail('Job events pruned', (string) $deleted);
        $totalDeleted += $deleted;

        // Prune stale supervisor and worker records
        $deleted = $this->pruneStaleProcesses($before);
        $this->components->twoColumnDetail('Stale process records pruned', (string) $deleted);
        $totalDeleted += $deleted;

        $this->newLine();
        $this->components->info("Total records pruned: {$totalDeleted}");

        return self::SUCCESS;
    }

    /**
     * Prune completed jobs.
     */
    private function pruneCompletedJobs(DateTimeInterface $before): int
    {
        return DB::table('station_jobs')
            ->where('status', 'completed')
            ->where('completed_at', '<', $before)
            ->delete();
    }

    /**
     * Prune failed jobs.
     */
    private function pruneFailedJobs(DateTimeInterface $before): int
    {
        return DB::table('station_failed_jobs')
            ->where('failed_at', '<', $before)
            ->delete();
    }

    /**
     * Prune metrics data.
     */
    private function pruneMetrics(DateTimeInterface $before): int
    {
        return DB::table('station_metrics')
            ->where('recorded_at', '<', $before)
            ->delete();
    }

    /**
     * Prune checkpoints.
     */
    private function pruneCheckpoints(DateTimeInterface $before): int
    {
        return DB::table('station_checkpoints')
            ->where('created_at', '<', $before)
            ->delete();
    }

    /**
     * Prune driver snapshots.
     */
    private function pruneDriverSnapshots(DateTimeInterface $before): int
    {
        return DB::table('station_driver_snapshots')
            ->where('recorded_at', '<', $before)
            ->delete();
    }

    /**
     * Prune job events.
     */
    private function pruneJobEvents(DateTimeInterface $before): int
    {
        return DB::table('station_job_events')
            ->where('occurred_at', '<', $before)
            ->delete();
    }

    /**
     * Prune stale supervisor and worker records.
     */
    private function pruneStaleProcesses(DateTimeInterface $before): int
    {
        $supervisors = DB::table('station_supervisors')
            ->where('status', 'terminated')
            ->where('updated_at', '<', $before)
            ->delete();

        $workers = DB::table('station_workers')
            ->where('status', 'stopped')
            ->where('updated_at', '<', $before)
            ->delete();

        return $supervisors + $workers;
    }
}
