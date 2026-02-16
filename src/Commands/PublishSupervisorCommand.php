<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class PublishSupervisorCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:publish-supervisor
                            {--path= : Custom path for the supervisor configuration}
                            {--user= : The user to run the supervisor process as}
                            {--workers=3 : Number of worker processes per supervisor}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a Supervisor configuration file for Station';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pathOption = $this->option('path');
        $path = \is_string($pathOption) ? $pathOption : '/etc/supervisor/conf.d/station.conf';
        $userOption = $this->option('user');
        $user = \is_string($userOption) ? $userOption : get_current_user();
        $workers = (int) $this->option('workers');

        $appPath = base_path();
        $appName = config('app.name', 'laravel');
        $phpBinary = PHP_BINARY;
        $connection = config('station.default', 'station');

        $config = $this->generateConfig(
            appName: $appName,
            appPath: $appPath,
            phpBinary: $phpBinary,
            user: $user,
            workers: $workers,
            connection: $connection,
        );

        $this->components->info('Generated Supervisor Configuration:');
        $this->newLine();
        $this->line($config);
        $this->newLine();

        if ($this->confirm("Do you want to write this configuration to {$path}?", false)) {
            try {
                File::put($path, $config);
                $this->components->info("Configuration written to {$path}");
                $this->newLine();
                $this->line('To start the supervisor:');
                $this->line('  sudo supervisorctl reread');
                $this->line('  sudo supervisorctl update');
                $this->line("  sudo supervisorctl start {$appName}-station:*");
            } catch (Throwable $e) {
                $this->components->error("Failed to write configuration: {$e->getMessage()}");
                $this->line('You may need to run this command with sudo or write the file manually.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Generate the Supervisor configuration.
     */
    private function generateConfig(
        string $appName,
        string $appPath,
        string $phpBinary,
        string $user,
        int $workers,
        string $connection,
    ): string {
        $programName = strtolower($appName) . '-station';
        $logPath = storage_path('logs');

        return <<<CONF
[program:{$programName}]
process_name=%(program_name)s_%(process_num)02d
command={$phpBinary} {$appPath}/artisan station:work {$connection} --workers={$workers} --daemon
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user={$user}
numprocs=1
redirect_stderr=true
stdout_logfile={$logPath}/station.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=60
stopsignal=SIGTERM

; Graceful shutdown configuration
; Wait up to 60 seconds for workers to finish current jobs
CONF;
    }
}
