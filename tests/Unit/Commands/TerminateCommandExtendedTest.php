<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\TerminateCommand;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\StationServiceProvider;

/**
 * Extended tests for TerminateCommand covering:
 * - terminateSupervisor with --force flag (SIGKILL path)
 * - terminateSupervisor without --force flag (SIGTERM path, waitForTermination)
 * - terminateAll with multiple supervisors without --force (wait path)
 * - terminateAll with --force flag
 * - Edge: supervisor with zero PID
 */
class TerminateCommandExtendedTest extends TestCase
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

    public function testTerminateSupervisorGracefulWithInvalidPid(): void
    {
        // --force not set -> uses SIGTERM
        $this->supervisorRepository->shouldReceive('find')
            ->once()
            ->with('sup-1')
            ->andReturn(['id' => 'sup-1', 'pid' => 999998]);

        // posix_kill(999998, SIGTERM) will fail because PID doesn't exist
        $this->artisan(TerminateCommand::class, ['--supervisor' => 'sup-1'])
            ->assertExitCode(1); // Failure since PID doesn't exist
    }

    public function testTerminateAllGracefulWithInvalidPids(): void
    {
        $supervisors = collect([
            ['id' => 'sup-a', 'pid' => 999991],
            ['id' => 'sup-b', 'pid' => 999992],
        ]);

        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn($supervisors);

        $this->supervisorRepository->shouldReceive('markTerminated')
            ->twice();

        // Without --force, it sends SIGTERM and waits. PIDs don't exist so
        // posix_kill($pid, 0) returns false immediately -> no wait loop.
        $this->artisan(TerminateCommand::class, ['--wait' => 1])
            ->assertExitCode(0);
    }

    public function testTerminateAllForceWithSingleSupervisor(): void
    {
        $supervisors = collect([
            ['id' => 'sup-only', 'pid' => 999990],
        ]);

        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn($supervisors);

        $this->supervisorRepository->shouldReceive('markTerminated')
            ->once()
            ->with('sup-only');

        $this->artisan(TerminateCommand::class, ['--force' => true])
            ->assertExitCode(0);
    }

    public function testTerminateSupervisorForceBypassesWait(): void
    {
        $this->supervisorRepository->shouldReceive('find')
            ->once()
            ->with('sup-force')
            ->andReturn(['id' => 'sup-force', 'pid' => 999989]);

        // With --force, SIGKILL is sent. If posix_kill fails, it returns FAILURE.
        // The --force path skips the waitForTermination call.
        $this->artisan(TerminateCommand::class, [
            '--supervisor' => 'sup-force',
            '--force' => true,
        ])->assertExitCode(1); // Fails because PID doesn't exist
    }

    public function testTerminateWithZeroWaitTime(): void
    {
        $supervisors = collect([
            ['id' => 'sup-z', 'pid' => 999988],
        ]);

        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn($supervisors);

        $this->supervisorRepository->shouldReceive('markTerminated')
            ->once();

        // Wait time of 0 means the wait loop runs 0 iterations
        $this->artisan(TerminateCommand::class, ['--wait' => 0])
            ->assertExitCode(0);
    }

    public function testTerminateSupervisorNotFoundExitCode(): void
    {
        $this->supervisorRepository->shouldReceive('find')
            ->once()
            ->with('nonexistent')
            ->andReturn(null);

        $this->artisan(TerminateCommand::class, ['--supervisor' => 'nonexistent'])
            ->assertExitCode(1);
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
