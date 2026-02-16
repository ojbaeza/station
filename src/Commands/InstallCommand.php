<?php

declare(strict_types=1);

namespace Station\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;

final class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'station:install
                            {--force : Overwrite existing configuration files}';

    /**
     * The console command description.
     */
    protected $description = 'Install Station resources';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Installing Station...');

        $this->publishConfiguration();
        $this->publishMigrations();
        $this->publishAssets();
        $this->updateEnvironment();

        $this->newLine();
        $this->components->info('Station installed successfully!');
        $this->newLine();

        $this->line('Next steps:');
        $this->line('  1. Review the configuration in <fg=yellow>config/station.php</>');
        $this->line('  2. Run <fg=yellow>php artisan migrate</> to create the database tables');
        $this->line('  3. Add your RabbitMQ credentials to <fg=yellow>.env</>');
        $this->line('  4. Start the supervisor with <fg=yellow>php artisan station:work</>');
        $this->newLine();
        /** @var string $dashboardPath */
        $dashboardPath = config('station.dashboard.path', 'station');
        $dashboardUrl = URL::to($dashboardPath);
        $this->line("Access the dashboard at: <fg=cyan>{$dashboardUrl}</>");

        return self::SUCCESS;
    }

    /**
     * Publish the configuration file.
     */
    private function publishConfiguration(): void
    {
        $force = (bool) $this->option('force');

        $this->callSilently('vendor:publish', [
            '--tag' => 'station-config',
            '--force' => $force,
        ]);

        $this->components->task('Publishing configuration', static fn() => true);
    }

    /**
     * Publish the database migrations.
     */
    private function publishMigrations(): void
    {
        $force = (bool) $this->option('force');

        $this->callSilently('vendor:publish', [
            '--tag' => 'station-migrations',
            '--force' => $force,
        ]);

        $this->components->task('Publishing migrations', static fn() => true);
    }

    /**
     * Publish the dashboard assets.
     */
    private function publishAssets(): void
    {
        $force = (bool) $this->option('force');

        $this->callSilently('vendor:publish', [
            '--tag' => 'station-assets',
            '--force' => $force,
        ]);

        $this->components->task('Publishing dashboard assets', static fn() => true);
    }

    /**
     * Update the environment file with Station variables.
     */
    private function updateEnvironment(): void
    {
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            $this->components->warn('.env file not found, skipping environment setup');

            return;
        }

        $env = File::get($envPath);

        // Check if Station variables already exist
        if (str_contains($env, 'STATION_')) {
            $this->components->task('Environment variables', static fn() => true);

            return;
        }

        $stationEnv = <<<'ENV'

# Station Queue Configuration
STATION_DRIVER=rabbitmq
STATION_DASHBOARD_ENABLED=true

# RabbitMQ Configuration
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=
RABBITMQ_PASSWORD=
RABBITMQ_VHOST=/
ENV;

        File::append($envPath, $stationEnv);

        $this->components->task('Adding environment variables', static fn() => true);
    }
}
