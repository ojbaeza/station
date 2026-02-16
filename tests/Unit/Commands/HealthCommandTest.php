<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\HealthCommand;
use Station\Contracts\HealthCheckerInterface;
use Station\DTOs\HealthCheckResult;
use Station\StationServiceProvider;

class HealthCommandTest extends TestCase
{
    private MockInterface&HealthCheckerInterface $healthChecker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->healthChecker = Mockery::mock(HealthCheckerInterface::class);
        $this->app->instance(HealthCheckerInterface::class, $this->healthChecker);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHealthyStatusReturnsSuccess(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'supervisors' => [
                        ['id' => 'sup-1', 'pid' => 1234, 'healthy' => true],
                    ],
                    'workers' => [
                        ['id' => 'worker-1', 'healthy' => true],
                    ],
                    'queues' => [
                        'default' => ['healthy' => true, 'size' => 10, 'paused' => false],
                    ],
                ],
                connections: [
                    'rabbitmq' => ['connected' => true, 'latency_ms' => 5],
                ],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testUnhealthyStatusReturnsFailure(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'unhealthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'issues' => ['No active supervisors'],
                ],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(1);
    }

    public function testDegradedStatusReturnsSuccessByDefault(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'degraded',
                timestamp: now()->toIso8601String(),
                checks: [],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testDegradedStatusWithFailOnWarningReturnsFailure(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'degraded',
                timestamp: now()->toIso8601String(),
                checks: [],
            ));

        $this->artisan(HealthCommand::class, ['--fail-on-warning' => true])
            ->assertExitCode(1);
    }

    public function testJsonOutputReturnsValidJson(): void
    {
        $health = new HealthCheckResult(
            status: 'healthy',
            timestamp: now()->toIso8601String(),
            checks: [],
        );

        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn($health);

        $this->artisan(HealthCommand::class, ['--json' => true])
            ->expectsOutput(json_encode($health, JSON_PRETTY_PRINT))
            ->assertExitCode(0);
    }

    public function testStuckJobsShowsWarning(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'stuck_jobs' => 5,
                ],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testIssuesDisplaysProblems(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'issues' => [
                        'Worker pool running below capacity',
                        'Queue processing delayed',
                    ],
                ],
            ));

        $this->artisan(HealthCommand::class)
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
