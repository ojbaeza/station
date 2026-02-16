<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\WorkCommand;
use Station\Contracts\WorkerSupervisorInterface;
use Station\StationServiceProvider;

/**
 * Extended tests for WorkCommand covering:
 * - --list option with connections configured
 * - --list option with no connections
 * - Invalid connection name
 * - Queue from connection config when --queue not provided
 */
class WorkCommandExtendedTest extends TestCase
{
    private MockInterface&WorkerSupervisorInterface $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = Mockery::mock(WorkerSupervisorInterface::class);
        $this->app->instance(WorkerSupervisorInterface::class, $this->supervisor);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testListOptionShowsAvailableConnections(): void
    {
        $this->artisan(WorkCommand::class, ['--list' => true])
            ->assertExitCode(0);
    }

    public function testListOptionShowsNoConnectionsWarning(): void
    {
        // Override connections to empty
        $this->app['config']->set('station.connections', []);

        $this->artisan(WorkCommand::class, ['--list' => true])
            ->expectsOutputToContain('No connections configured')
            ->assertExitCode(0);
    }

    public function testInvalidConnectionReturnsFailure(): void
    {
        // supervisor should never be called
        $this->supervisor->shouldNotReceive('start');

        $this->artisan(WorkCommand::class, ['connection' => 'nonexistent'])
            ->assertExitCode(1);
    }

    public function testListOptionShowsMultipleConnections(): void
    {
        $this->app['config']->set('station.connections', [
            'rabbitmq' => ['driver' => 'rabbitmq', 'queue' => 'default'],
            'redis' => ['driver' => 'redis', 'queue' => 'jobs'],
        ]);

        $this->artisan(WorkCommand::class, ['--list' => true])
            ->assertExitCode(0);
    }

    public function testListOptionShowsConnectionWithArrayQueue(): void
    {
        $this->app['config']->set('station.connections', [
            'rabbitmq' => ['driver' => 'rabbitmq', 'queue' => ['high', 'default', 'low']],
        ]);

        $this->artisan(WorkCommand::class, ['--list' => true])
            ->assertExitCode(0);
    }

    public function testWorkUsesQueueFromConnectionConfig(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $queues === ['jobs']
                    && $options['connection'] === 'station');

        $this->artisan(WorkCommand::class)
            ->assertExitCode(0);
    }

    public function testWorkDefaultsToSupervisorQueuesWhenConnectionHasNoQueue(): void
    {
        // Remove queue from connection config
        $this->app['config']->set('station.connections.station', ['driver' => 'rabbitmq']);
        $this->app['config']->set('station.supervisors.default.queues', ['priority', 'normal']);

        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues) => $queues === ['priority', 'normal']);

        $this->artisan(WorkCommand::class)
            ->assertExitCode(0);
    }

    public function testWorkWithConnectionReturnsFailureForInvalid(): void
    {
        $this->supervisor->shouldNotReceive('start');

        $this->artisan(WorkCommand::class, ['connection' => 'bad_connection'])
            ->assertExitCode(1);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('station.default', 'station');
        $app['config']->set('station.connections.station', [
            'driver' => 'rabbitmq',
            'queue' => 'jobs',
        ]);
    }
}
