<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\SupervisorRepositoryInterface;

final class TerminateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:terminate
                            {--supervisor= : Terminate a specific supervisor by ID}
                            {--wait=30 : Seconds to wait for graceful shutdown}
                            {--force : Force immediate termination}';

    /**
     * The console command description.
     */
    protected $description = 'Gracefully terminate Station supervisors and workers';

    public function __construct(
        private readonly SupervisorRepositoryInterface $supervisorRepository,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $supervisorOption = $this->option('supervisor');
        $supervisorId = \is_string($supervisorOption) ? $supervisorOption : null;
        $wait = (int) $this->option('wait');
        $force = (bool) $this->option('force');

        if ($supervisorId !== null) {
            return $this->terminateSupervisor($supervisorId, $wait, $force);
        }

        return $this->terminateAll($wait, $force);
    }

    /**
     * Terminate a specific supervisor.
     */
    private function terminateSupervisor(string $supervisorId, int $wait, bool $force): int
    {
        $supervisor = $this->supervisorRepository->find($supervisorId);

        if ($supervisor === null) {
            $this->components->error("Supervisor [{$supervisorId}] not found.");

            return self::FAILURE;
        }

        $signal = $force ? SIGKILL : SIGTERM;
        $signalName = $force ? 'SIGKILL' : 'SIGTERM';

        $this->components->info("Sending {$signalName} to supervisor [{$supervisorId}] (PID: {$supervisor['pid']})...");

        if (!posix_kill((int) $supervisor['pid'], $signal)) {
            $this->components->error('Failed to send signal to supervisor.');

            return self::FAILURE;
        }

        if (!$force) {
            $this->components->info("Waiting up to {$wait} seconds for graceful shutdown...");

            $this->waitForTermination((int) $supervisor['pid'], $wait);
        }

        $this->supervisorRepository->markTerminated($supervisorId);

        $this->components->info("Supervisor [{$supervisorId}] terminated.");

        return self::SUCCESS;
    }

    /**
     * Terminate all supervisors.
     */
    private function terminateAll(int $wait, bool $force): int
    {
        $supervisors = $this->supervisorRepository->getActive();

        if ($supervisors->isEmpty()) {
            $this->components->warn('No active supervisors found.');

            return self::SUCCESS;
        }

        $signal = $force ? SIGKILL : SIGTERM;
        $signalName = $force ? 'SIGKILL' : 'SIGTERM';

        $this->components->info("Terminating " . \count($supervisors) . " supervisor(s) with {$signalName}...");

        $pids = [];
        foreach ($supervisors as $supervisor) {
            $pid = (int) $supervisor['pid'];
            posix_kill($pid, $signal);
            $pids[] = $pid;
            $this->supervisorRepository->markTerminated($supervisor['id']);
        }

        if (!$force) {
            $this->components->info("Waiting up to {$wait} seconds for graceful shutdown...");

            foreach ($pids as $pid) {
                $this->waitForTermination($pid, $wait);
            }
        }

        $this->components->info('All supervisors terminated.');

        return self::SUCCESS;
    }

    /**
     * Wait for a process to terminate.
     */
    private function waitForTermination(int $pid, int $maxWait): void
    {
        $waited = 0;

        while ($waited < $maxWait && posix_kill($pid, 0)) {
            sleep(1);
            $waited++;
        }
    }
}
