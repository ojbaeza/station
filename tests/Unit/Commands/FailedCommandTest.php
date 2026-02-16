<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Carbon\CarbonImmutable;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\FailedCommand;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\StationServiceProvider;

class FailedCommandTest extends TestCase
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

    public function testFailedWithNoJobsShowsInfo(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null, null, 50)
            ->andReturn(collect());

        $this->artisan(FailedCommand::class)
            ->assertExitCode(0);
    }

    public function testFailedDisplaysJobsInTable(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null, null, 50)
            ->andReturn(collect([
                $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default'),
            ]));

        $this->artisan(FailedCommand::class)
            ->assertExitCode(0);
    }

    public function testFailedWithQueueFilterFiltersJobs(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with('high-priority', null, 50)
            ->andReturn(collect());

        $this->artisan(FailedCommand::class, ['--queue' => 'high-priority'])
            ->assertExitCode(0);
    }

    public function testFailedWithLimitRespectsLimit(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->with(null, null, 10)
            ->andReturn(collect());

        $this->artisan(FailedCommand::class, ['--limit' => 10])
            ->assertExitCode(0);
    }

    public function testFailedWithShowExceptionDisplaysFullException(): void
    {
        $this->repository->shouldReceive('getFailed')
            ->once()
            ->andReturn(collect([
                $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 3),
            ]));

        $this->artisan(FailedCommand::class, ['--show-exception' => true])
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

    private function createJob(string $id, string $jobClass, string $queue, int $attempts = 1): Job
    {
        return new Job(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: serialize(['data' => 'test']),
            status: JobStatus::Failed->value,
            attempts: $attempts,
            completedAt: CarbonImmutable::now(),
        );
    }
}
