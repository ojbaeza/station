<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Contracts\CheckpointManagerInterface;
use Station\Contracts\HealthCheckerInterface;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Events\JobRecovered;
use Station\Recovery\JobResumer;

class JobResumerExtendedTest extends TestCase
{
    private JobManagerInterface&MockInterface $jobManager;

    private JobRepositoryInterface&MockInterface $repository;

    private CheckpointManagerInterface&MockInterface $checkpointManager;

    private Dispatcher&MockInterface $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobManager = Mockery::mock(JobManagerInterface::class);
        $this->repository = Mockery::mock(JobRepositoryInterface::class);
        $this->checkpointManager = Mockery::mock(CheckpointManagerInterface::class);
        $this->events = Mockery::mock(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRecoverAllReturnsZeroWhenDisabled(): void
    {
        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => false],
        );

        $this->assertSame(0, $resumer->recoverAll());
    }

    public function testRecoverAllRecoversStuckJobs(): void
    {
        $job1 = $this->createJob('job-1');
        $job2 = $this->createJob('job-2');

        $this->repository
            ->shouldReceive('getStuckJobs')
            ->once()
            ->with(90) // default heartbeat_timeout
            ->andReturn(new Collection([$job1, $job2]));

        // Both jobs get graceful (no checkpoint) -> forced restart
        $this->checkpointManager
            ->shouldReceive('get')
            ->with('job-1')
            ->andReturn(null);
        $this->checkpointManager
            ->shouldReceive('get')
            ->with('job-2')
            ->andReturn(null);

        $this->checkpointManager
            ->shouldReceive('delete')
            ->with('job-1')
            ->once();
        $this->checkpointManager
            ->shouldReceive('delete')
            ->with('job-2')
            ->once();

        $this->jobManager
            ->shouldReceive('retry')
            ->with('job-1')
            ->once();
        $this->jobManager
            ->shouldReceive('retry')
            ->with('job-2')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->with(Mockery::type(JobRecovered::class))
            ->twice();

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        $this->assertSame(2, $resumer->recoverAll());
    }

    public function testRecoverAllUsesConfiguredTimeout(): void
    {
        $this->repository
            ->shouldReceive('getStuckJobs')
            ->once()
            ->with(120)
            ->andReturn(new Collection([]));

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            [
                'enabled' => true,
                'thresholds' => ['heartbeat_timeout' => 120],
            ],
        );

        $this->assertSame(0, $resumer->recoverAll());
    }

    public function testRecoverAllWithNoStuckJobsReturnsZero(): void
    {
        $this->repository
            ->shouldReceive('getStuckJobs')
            ->once()
            ->with(90)
            ->andReturn(new Collection([]));

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        $this->assertSame(0, $resumer->recoverAll());
    }

    public function testRecoverAllPassesStrategyToResumeJob(): void
    {
        $job = $this->createJob('job-1');

        $this->repository
            ->shouldReceive('getStuckJobs')
            ->once()
            ->andReturn(new Collection([$job]));

        // 'restart' strategy goes directly to forced restart (no checkpoint check)
        $this->checkpointManager
            ->shouldReceive('delete')
            ->with('job-1')
            ->once();

        $this->jobManager
            ->shouldReceive('retry')
            ->with('job-1')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn(JobRecovered $event) => $event->strategy === 'forced_restart');

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        $this->assertSame(1, $resumer->recoverAll('restart'));
    }

    public function testHealthReturnsInjectedHealthChecker(): void
    {
        $healthChecker = Mockery::mock(HealthCheckerInterface::class);

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
            $healthChecker,
        );

        $this->assertSame($healthChecker, $resumer->health());
    }

    public function testResumeJobWithDefaultStrategyFallsBackToGracefulThenForced(): void
    {
        $job = $this->createJob('job-1');

        // No checkpoint -> graceful fails -> falls back to forced
        $this->checkpointManager
            ->shouldReceive('get')
            ->with('job-1')
            ->andReturn(null);

        $this->checkpointManager
            ->shouldReceive('delete')
            ->with('job-1')
            ->once();

        $this->jobManager
            ->shouldReceive('retry')
            ->with('job-1')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn(JobRecovered $event) => $event->strategy === 'forced_restart');

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        // 'unknown' strategy falls through to default branch
        $this->assertTrue($resumer->resumeJob($job, 'unknown'));
    }

    public function testCheckpointStrategyFallsBackToGracefulWhenNoCheckpoint(): void
    {
        $job = $this->createJob('job-1');

        // First get (partial recovery) returns null
        // Second get (graceful restart) also returns null
        $this->checkpointManager
            ->shouldReceive('get')
            ->with('job-1')
            ->twice()
            ->andReturn(null);

        // Falls all the way through to forced restart
        $this->checkpointManager
            ->shouldReceive('delete')
            ->with('job-1')
            ->once();

        $this->jobManager
            ->shouldReceive('retry')
            ->with('job-1')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn(JobRecovered $event) => $event->strategy === 'forced_restart');

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        $this->assertTrue($resumer->resumeJob($job, 'checkpoint'));
    }

    public function testPartialRecoveryWithoutLastProcessedIdStillRetries(): void
    {
        $job = $this->createJob('job-1');

        // Checkpoint exists but has no last_processed_id
        $this->checkpointManager
            ->shouldReceive('get')
            ->with('job-1')
            ->once()
            ->andReturn(['progress' => 50]);

        // save() should NOT be called since there's no last_processed_id
        $this->checkpointManager
            ->shouldNotReceive('save');

        $this->jobManager
            ->shouldReceive('retry')
            ->with('job-1')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn(JobRecovered $event) => $event->strategy === 'partial_recovery');

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        $this->assertTrue($resumer->resumeJob($job, 'checkpoint'));
    }

    private function createJob(string $id = 'job-123'): Job
    {
        return new Job(
            id: $id,
            queue: 'default',
            jobClass: 'App\\Jobs\\TestJob',
            payload: serialize(['data' => 'test']),
            status: JobStatus::Processing->value,
            startedAt: CarbonImmutable::now()->subMinutes(5),
        );
    }
}
