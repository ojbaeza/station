<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\FlushCommand;
use Station\Contracts\JobRepositoryInterface;
use Station\StationServiceProvider;

class FlushCommandTest extends TestCase
{
    private MockInterface&JobRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(JobRepositoryInterface::class);
        $this->app->instance(JobRepositoryInterface::class, $this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testFlushWithNoFailedJobsShowsInfo(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null, null)
            ->andReturn(collect());

        $this->artisan(FlushCommand::class)
            ->assertExitCode(0);
    }

    public function testFlushWithFailedJobsAsksConfirmation(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null, null)
            ->andReturn(collect([
                ['id' => 'job-1'],
                ['id' => 'job-2'],
            ]));

        $this->repository->shouldReceive('flushFailed')
            ->once()
            ->with(null, null)
            ->andReturn(2);

        $this->artisan(FlushCommand::class)
            ->expectsConfirmation('Are you sure you want to delete these failed jobs? This cannot be undone.', 'yes')
            ->assertExitCode(0);
    }

    public function testFlushWithDeclinedConfirmationDoesNotDelete(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->andReturn(collect([['id' => 'job-1']]));

        $this->repository->shouldNotReceive('flushFailed');

        $this->artisan(FlushCommand::class)
            ->expectsConfirmation('Are you sure you want to delete these failed jobs? This cannot be undone.', 'no')
            ->assertExitCode(0);
    }

    public function testFlushWithForceSkipsConfirmation(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->andReturn(collect([['id' => 'job-1']]));

        $this->repository->shouldReceive('flushFailed')
            ->once()
            ->andReturn(1);

        $this->artisan(FlushCommand::class, ['--force' => true])
            ->assertExitCode(0);
    }

    public function testFlushWithQueueFilterFiltersJobs(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with('high-priority', null)
            ->andReturn(collect([['id' => 'job-1']]));

        $this->repository->shouldReceive('flushFailed')
            ->once()
            ->with('high-priority', null)
            ->andReturn(1);

        $this->artisan(FlushCommand::class, ['--queue' => 'high-priority', '--force' => true])
            ->assertExitCode(0);
    }

    public function testFlushWithHoursFilterFiltersOldJobs(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null, 48)
            ->andReturn(collect([['id' => 'job-1']]));

        $this->repository->shouldReceive('flushFailed')
            ->once()
            ->with(null, 48)
            ->andReturn(1);

        $this->artisan(FlushCommand::class, ['--hours' => 48, '--force' => true])
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
