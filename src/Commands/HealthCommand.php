<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\HealthCheckerInterface;

final class HealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:health
                            {--json : Output results as JSON}
                            {--fail-on-warning : Exit with failure code if any warnings}';

    /**
     * The console command description.
     */
    protected $description = 'Check the health of Station queues and workers';

    public function __construct(
        private readonly HealthCheckerInterface $healthChecker,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $healthResult = $this->healthChecker->check();
        $health = $healthResult->toArray();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($healthResult, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));

            return $this->getExitCode($health);
        }

        $this->displayHealth($health);

        return $this->getExitCode($health);
    }

    /**
     * Display health check results.
     *
     * @param array<string, mixed> $health
     */
    private function displayHealth(array $health): void
    {
        $status = $health['status'] ?? 'unknown';
        $statusColor = match ($status) {
            'healthy' => 'green',
            'degraded' => 'yellow',
            'unhealthy' => 'red',
            default => 'gray',
        };

        $this->newLine();
        $this->line("  <fg={$statusColor};options=bold>Station Health: " . strtoupper($status) . '</> ');
        $this->newLine();

        // Connection health
        $this->components->info('Connections');
        foreach ($health['connections'] ?? [] as $name => $connection) {
            $connStatus = $connection['connected'] ? '<fg=green>Connected</>' : '<fg=red>Disconnected</>';
            $latency = isset($connection['latency_ms']) ? " ({$connection['latency_ms']}ms)" : '';
            $this->components->twoColumnDetail($name, $connStatus . $latency);
        }

        // Supervisor health
        $this->components->info('Supervisors');
        $supervisors = $health['supervisors'] ?? [];
        if ($supervisors === []) {
            $this->components->warn('No active supervisors');
        } else {
            $this->components->twoColumnDetail('Active supervisors', (string) \count($supervisors));
            foreach ($supervisors as $supervisor) {
                $supervisorStatus = $supervisor['healthy'] ? '<fg=green>Healthy</>' : '<fg=red>Unhealthy</>';
                $this->components->twoColumnDetail(
                    "  {$supervisor['id']} (PID: {$supervisor['pid']})",
                    $supervisorStatus,
                );
            }
        }

        // Worker health
        $this->components->info('Workers');
        $workers = $health['workers'] ?? [];
        if ($workers === []) {
            $this->components->warn('No active workers');
        } else {
            $healthyWorkers = \count(array_filter($workers, static fn($w) => $w['healthy'] ?? false));
            $this->components->twoColumnDetail('Total workers', (string) \count($workers));
            $this->components->twoColumnDetail('Healthy workers', (string) $healthyWorkers);
        }

        // Queue health
        $this->components->info('Queues');
        foreach ($health['queues'] ?? [] as $queue => $queueHealth) {
            $queueStatus = $queueHealth['healthy'] ? '<fg=green>Healthy</>' : '<fg=red>Unhealthy</>';
            $details = "Size: {$queueHealth['size']}, Paused: " . ($queueHealth['paused'] ? 'Yes' : 'No');
            $this->components->twoColumnDetail($queue, $queueStatus . " ({$details})");
        }

        // Stuck jobs
        if (isset($health['stuck_jobs']) && $health['stuck_jobs'] > 0) {
            $this->newLine();
            $this->components->error("Warning: {$health['stuck_jobs']} stuck job(s) detected!");
            $this->line('  Run <fg=yellow>station:recover</> to recover stuck jobs.');
        }

        // Issues
        if (!empty($health['issues'])) {
            $this->newLine();
            $this->components->error('Issues:');
            foreach ($health['issues'] as $issue) {
                $this->line("  • {$issue}");
            }
        }
    }

    /**
     * Get the exit code based on health status.
     *
     * @param array<string, mixed> $health
     */
    private function getExitCode(array $health): int
    {
        $status = $health['status'] ?? 'unknown';
        $failOnWarning = (bool) $this->option('fail-on-warning');

        if ($status === 'unhealthy') {
            return self::FAILURE;
        }

        if ($failOnWarning && $status === 'degraded') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
