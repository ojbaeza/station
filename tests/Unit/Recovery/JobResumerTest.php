<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Contracts\CheckpointManagerInterface;
use Station\Contracts\JobManagerInterface;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Events\JobRecovered;
use Station\Recovery\JobResumer;

class JobResumerTest extends TestCase
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

    public function testIsEnabledWithDefaultConfig(): void
    {
        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            [],
        );

        $this->assertTrue($resumer->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => false],
        );

        $this->assertFalse($resumer->isEnabled());
    }

    public function testResumeReturnsFalseWhenDisabled(): void
    {
        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => false],
        );

        $job = $this->createJob();

        $this->assertFalse($resumer->resumeJob($job));
    }

    public function testResumeByIdReturnsFalseWhenJobNotFound(): void
    {
        $this->repository
            ->shouldReceive('find')
            ->once()
            ->with('non-existent')
            ->andReturn(null);

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        $this->assertFalse($resumer->resume('non-existent'));
    }

    public function testResumeUsesGracefulRestartWhenCheckpointExists(): void
    {
        $job = $this->createJob();

        $this->checkpointManager
            ->shouldReceive('get')
            ->once()
            ->with('job-123')
            ->andReturn(['progress' => 50]);

        $this->jobManager
            ->shouldReceive('retry')
            ->once()
            ->with('job-123')
            ->andReturn(true);

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(JobRecovered::class));

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            [
                'enabled' => true,
                'strategies' => [
                    'graceful_restart' => true,
                    'forced_restart' => true,
                    'partial_recovery' => true,
                ],
            ],
        );

        $result = $resumer->resumeJob($job, 'graceful');

        $this->assertTrue($result);
    }

    public function testResumeUsesForcedRestartWhenNoCheckpoint(): void
    {
        $job = $this->createJob();

        $this->checkpointManager
            ->shouldReceive('get')
            ->once()
            ->with('job-123')
            ->andReturn(null);

        $this->checkpointManager
            ->shouldReceive('delete')
            ->once()
            ->with('job-123');

        $this->jobManager
            ->shouldReceive('retry')
            ->once()
            ->with('job-123')
            ->andReturn(true);

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn(JobRecovered $event) => $event->strategy === 'forced_restart'
                    && $event->fromCheckpoint === false);

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            [
                'enabled' => true,
                'strategies' => [
                    'graceful_restart' => true,
                    'forced_restart' => true,
                    'partial_recovery' => true,
                ],
            ],
        );

        // 'graceful' strategy tries graceful first, then falls back to forced
        $result = $resumer->resumeJob($job, 'graceful');

        $this->assertTrue($result);
    }

    public function testResumeWithRestartStrategyUsesForcedRestart(): void
    {
        $job = $this->createJob();

        $this->checkpointManager
            ->shouldReceive('delete')
            ->once()
            ->with('job-123');

        $this->jobManager
            ->shouldReceive('retry')
            ->once()
            ->with('job-123')
            ->andReturn(true);

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn(JobRecovered $event) => $event->strategy === 'forced_restart');

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            [
                'enabled' => true,
            ],
        );

        // 'restart' strategy goes directly to forced restart
        $result = $resumer->resumeJob($job, 'restart');

        $this->assertTrue($result);
    }

    public function testResumeWithCheckpointStrategyTriesPartialRecovery(): void
    {
        $job = $this->createJob();

        // checkpoint strategy: partial -> graceful -> forced
        $this->checkpointManager
            ->shouldReceive('get')
            ->with('job-123')
            ->once()
            ->andReturn(['last_processed_id' => 'item-5']);

        $this->checkpointManager
            ->shouldReceive('save')
            ->once()
            ->with('job-123', Mockery::on(static fn($data) => isset($data['skipped_ids'])
                    && \in_array('item-5', $data['skipped_ids'], true)));

        $this->jobManager
            ->shouldReceive('retry')
            ->once()
            ->with('job-123')
            ->andReturn(true);

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn(JobRecovered $event) => $event->strategy === 'partial_recovery');

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            [
                'enabled' => true,
            ],
        );

        // 'checkpoint' strategy tries partial recovery first
        $result = $resumer->resumeJob($job, 'checkpoint');

        $this->assertTrue($result);
    }

    public function testResumeByIdFindsJobAndResumes(): void
    {
        $job = $this->createJob();

        $this->repository
            ->shouldReceive('find')
            ->once()
            ->with('job-123')
            ->andReturn($job);

        // graceful strategy: graceful -> forced
        $this->checkpointManager
            ->shouldReceive('get')
            ->once()
            ->with('job-123')
            ->andReturn(null);

        $this->checkpointManager
            ->shouldReceive('delete')
            ->once()
            ->with('job-123');

        $this->jobManager
            ->shouldReceive('retry')
            ->once()
            ->with('job-123')
            ->andReturn(true);

        $this->events
            ->shouldReceive('dispatch')
            ->once();

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            ['enabled' => true],
        );

        $result = $resumer->resume('job-123');

        $this->assertTrue($result);
    }

    public function testPartialRecoveryAddsSkippedIds(): void
    {
        $job = $this->createJob();

        $existingCheckpoint = [
            'last_processed_id' => 'item-10',
            'skipped_ids' => ['item-5'],
        ];

        $this->checkpointManager
            ->shouldReceive('get')
            ->with('job-123')
            ->once()
            ->andReturn($existingCheckpoint);

        $this->checkpointManager
            ->shouldReceive('save')
            ->once()
            ->with('job-123', Mockery::on(static fn($data) => $data['skipped_ids'] === ['item-5', 'item-10']));

        $this->jobManager
            ->shouldReceive('retry')
            ->once()
            ->with('job-123')
            ->andReturn(true);

        $this->events
            ->shouldReceive('dispatch')
            ->once();

        $resumer = new JobResumer(
            $this->jobManager,
            $this->repository,
            $this->checkpointManager,
            $this->events,
            [
                'enabled' => true,
            ],
        );

        // 'checkpoint' strategy for partial recovery
        $result = $resumer->resumeJob($job, 'checkpoint');

        $this->assertTrue($result);
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
