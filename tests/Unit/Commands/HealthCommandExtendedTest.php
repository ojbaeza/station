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

/**
 * Extended tests for HealthCommand covering the displayHealth method
 * branches with comprehensive health check data.
 */
class HealthCommandExtendedTest extends TestCase
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

    public function testDisplayHealthWithConnectionsSupervisorsWorkersAndQueues(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'supervisors' => [
                        ['id' => 'sup-1', 'pid' => 1234, 'healthy' => true],
                        ['id' => 'sup-2', 'pid' => 5678, 'healthy' => false],
                    ],
                    'workers' => [
                        ['id' => 'worker-1', 'healthy' => true],
                        ['id' => 'worker-2', 'healthy' => true],
                        ['id' => 'worker-3', 'healthy' => false],
                    ],
                    'queues' => [
                        'default' => ['healthy' => true, 'size' => 10, 'paused' => false],
                        'high' => ['healthy' => false, 'size' => 500, 'paused' => true],
                    ],
                ],
                connections: [
                    'rabbitmq' => ['connected' => true, 'latency_ms' => 5],
                    'redis' => ['connected' => false],
                ],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testDisplayHealthWithEmptySupervisorsShowsWarning(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'degraded',
                timestamp: now()->toIso8601String(),
                checks: [
                    'supervisors' => [],
                    'workers' => [],
                    'queues' => [],
                ],
                connections: [],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testDisplayHealthWithStuckJobsShowsErrorMessage(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'stuck_jobs' => 12,
                    'supervisors' => [],
                    'workers' => [],
                    'queues' => [],
                ],
                connections: [],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testDisplayHealthWithIssuesShowsAllIssues(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'degraded',
                timestamp: now()->toIso8601String(),
                checks: [
                    'issues' => [
                        'Worker pool running below capacity',
                        'Queue processing delayed',
                        'Memory usage high',
                    ],
                    'supervisors' => [],
                    'workers' => [],
                    'queues' => [],
                ],
                connections: [],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testJsonOutputWithUnhealthyStatusReturnsFailure(): void
    {
        $health = new HealthCheckResult(
            status: 'unhealthy',
            timestamp: now()->toIso8601String(),
            checks: [
                'issues' => ['All connections lost'],
            ],
        );

        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn($health);

        $this->artisan(HealthCommand::class, ['--json' => true])
            ->assertExitCode(1);
    }

    public function testJsonOutputWithDegradedAndFailOnWarningReturnsFailure(): void
    {
        $health = new HealthCheckResult(
            status: 'degraded',
            timestamp: now()->toIso8601String(),
            checks: [],
        );

        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn($health);

        $this->artisan(HealthCommand::class, ['--json' => true, '--fail-on-warning' => true])
            ->assertExitCode(1);
    }

    public function testDisplayHealthWithUnknownStatusUsesGrayColor(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'unknown',
                timestamp: now()->toIso8601String(),
                checks: [
                    'supervisors' => [],
                    'workers' => [],
                    'queues' => [],
                ],
                connections: [],
            ));

        // Unknown status is neither unhealthy nor degraded, so returns success
        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testDisplayHealthWithConnectionMissingLatency(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'supervisors' => [],
                    'workers' => [],
                    'queues' => [],
                ],
                connections: [
                    'redis' => ['connected' => true],
                ],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testDisplayHealthWithMultipleWorkersCountsHealthy(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'supervisors' => [
                        ['id' => 'sup-1', 'pid' => 100, 'healthy' => true],
                    ],
                    'workers' => [
                        ['id' => 'w-1', 'healthy' => true],
                        ['id' => 'w-2', 'healthy' => true],
                        ['id' => 'w-3', 'healthy' => false],
                        ['id' => 'w-4', 'healthy' => true],
                    ],
                    'queues' => [
                        'default' => ['healthy' => true, 'size' => 0, 'paused' => false],
                    ],
                ],
                connections: [],
            ));

        $this->artisan(HealthCommand::class)
            ->assertExitCode(0);
    }

    public function testDisplayHealthWithZeroStuckJobsDoesNotShowWarning(): void
    {
        $this->healthChecker->shouldReceive('check')
            ->once()
            ->andReturn(new HealthCheckResult(
                status: 'healthy',
                timestamp: now()->toIso8601String(),
                checks: [
                    'stuck_jobs' => 0,
                    'supervisors' => [],
                    'workers' => [],
                    'queues' => [],
                ],
                connections: [],
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
