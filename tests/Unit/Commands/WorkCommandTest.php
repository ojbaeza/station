<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\WorkCommand;
use Station\Contracts\WorkerSupervisorInterface;
use Station\StationServiceProvider;

class WorkCommandTest extends TestCase
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

    public function testWorkStartsSupervisorWithDefaultOptions(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $queues === ['default']
                    && $options['connection'] === 'station'
                    && $options['processes'] === 1
                    && $options['memory'] === 128
                    && $options['timeout'] === 60
                    && $options['sleep'] === 3
                    && $options['tries'] === 1
                    && $options['maxJobs'] === 0
                    && $options['maxTime'] === 0
                    && $options['daemon'] === false);

        $this->artisan(WorkCommand::class)
            ->assertExitCode(0);
    }

    public function testWorkWithCustomConnection(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['connection'] === 'rabbitmq');

        $this->artisan(WorkCommand::class, ['connection' => 'rabbitmq'])
            ->assertExitCode(0);
    }

    public function testWorkWithCustomQueue(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $queues === ['high', 'low']);

        $this->artisan(WorkCommand::class, ['--queue' => 'high,low'])
            ->assertExitCode(0);
    }

    public function testWorkWithMultipleWorkers(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['processes'] === 4);

        $this->artisan(WorkCommand::class, ['--workers' => '4'])
            ->assertExitCode(0);
    }

    public function testWorkWithCustomMemoryLimit(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['memory'] === 256);

        $this->artisan(WorkCommand::class, ['--memory' => '256'])
            ->assertExitCode(0);
    }

    public function testWorkWithCustomTimeout(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['timeout'] === 120);

        $this->artisan(WorkCommand::class, ['--timeout' => '120'])
            ->assertExitCode(0);
    }

    public function testWorkWithCustomSleep(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['sleep'] === 5);

        $this->artisan(WorkCommand::class, ['--sleep' => '5'])
            ->assertExitCode(0);
    }

    public function testWorkWithCustomTries(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['tries'] === 3);

        $this->artisan(WorkCommand::class, ['--tries' => '3'])
            ->assertExitCode(0);
    }

    public function testWorkWithMaxJobs(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['maxJobs'] === 100);

        $this->artisan(WorkCommand::class, ['--max-jobs' => '100'])
            ->assertExitCode(0);
    }

    public function testWorkWithMaxTime(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['maxTime'] === 3600);

        $this->artisan(WorkCommand::class, ['--max-time' => '3600'])
            ->assertExitCode(0);
    }

    public function testWorkInDaemonMode(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $options['daemon'] === true);

        $this->artisan(WorkCommand::class, ['--daemon' => true])
            ->assertExitCode(0);
    }

    public function testWorkWithAllOptions(): void
    {
        $this->supervisor->shouldReceive('start')
            ->once()
            ->withArgs(static fn($queues, $options) => $queues === ['emails', 'notifications']
                    && $options['connection'] === 'redis'
                    && $options['processes'] === 8
                    && $options['memory'] === 512
                    && $options['timeout'] === 300
                    && $options['sleep'] === 1
                    && $options['tries'] === 5
                    && $options['maxJobs'] === 1000
                    && $options['maxTime'] === 7200
                    && $options['daemon'] === true);

        $this->artisan(WorkCommand::class, [
            'connection' => 'redis',
            '--queue' => 'emails,notifications',
            '--workers' => '8',
            '--memory' => '512',
            '--timeout' => '300',
            '--sleep' => '1',
            '--tries' => '5',
            '--max-jobs' => '1000',
            '--max-time' => '7200',
            '--daemon' => true,
        ])
            ->assertExitCode(0);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('station.default', 'station');
        $app['config']->set('station.connections.station.queue', ['default']);
    }
}
