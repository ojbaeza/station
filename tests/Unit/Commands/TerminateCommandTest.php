<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\TerminateCommand;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\StationServiceProvider;

class TerminateCommandTest extends TestCase
{
    private MockInterface&SupervisorRepositoryInterface $supervisorRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisorRepository = Mockery::mock(SupervisorRepositoryInterface::class);
        $this->app->instance(SupervisorRepositoryInterface::class, $this->supervisorRepository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testTerminateWithNoActiveSupervisors(): void
    {
        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $this->artisan(TerminateCommand::class)
            ->assertExitCode(0);
    }

    public function testTerminateSpecificSupervisorNotFound(): void
    {
        $this->supervisorRepository->shouldReceive('find')
            ->once()
            ->with('unknown-sup')
            ->andReturn(null);

        $this->artisan(TerminateCommand::class, ['--supervisor' => 'unknown-sup'])
            ->assertExitCode(1);
    }

    public function testTerminateSpecificSupervisorWithForceFailsWithInvalidPid(): void
    {
        $this->supervisorRepository->shouldReceive('find')
            ->once()
            ->with('sup-1')
            ->andReturn(['id' => 'sup-1', 'pid' => 999999]);

        // posix_kill will fail with a non-existent PID
        $this->artisan(TerminateCommand::class, [
            '--supervisor' => 'sup-1',
            '--force' => true,
        ])
            ->assertExitCode(1); // Failure due to invalid PID
    }

    public function testTerminateWithCustomWaitTime(): void
    {
        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $this->artisan(TerminateCommand::class, ['--wait' => 60, '--force' => true])
            ->assertExitCode(0);
    }

    public function testTerminateWithForceUsesSignalKill(): void
    {
        // Test with an invalid PID to verify the force path is taken
        $this->supervisorRepository->shouldReceive('find')
            ->once()
            ->with('sup-force')
            ->andReturn(['id' => 'sup-force', 'pid' => 999888]);

        $this->artisan(TerminateCommand::class, [
            '--supervisor' => 'sup-force',
            '--force' => true,
        ])
            ->assertExitCode(1); // Will fail due to invalid PID
    }

    public function testTerminateAllWithInvalidPidsStillMarksTerminated(): void
    {
        // When posix_kill fails, the loop continues and marks terminated
        $supervisors = collect([
            ['id' => 'sup-1', 'pid' => 999997],
            ['id' => 'sup-2', 'pid' => 999996],
        ]);

        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn($supervisors);

        // markTerminated is called in the loop, regardless of posix_kill result
        $this->supervisorRepository->shouldReceive('markTerminated')
            ->twice();

        // With force flag, terminateAll doesn't wait
        $this->artisan(TerminateCommand::class, ['--force' => true])
            ->assertExitCode(0);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
    }
}
