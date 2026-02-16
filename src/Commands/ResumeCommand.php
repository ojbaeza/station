<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\DriverInterface;

final class ResumeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:resume
                            {queue : The name of the queue to resume}
                            {--connection= : The queue connection name}';

    /**
     * The console command description.
     */
    protected $description = 'Resume processing for a paused queue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queueArg = $this->argument('queue');
        $queue = \is_string($queueArg) ? $queueArg : '';
        $connectionOption = $this->option('connection');
        $connection = \is_string($connectionOption) ? $connectionOption : config('station.default');

        $driver = app('queue')->connection($connection);

        if (!$driver instanceof DriverInterface) {
            $this->components->error('The queue driver does not support resuming.');

            return self::FAILURE;
        }

        $driver->resume($queue);

        $this->components->info("Queue [{$queue}] has been resumed on connection [{$connection}].");

        return self::SUCCESS;
    }
}
