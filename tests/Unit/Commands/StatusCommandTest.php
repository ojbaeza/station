<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\StatusCommand;
use Station\Contracts\JobRepositoryInterface;
use Station\Contracts\MetricsCollectorInterface;
use Station\Contracts\SupervisorRepositoryInterface;
use Station\Contracts\WorkerRepositoryInterface;
use Station\DTOs\JobStats;
use Station\StationServiceProvider;

class StatusCommandTest extends TestCase
{
    private MockInterface&JobRepositoryInterface $jobRepository;

    private MockInterface&SupervisorRepositoryInterface $supervisorRepository;

    private MockInterface&WorkerRepositoryInterface $workerRepository;

    private MockInterface&MetricsCollectorInterface $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobRepository = Mockery::mock(JobRepositoryInterface::class);
        $this->supervisorRepository = Mockery::mock(SupervisorRepositoryInterface::class);
        $this->workerRepository = Mockery::mock(WorkerRepositoryInterface::class);
        $this->metrics = Mockery::mock(MetricsCollectorInterface::class);

        $this->app->instance(JobRepositoryInterface::class, $this->jobRepository);
        $this->app->instance(SupervisorRepositoryInterface::class, $this->supervisorRepository);
        $this->app->instance(WorkerRepositoryInterface::class, $this->workerRepository);
        $this->app->instance(MetricsCollectorInterface::class, $this->metrics);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testStatusDisplaysAllSections(): void
    {
        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                [
                    'id' => 'sup-1',
                    'name' => 'default',
                    'pid' => 1234,
                    'status' => 'running',
                    'started_at' => '2025-01-27 10:00:00',
                ],
            ]));

        $this->workerRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([
                [
                    'id' => 'worker-1',
                    'supervisor_id' => 'sup-1',
                    'pid' => 1235,
                    'queue' => 'default',
                    'status' => 'processing',
                    'jobs_processed' => 150,
                ],
            ]));

        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([
                'default' => new JobStats(pending: 10, processing: 2, completed: 500, failed: 5),
            ]);

        $this->metrics->shouldReceive('getThroughput')->once()->andReturn(42.5);
        $this->metrics->shouldReceive('getAverageWaitTime')->once()->andReturn(1.5);
        $this->metrics->shouldReceive('getAverageProcessingTime')->once()->andReturn(0.75);
        $this->metrics->shouldReceive('getFailureRate')->once()->andReturn(0.01);

        $this->artisan(StatusCommand::class)
            ->assertExitCode(0);
    }

    public function testStatusWithNoSupervisorsShowsWarning(): void
    {
        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $this->workerRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([]);

        $this->metrics->shouldReceive('getThroughput')->once()->andReturn(0);
        $this->metrics->shouldReceive('getAverageWaitTime')->once()->andReturn(0);
        $this->metrics->shouldReceive('getAverageProcessingTime')->once()->andReturn(0);
        $this->metrics->shouldReceive('getFailureRate')->once()->andReturn(0);

        $this->artisan(StatusCommand::class)
            ->assertExitCode(0);
    }

    public function testStatusWithQueueFilterShowsOnlyMatchingQueue(): void
    {
        $this->supervisorRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $this->workerRepository->shouldReceive('getActive')
            ->once()
            ->andReturn(collect([]));

        $this->jobRepository->shouldReceive('getStatsByQueue')
            ->once()
            ->andReturn([
                'default' => new JobStats(pending: 10, processing: 0, completed: 100, failed: 0),
                'high-priority' => new JobStats(pending: 5, processing: 1, completed: 50, failed: 2),
            ]);

        $this->metrics->shouldReceive('getThroughput')->once()->andReturn(0);
        $this->metrics->shouldReceive('getAverageWaitTime')->once()->andReturn(0);
        $this->metrics->shouldReceive('getAverageProcessingTime')->once()->andReturn(0);
        $this->metrics->shouldReceive('getFailureRate')->once()->andReturn(0);

        $this->artisan(StatusCommand::class, ['--queue' => 'high-priority'])
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
