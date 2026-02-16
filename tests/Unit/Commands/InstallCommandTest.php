<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Illuminate\Support\Facades\File;
use Station\Commands\InstallCommand;
use Station\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = base_path('.env');
    }

    protected function tearDown(): void
    {
        // Clean up test .env file
        if (File::exists($this->envPath)) {
            File::delete($this->envPath);
        }
        parent::tearDown();
    }

    public function testInstallsSuccessfully(): void
    {
        // Create a minimal .env file
        File::put($this->envPath, "APP_NAME=TestApp\n");

        $this->artisan(InstallCommand::class)
            ->expectsOutputToContain('Installing Station')
            ->expectsOutputToContain('Station installed successfully')
            ->assertSuccessful();

        // Verify environment variables were added
        $env = File::get($this->envPath);
        $this->assertStringContainsString('STATION_DRIVER', $env);
        $this->assertStringContainsString('RABBITMQ_HOST', $env);
    }

    public function testSkipsEnvironmentWhenNoEnvFile(): void
    {
        // Make sure no .env file exists
        if (File::exists($this->envPath)) {
            File::delete($this->envPath);
        }

        $this->artisan(InstallCommand::class)
            ->expectsOutputToContain('Installing Station')
            ->expectsOutputToContain('Station installed successfully')
            ->assertSuccessful();
    }

    public function testSkipsEnvironmentWhenVariablesAlreadyExist(): void
    {
        // Create .env with existing Station variables
        File::put($this->envPath, "APP_NAME=TestApp\nSTATION_DRIVER=redis\n");

        $this->artisan(InstallCommand::class)
            ->expectsOutputToContain('Installing Station')
            ->assertSuccessful();

        // Verify RABBITMQ variables were not duplicated
        $env = File::get($this->envPath);
        $this->assertStringNotContainsString('RABBITMQ_HOST', $env);
    }

    public function testWithForceOption(): void
    {
        File::put($this->envPath, "APP_NAME=TestApp\n");

        $this->artisan(InstallCommand::class, ['--force' => true])
            ->expectsOutputToContain('Installing Station')
            ->expectsOutputToContain('Station installed successfully')
            ->assertSuccessful();
    }
}
