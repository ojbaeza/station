<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Contracts\JobRepositoryInterface;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Recovery\StuckJobDetector;

class StuckJobDetectorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&JobRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(JobRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testIsEnabledReturnsTrueByDefault(): void
    {
        $detector = new StuckJobDetector($this->repository, []);

        $this->assertTrue($detector->isEnabled());
    }

    public function testIsEnabledRespectsConfig(): void
    {
        $detector = new StuckJobDetector($this->repository, ['enabled' => false]);

        $this->assertFalse($detector->isEnabled());
    }

    public function testDetectReturnsEmptyWhenDisabled(): void
    {
        $detector = new StuckJobDetector($this->repository, ['enabled' => false]);

        $result = $detector->detect();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testDetectReturnsStuckJobs(): void
    {
        $stuckJobs = collect([
            new Job(
                id: 'stuck-1',
                queue: 'default',
                jobClass: 'TestJob',
                payload: '{}',
                status: JobStatus::Processing->value,
            ),
        ]);

        $this->repository->shouldReceive('getStuckJobs')
            ->once()
            ->with(90)
            ->andReturn($stuckJobs);

        $detector = new StuckJobDetector($this->repository, ['enabled' => true]);

        $result = $detector->detect();

        $this->assertCount(1, $result);
        $this->assertSame('stuck-1', $result->first()->id);
    }

    public function testDetectUsesCustomTimeout(): void
    {
        $this->repository->shouldReceive('getStuckJobs')
            ->once()
            ->with(120)
            ->andReturn(collect([]));

        $detector = new StuckJobDetector($this->repository, [
            'enabled' => true,
            'thresholds' => ['heartbeat_timeout' => 120],
        ]);

        $detector->detect();
    }

    public function testCalculateStuckScoreForRecentJob(): void
    {
        $job = new Job(
            id: 'recent-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            timeout: 60,
            startedAt: CarbonImmutable::now()->subSeconds(10),
        );

        $detector = new StuckJobDetector($this->repository, []);

        $score = $detector->calculateStuckScore($job);

        $this->assertLessThan(0.5, $score);
    }

    public function testCalculateStuckScoreForOldJob(): void
    {
        // Create a job that has been running for 10 minutes
        $startedAt = CarbonImmutable::now()->subMinutes(10);

        $job = new Job(
            id: 'old-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            timeout: 60,
            startedAt: $startedAt,
        );

        $detector = new StuckJobDetector($this->repository, [
            'thresholds' => [
                'heartbeat_timeout' => 90, // 90 seconds
                'runtime_multiplier' => 1.5, // 60 * 1.5 = 90 seconds expected max
            ],
        ]);

        $score = $detector->calculateStuckScore($job);

        // A 10-minute old job should have a high stuck score
        // heartbeat weight (0.4) + runtime weight (0.3) = 0.7
        $this->assertGreaterThanOrEqual(0.7, $score);
    }

    public function testCalculateStuckScoreWithNullStartedAt(): void
    {
        $job = new Job(
            id: 'no-start-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Pending->value,
        );

        $detector = new StuckJobDetector($this->repository, []);

        $score = $detector->calculateStuckScore($job);

        $this->assertSame(0.0, $score);
    }

    public function testIsStuckReturnsTrueForHighScore(): void
    {
        $job = new Job(
            id: 'stuck-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            timeout: 60,
            startedAt: CarbonImmutable::now()->subMinutes(10),
        );

        $detector = new StuckJobDetector($this->repository, [
            'stuck_threshold' => 0.5,
            'thresholds' => [
                'heartbeat_timeout' => 90,
                'runtime_multiplier' => 1.5,
            ],
        ]);

        $this->assertTrue($detector->isStuck($job));
    }

    public function testIsStuckReturnsFalseForLowScore(): void
    {
        $job = new Job(
            id: 'normal-job',
            queue: 'default',
            jobClass: 'TestJob',
            payload: '{}',
            status: JobStatus::Processing->value,
            timeout: 60,
            startedAt: CarbonImmutable::now()->subSeconds(10),
        );

        $detector = new StuckJobDetector($this->repository, [
            'stuck_threshold' => 0.7,
        ]);

        $this->assertFalse($detector->isStuck($job));
    }
}
