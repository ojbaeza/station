<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Station\Commands\PublishSupervisorCommand;
use Station\Tests\TestCase;

/**
 * Extended tests for PublishSupervisorCommand covering:
 * - Write failure path (File::put throws)
 * - Configuration with custom path and user combined
 */
class PublishSupervisorCommandExtendedTest extends TestCase
{
    public function testWriteConfigurationFailureReturnsFailureExitCode(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'station']);

        // Use an invalid path that will cause File::put to throw
        $invalidPath = '/nonexistent/deeply/nested/path/station.conf';

        File::shouldReceive('put')
            ->once()
            ->with($invalidPath, Mockery::type('string'))
            ->andThrow(new RuntimeException('Permission denied'));

        $this->artisan(PublishSupervisorCommand::class, ['--path' => $invalidPath])
            ->expectsQuestion("Do you want to write this configuration to {$invalidPath}?", true)
            ->expectsOutputToContain('Failed to write configuration')
            ->assertExitCode(1);
    }

    public function testGeneratesConfigWithCustomPathAndUser(): void
    {
        config(['app.name' => 'MyApp']);
        config(['station.default' => 'rabbitmq']);

        $testPath = storage_path('custom-supervisor.conf');

        $this->artisan(PublishSupervisorCommand::class, [
            '--path' => $testPath,
            '--user' => 'deploy',
            '--workers' => '4',
        ])
            ->expectsQuestion("Do you want to write this configuration to {$testPath}?", true)
            ->assertSuccessful();

        $this->assertTrue(File::exists($testPath));
        $content = File::get($testPath);

        $this->assertStringContainsString('[program:myapp-station]', $content);
        $this->assertStringContainsString('user=deploy', $content);
        $this->assertStringContainsString('--workers=4', $content);
        $this->assertStringContainsString('station:work rabbitmq', $content);

        // Clean up
        File::delete($testPath);
    }

    public function testGeneratesConfigUsesDefaultConnectionFromConfig(): void
    {
        config(['app.name' => 'TestApp']);
        config(['station.default' => 'sqs']);

        $this->artisan(PublishSupervisorCommand::class)
            ->expectsQuestion('Do you want to write this configuration to /etc/supervisor/conf.d/station.conf?', false)
            ->assertSuccessful();
    }
}
