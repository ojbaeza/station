<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Station\Contracts\WorkerSupervisorInterface;

final class WorkCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:work
                            {connection? : The queue connection to work (e.g., rabbitmq, redis, sqs, beanstalkd, kafka)}
                            {--queue= : The names of the queues to work (comma-separated)}
                            {--workers=1 : Number of worker processes}
                            {--memory=128 : The memory limit in megabytes}
                            {--timeout=60 : The number of seconds a job may run}
                            {--sleep=3 : Seconds to wait when no job is available}
                            {--tries=1 : Number of times to attempt a job}
                            {--max-jobs=0 : Number of jobs to process before stopping (0 = unlimited)}
                            {--max-time=0 : Maximum seconds the worker should run (0 = unlimited)}
                            {--daemon : Run the worker in daemon mode}
                            {--list : List all available queue connections}';

    /**
     * The console command description.
     */
    protected $description = 'Start a Station queue worker supervisor for a specific connection';

    public function __construct(
        private readonly WorkerSupervisorInterface $supervisor,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Handle --list option
        if ($this->option('list')) {
            return $this->listConnections();
        }

        $connectionArg = $this->argument('connection');
        $connection = \is_string($connectionArg) ? $connectionArg : (string) config('station.default');

        // Validate connection exists
        if (!$this->isValidConnection($connection)) {
            $this->components->error("Invalid connection: [{$connection}]");
            $this->components->info('Use --list to see available connections.');

            return self::FAILURE;
        }

        $queues = $this->getQueues($connection);
        $workerCount = (int) $this->option('workers');
        $memory = (int) $this->option('memory');
        $timeout = (int) $this->option('timeout');

        // Get driver name for display
        $driver = $this->getDriverName($connection);

        $this->components->info("Starting Station supervisor [{$connection}] with {$workerCount} worker(s)...");
        $this->components->bulletList([
            "Connection: {$connection}",
            "Driver: {$driver}",
            'Queues: ' . implode(', ', $queues),
            "Memory limit: {$memory}MB",
            "Timeout: {$timeout}s",
        ]);

        $options = [
            'connection' => $connection,
            'processes' => $workerCount,
            'memory' => (int) $this->option('memory'),
            'timeout' => (int) $this->option('timeout'),
            'sleep' => (int) $this->option('sleep'),
            'tries' => (int) $this->option('tries'),
            'maxJobs' => (int) $this->option('max-jobs'),
            'maxTime' => (int) $this->option('max-time'),
            'daemon' => (bool) $this->option('daemon'),
        ];

        $this->supervisor->start($queues, $options);

        return self::SUCCESS;
    }

    /**
     * List all available queue connections.
     */
    private function listConnections(): int
    {
        $this->components->info('Available Queue Connections');
        $this->newLine();

        /** @var array<string, array<string, mixed>> $connections */
        $connections = config('station.connections', []);
        $default = (string) config('station.default');

        if ($connections === []) {
            $this->components->warn('No connections configured in config/station.php');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($connections as $name => $config) {
            $driver = $config['driver'] ?? 'unknown';
            $queue = $this->getDefaultQueue($config);
            $isDefault = $name === $default ? '✓' : '';

            $rows[] = [
                $name,
                $driver,
                $queue,
                $isDefault,
            ];
        }

        $this->table(
            ['Connection', 'Driver', 'Default Queue', 'Default'],
            $rows,
        );

        $this->newLine();
        $this->components->info("Usage: php artisan station:work {connection} --queue={queues}");
        $this->newLine();
        $this->line('  <comment>Examples:</comment>');
        $this->line("    php artisan station:work {$default}");
        $this->line("    php artisan station:work {$default} --queue=high,default,low --workers=3");

        if (\count($connections) > 1) {
            $otherConnection = array_keys($connections)[1] ?? array_keys($connections)[0];
            if ($otherConnection !== $default) {
                $this->line("    php artisan station:work {$otherConnection} --queue=default --workers=2");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Check if a connection is valid.
     */
    private function isValidConnection(string $connection): bool
    {
        /** @var array<string, mixed>|null $config */
        $config = config("station.connections.{$connection}");

        return $config !== null;
    }

    /**
     * Get the driver name for a connection.
     */
    private function getDriverName(string $connection): string
    {
        /** @var string */
        return config("station.connections.{$connection}.driver", 'unknown');
    }

    /**
     * Get the default queue for a connection config.
     *
     * @param array<string, mixed> $config
     */
    private function getDefaultQueue(array $config): string
    {
        $queue = $config['queue'] ?? 'default';

        if (\is_array($queue)) {
            return implode(', ', $queue);
        }

        return (string) $queue;
    }

    /**
     * Get the queues to work.
     *
     * @return array<string>
     */
    private function getQueues(string $connection): array
    {
        $queuesOption = $this->option('queue');

        if (\is_string($queuesOption)) {
            return explode(',', $queuesOption);
        }

        // Get from connection config
        /** @var string|array<string>|null $configQueue */
        $configQueue = config("station.connections.{$connection}.queue");

        if ($configQueue !== null) {
            return \is_array($configQueue) ? $configQueue : [(string) $configQueue];
        }

        // Fall back to supervisor config
        /** @var array<string> */
        return config('station.supervisors.default.queues', ['default']);
    }
}
