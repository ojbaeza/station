<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Mockery;
use Station\Commands\ResumeCommand;
use Station\Contracts\DriverInterface;
use Station\Tests\TestCase;

class ResumeCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testResumesQueueSuccessfully(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('resume')
            ->with('high')
            ->once();

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('station')
            ->andReturn($driver);

        $this->app->instance('queue', $queueManager);
        config(['station.default' => 'station']);

        $this->artisan(ResumeCommand::class, ['queue' => 'high'])
            ->expectsOutputToContain('has been resumed')
            ->assertSuccessful();
    }

    public function testResumesQueueWithCustomConnection(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('resume')
            ->with('default')
            ->once();

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('custom-connection')
            ->andReturn($driver);

        $this->app->instance('queue', $queueManager);

        $this->artisan(ResumeCommand::class, [
            'queue' => 'default',
            '--connection' => 'custom-connection',
        ])
            ->expectsOutputToContain('has been resumed')
            ->assertSuccessful();
    }

    public function testFailsWhenDriverDoesNotSupportResuming(): void
    {
        // Standard Laravel Queue interface (not DriverInterface)
        $queue = Mockery::mock(Queue::class);

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')
            ->with('sync')
            ->andReturn($queue);

        $this->app->instance('queue', $queueManager);
        config(['station.default' => 'sync']);

        $this->artisan(ResumeCommand::class, ['queue' => 'default'])
            ->expectsOutputToContain('does not support resuming')
            ->assertFailed();
    }
}
