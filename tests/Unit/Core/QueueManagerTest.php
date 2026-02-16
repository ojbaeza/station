<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue;
use Mockery;
use PHPUnit\Framework\TestCase;
use Station\Contracts\DriverInterface;
use Station\Core\QueueManager;

class QueueManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testIsPausedDelegatesToDriverInterface(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('isPaused')->with('emails')->andReturn(true);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('rabbitmq')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertTrue($manager->isPaused('emails', 'rabbitmq'));
    }

    public function testIsPausedReturnsFalseWhenNotPaused(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('isPaused')->with('default')->andReturn(false);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('redis')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertFalse($manager->isPaused('default', 'redis'));
    }

    public function testSizeDelegatesToDriver(): void
    {
        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->with('default')->andReturn(42);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('redis')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertSame(42, $manager->size('default', 'redis'));
    }

    public function testSizeReturnsZeroForEmptyQueue(): void
    {
        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->with('default')->andReturn(0);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('redis')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertSame(0, $manager->size('default', 'redis'));
    }

    public function testClearDelegatesToDriverInterface(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('clear')->with('emails')->andReturn(10);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('rabbitmq')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertSame(10, $manager->clear('emails', 'rabbitmq'));
    }

    public function testClearFallsBackToPopWhenNotDriverInterface(): void
    {
        $job = Mockery::mock(Job::class);

        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->with('emails')->andReturn(2);
        $driver->shouldReceive('pop')
            ->with('emails')
            ->andReturn($job, $job, null);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('test')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertSame(2, $manager->clear('emails', 'test'));
    }

    public function testClearFallbackWithEmptyQueue(): void
    {
        $driver = Mockery::mock(Queue::class);
        $driver->shouldReceive('size')->with('default')->andReturn(0);
        $driver->shouldReceive('pop')
            ->with('default')
            ->andReturn(null);

        $laravelManager = Mockery::mock(\Illuminate\Queue\QueueManager::class);
        $laravelManager->shouldReceive('connection')->with('test')->andReturn($driver);

        $manager = new QueueManager($laravelManager);

        $this->assertSame(0, $manager->clear('default', 'test'));
    }
}
