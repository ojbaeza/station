<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Station\Commands\PruneCommand;
use Station\StationServiceProvider;

class PruneCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    public function testPruneWithForceDeletesOldRecords(): void
    {
        // Insert old completed job
        DB::table('station_jobs')->insert([
            'id' => 'old-job',
            'status' => 'completed',
            'completed_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        // Insert recent completed job
        DB::table('station_jobs')->insert([
            'id' => 'recent-job',
            'status' => 'completed',
            'completed_at' => now()->subHours(12)->toDateTimeString(),
        ]);

        $this->artisan(PruneCommand::class, ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        // Old job should be deleted, recent job should remain
        $this->assertDatabaseMissing('station_jobs', ['id' => 'old-job']);
        $this->assertDatabaseHas('station_jobs', ['id' => 'recent-job']);
    }

    public function testPruneOnlyCompletedDeletesOnlyCompletedJobs(): void
    {
        DB::table('station_jobs')->insert([
            'id' => 'old-completed',
            'status' => 'completed',
            'completed_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        DB::table('station_failed_jobs')->insert([
            'failed_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        $this->artisan(PruneCommand::class, ['--force' => true, '--completed' => true, '--hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('station_jobs', ['id' => 'old-completed']);
        $this->assertDatabaseCount('station_failed_jobs', 1);
    }

    public function testPruneOnlyFailedDeletesOnlyFailedJobs(): void
    {
        DB::table('station_jobs')->insert([
            'id' => 'old-completed',
            'status' => 'completed',
            'completed_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        DB::table('station_failed_jobs')->insert([
            'failed_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        $this->artisan(PruneCommand::class, ['--force' => true, '--failed' => true, '--hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseHas('station_jobs', ['id' => 'old-completed']);
        $this->assertDatabaseCount('station_failed_jobs', 0);
    }

    public function testPruneOnlyMetricsDeletesOnlyMetrics(): void
    {
        DB::table('station_metrics')->insert([
            'recorded_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        DB::table('station_jobs')->insert([
            'id' => 'old-completed',
            'status' => 'completed',
            'completed_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        $this->artisan(PruneCommand::class, ['--force' => true, '--metrics' => true, '--hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseCount('station_metrics', 0);
        $this->assertDatabaseHas('station_jobs', ['id' => 'old-completed']);
    }

    public function testPruneOnlyCheckpointsDeletesOnlyCheckpoints(): void
    {
        DB::table('station_checkpoints')->insert([
            'created_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        $this->artisan(PruneCommand::class, ['--force' => true, '--checkpoints' => true, '--hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseCount('station_checkpoints', 0);
    }

    public function testPruneWithoutForceAsksConfirmation(): void
    {
        $this->artisan(PruneCommand::class, ['--hours' => 24])
            ->expectsConfirmation('Are you sure you want to prune old records? This cannot be undone.', 'no')
            ->assertExitCode(0);
    }

    public function testPruneStaleProcesses(): void
    {
        DB::table('station_supervisors')->insert([
            'id' => 'old-sup',
            'status' => 'terminated',
            'updated_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        DB::table('station_workers')->insert([
            'id' => 'old-worker',
            'status' => 'stopped',
            'updated_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        // Active supervisor should not be pruned
        DB::table('station_supervisors')->insert([
            'id' => 'active-sup',
            'status' => 'running',
            'updated_at' => now()->subHours(48)->toDateTimeString(),
        ]);

        $this->artisan(PruneCommand::class, ['--force' => true, '--hours' => 24])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('station_supervisors', ['id' => 'old-sup']);
        $this->assertDatabaseMissing('station_workers', ['id' => 'old-worker']);
        $this->assertDatabaseHas('station_supervisors', ['id' => 'active-sup']);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS station_jobs (
            id VARCHAR(255) PRIMARY KEY,
            status VARCHAR(50) NOT NULL,
            completed_at TIMESTAMP NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_failed_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            failed_at TIMESTAMP NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recorded_at TIMESTAMP NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_checkpoints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TIMESTAMP NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_job_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            occurred_at TIMESTAMP NOT NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_supervisors (
            id VARCHAR(255) PRIMARY KEY,
            status VARCHAR(50) NOT NULL,
            updated_at TIMESTAMP NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_workers (
            id VARCHAR(255) PRIMARY KEY,
            status VARCHAR(50) NOT NULL,
            updated_at TIMESTAMP NULL
        )');

        DB::statement('CREATE TABLE IF NOT EXISTS station_driver_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection VARCHAR(50) NOT NULL,
            queue_size INTEGER DEFAULT 0,
            memory_bytes INTEGER DEFAULT 0,
            consumers INTEGER DEFAULT 0,
            ops_rate REAL DEFAULT 0,
            recorded_at TIMESTAMP NOT NULL
        )');
    }
}
