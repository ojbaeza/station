<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Illuminate\Support\Facades\File;
use Station\Commands\PublishSupervisorCommand;
use Station\Tests\TestCase;

class PublishSupervisorCommandTest extends TestCase
{
    private string $testConfPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testConfPath = storage_path('test-supervisor.conf');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testConfPath)) {
            File::delete($this->testConfPath);
        }
        parent::tearDown();
    }

    public function testGeneratesConfigurationWithDefaults(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'station']);

        $this->artisan(PublishSupervisorCommand::class)
            ->expectsQuestion('Do you want to write this configuration to /etc/supervisor/conf.d/station.conf?', false)
            ->assertSuccessful();
    }

    public function testGeneratesConfigWithCustomWorkers(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'station']);

        $this->artisan(PublishSupervisorCommand::class, ['--workers' => '5'])
            ->expectsQuestion('Do you want to write this configuration to /etc/supervisor/conf.d/station.conf?', false)
            ->assertSuccessful();
    }

    public function testGeneratesConfigWithCustomUser(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'station']);

        $this->artisan(PublishSupervisorCommand::class, ['--user' => 'www-data'])
            ->expectsQuestion('Do you want to write this configuration to /etc/supervisor/conf.d/station.conf?', false)
            ->assertSuccessful();
    }

    public function testWritesConfigurationToFile(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'station']);

        $this->artisan(PublishSupervisorCommand::class, ['--path' => $this->testConfPath])
            ->expectsQuestion("Do you want to write this configuration to {$this->testConfPath}?", true)
            ->assertSuccessful();

        $this->assertTrue(File::exists($this->testConfPath));
        $content = File::get($this->testConfPath);
        $this->assertStringContainsString('[program:testapp-station]', $content);
        $this->assertStringContainsString('numprocs=1', $content);
        $this->assertStringContainsString('station:work', $content);
    }

    public function testDeclineWritingConfiguration(): void
    {
        config(['app.name' => 'TestApp']);

        $this->artisan(PublishSupervisorCommand::class, ['--path' => $this->testConfPath])
            ->expectsQuestion("Do you want to write this configuration to {$this->testConfPath}?", false)
            ->assertSuccessful();

        $this->assertFalse(File::exists($this->testConfPath));
    }

    public function testConfigurationContainsCustomWorkers(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'station']);

        $this->artisan(PublishSupervisorCommand::class, [
            '--path' => $this->testConfPath,
            '--workers' => '5',
        ])
            ->expectsQuestion("Do you want to write this configuration to {$this->testConfPath}?", true)
            ->assertSuccessful();

        $content = File::get($this->testConfPath);
        $this->assertStringContainsString('--workers=5', $content);
    }

    public function testConfigurationContainsCustomUser(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'station']);

        $this->artisan(PublishSupervisorCommand::class, [
            '--path' => $this->testConfPath,
            '--user' => 'www-data',
        ])
            ->expectsQuestion("Do you want to write this configuration to {$this->testConfPath}?", true)
            ->assertSuccessful();

        $content = File::get($this->testConfPath);
        $this->assertStringContainsString('user=www-data', $content);
    }
}
