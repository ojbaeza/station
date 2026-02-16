<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Commands\RetryCommand;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\StationServiceProvider;

class RetryCommandTest extends TestCase
{
    private MockInterface&JobRepositoryInterface $repository;

    private MockInterface&JobManagerInterface $jobManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(JobRepositoryInterface::class);
        $this->jobManager = Mockery::mock(JobManagerInterface::class);

        $this->app->instance(JobRepositoryInterface::class, $this->repository);
        $this->app->instance(JobManagerInterface::class, $this->jobManager);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRetryWithSpecificIdsRetiesEachJob(): void
    {
        $this->jobManager->shouldReceive('retry')
            ->once()
            ->with('job-1');

        $this->jobManager->shouldReceive('retry')
            ->once()
            ->with('job-2');

        $this->artisan(RetryCommand::class, ['id' => ['job-1', 'job-2']])
            ->assertExitCode(0);
    }

    public function testRetryWithFailedJobShowsFailure(): void
    {
        $this->jobManager->shouldReceive('retry')
            ->once()
            ->with('job-1')
            ->andThrow(new RuntimeException('Job not found'));

        $this->artisan(RetryCommand::class, ['id' => ['job-1']])
            ->assertExitCode(1);
    }

    public function testRetryWithNoArgumentsShowsError(): void
    {
        $this->artisan(RetryCommand::class)
            ->assertExitCode(1);
    }

    public function testRetryAllWithNoFailedJobsShowsInfo(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null)
            ->andReturn(collect());

        $this->artisan(RetryCommand::class, ['--all' => true])
            ->assertExitCode(0);
    }

    public function testRetryAllWithFailedJobsAsksConfirmation(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null)
            ->andReturn(collect([
                (object) ['id' => 'job-1', 'name' => 'TestJob'],
                (object) ['id' => 'job-2', 'name' => 'TestJob'],
            ]));

        $this->jobManager->shouldReceive('retry')
            ->twice();

        $this->artisan(RetryCommand::class, ['--all' => true])
            ->expectsConfirmation('Do you want to retry all failed jobs?', 'yes')
            ->assertExitCode(0);
    }

    public function testRetryAllWithQueueFilterFiltersJobs(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with('high-priority')
            ->andReturn(collect([
                (object) ['id' => 'job-1', 'name' => 'TestJob'],
            ]));

        $this->jobManager->shouldReceive('retry')
            ->once()
            ->with('job-1');

        $this->artisan(RetryCommand::class, ['--all' => true, '--queue' => 'high-priority'])
            ->expectsConfirmation('Do you want to retry all failed jobs?', 'yes')
            ->assertExitCode(0);
    }

    public function testRetryRangeRetriesJobsInRange(): void
    {
        $this->jobManager->shouldReceive('retry')
            ->times(3);

        $this->artisan(RetryCommand::class, ['--range' => '1-3'])
            ->assertExitCode(0);
    }

    public function testRetryRangeWithInvalidFormatShowsError(): void
    {
        $this->artisan(RetryCommand::class, ['--range' => 'invalid'])
            ->assertExitCode(1);
    }

    public function testRetryRangeWithStartGreaterThanEndShowsError(): void
    {
        $this->artisan(RetryCommand::class, ['--range' => '10-5'])
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
