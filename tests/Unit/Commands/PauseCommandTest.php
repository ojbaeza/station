<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Mockery;
use Station\Commands\PauseCommand;
use Station\Contracts\DriverInterface;
use Station\Tests\TestCase;

class PauseCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPausesQueueSuccessfully(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('pause')
            ->with('high')
            ->once();

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('station')
            ->andReturn($driver);

        $this->app->instance('queue', $queueManager);
        config(['station.default' => 'station']);

        $this->artisan(PauseCommand::class, ['queue' => 'high'])
            ->expectsOutputToContain('has been paused')
            ->assertSuccessful();
    }

    public function testPausesQueueWithCustomConnection(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('pause')
            ->with('default')
            ->once();

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('custom-connection')
            ->andReturn($driver);

        $this->app->instance('queue', $queueManager);

        $this->artisan(PauseCommand::class, [
            'queue' => 'default',
            '--connection' => 'custom-connection',
        ])
            ->expectsOutputToContain('has been paused')
            ->assertSuccessful();
    }

    public function testFailsWhenDriverDoesNotSupportPausing(): void
    {
        // Standard Laravel Queue interface (not DriverInterface)
        $queue = Mockery::mock(Queue::class);

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('sync')
            ->andReturn($queue);

        $this->app->instance('queue', $queueManager);
        config(['station.default' => 'sync']);

        $this->artisan(PauseCommand::class, ['queue' => 'default'])
            ->expectsOutputToContain('does not support pausing')
            ->assertFailed();
    }
}
