<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Illuminate\Queue\QueueManager as LaravelQueueManager;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\AggregateDriverInfoInterface;
use Station\Contracts\DriverInterface;
use Station\Core\DriverInfoCollector;
use Station\StationServiceProvider;
use stdClass;

class DriverInfoCollectorTest extends TestCase
{
    public function testGetAllReturnsInfoForStationDrivers(): void
    {
        $mockDriver = $this->createMock(DriverInterface::class);
        $mockDriver->expects($this->once())
            ->method('getDriverInfo')
            ->with('default')
            ->willReturn([
                'driver' => 'rabbitmq',
                'size' => 42,
                'messages_ready' => 30,
            ]);

        $queueManager = $this->createMock(LaravelQueueManager::class);
        $queueManager->expects($this->once())
            ->method('connection')
            ->with('rabbitmq')
            ->willReturn($mockDriver);

        config(['queue.connections' => [
            'rabbitmq' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
            ],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertArrayHasKey('rabbitmq', $result);
        $this->assertSame('rabbitmq', $result['rabbitmq']['connection']);
        $this->assertSame('rabbitmq', $result['rabbitmq']['driver']);
        $this->assertSame(42, $result['rabbitmq']['size']);
        $this->assertSame(30, $result['rabbitmq']['messages_ready']);
    }

    public function testGetAllSkipsNonStationDrivers(): void
    {
        $queueManager = $this->createMock(LaravelQueueManager::class);
        $queueManager->expects($this->never())
            ->method('connection');

        config(['queue.connections' => [
            'sync' => ['driver' => 'sync'],
            'database' => ['driver' => 'database'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertEmpty($result);
    }

    public function testGetAllHandlesConnectionErrors(): void
    {
        $queueManager = $this->createMock(LaravelQueueManager::class);
        $queueManager->expects($this->once())
            ->method('connection')
            ->with('redis')
            ->willThrowException(new RuntimeException('Connection refused'));

        config(['queue.connections' => [
            'redis' => [
                'driver' => 'redis',
                'queue' => 'default',
            ],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertArrayHasKey('redis', $result);
        $this->assertSame('redis', $result['redis']['connection']);
        $this->assertSame('redis', $result['redis']['driver']);
        $this->assertSame('Unable to connect', $result['redis']['error']);
    }

    public function testGetAllCollectsMultipleDrivers(): void
    {
        $rabbitDriver = $this->createStub(DriverInterface::class);
        $rabbitDriver->method('getDriverInfo')
            ->willReturn(['driver' => 'rabbitmq', 'size' => 10]);

        $redisDriver = $this->createStub(DriverInterface::class);
        $redisDriver->method('getDriverInfo')
            ->willReturn(['driver' => 'redis', 'size' => 20]);

        $queueManager = $this->createStub(LaravelQueueManager::class);
        $queueManager->method('connection')
            ->willReturnMap([
                ['rabbitmq', $rabbitDriver],
                ['redis', $redisDriver],
            ]);

        config(['queue.connections' => [
            'rabbitmq' => ['driver' => 'rabbitmq', 'queue' => 'default'],
            'redis' => ['driver' => 'redis', 'queue' => 'jobs'],
            'sync' => ['driver' => 'sync'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('rabbitmq', $result);
        $this->assertArrayHasKey('redis', $result);
    }

    public function testGetAllUsesDefaultQueueWhenNotConfigured(): void
    {
        $mockDriver = $this->createMock(DriverInterface::class);
        $mockDriver->expects($this->once())
            ->method('getDriverInfo')
            ->with('default')
            ->willReturn(['driver' => 'redis', 'size' => 0]);

        $queueManager = $this->createStub(LaravelQueueManager::class);
        $queueManager->method('connection')->willReturn($mockDriver);

        config(['queue.connections' => [
            'redis' => ['driver' => 'redis'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $collector->getAll();
    }

    public function testGetAllUsesConfiguredQueue(): void
    {
        $mockDriver = $this->createMock(DriverInterface::class);
        $mockDriver->expects($this->once())
            ->method('getDriverInfo')
            ->with('high-priority')
            ->willReturn(['driver' => 'sqs', 'size' => 5]);

        $queueManager = $this->createStub(LaravelQueueManager::class);
        $queueManager->method('connection')->willReturn($mockDriver);

        config(['queue.connections' => [
            'sqs' => ['driver' => 'sqs', 'queue' => 'high-priority'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $collector->getAll();
    }

    public function testGetAllSkipsNonDriverInterfaceConnections(): void
    {
        $nonStationQueue = new stdClass();

        $queueManager = $this->createStub(LaravelQueueManager::class);
        $queueManager->method('connection')
            ->willReturn($nonStationQueue);

        config(['queue.connections' => [
            'redis' => ['driver' => 'redis', 'queue' => 'default'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertArrayNotHasKey('redis', $result);
    }

    public function testGetAllUsesAggregateInterfaceWhenAvailable(): void
    {
        $mockDriver = $this->createMock(AggregateDriverStub::class);
        $mockDriver->expects($this->once())
            ->method('getAllDriverInfo')
            ->willReturn([
                'driver' => 'rabbitmq',
                'size' => 100,
                'messages_ready' => 80,
                'queues' => ['q1' => ['size' => 60], 'q2' => ['size' => 40]],
                'queues_total' => 2,
            ]);
        $mockDriver->expects($this->never())
            ->method('getDriverInfo');

        $queueManager = $this->createMock(LaravelQueueManager::class);
        $queueManager->expects($this->once())
            ->method('connection')
            ->with('rabbitmq')
            ->willReturn($mockDriver);

        config(['queue.connections' => [
            'rabbitmq' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
            ],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertArrayHasKey('rabbitmq', $result);
        $this->assertSame(100, $result['rabbitmq']['size']);
        $this->assertArrayHasKey('queues', $result['rabbitmq']);
        $this->assertSame(2, $result['rabbitmq']['queues_total']);
    }

    public function testGetAllFallsBackToSingleQueueWhenNotAggregate(): void
    {
        $mockDriver = $this->createMock(DriverInterface::class);
        $mockDriver->expects($this->once())
            ->method('getDriverInfo')
            ->with('my-queue')
            ->willReturn([
                'driver' => 'sqs',
                'size' => 5,
            ]);

        $queueManager = $this->createStub(LaravelQueueManager::class);
        $queueManager->method('connection')->willReturn($mockDriver);

        config(['queue.connections' => [
            'sqs' => ['driver' => 'sqs', 'queue' => 'my-queue'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertArrayHasKey('sqs', $result);
        $this->assertSame(5, $result['sqs']['size']);
        $this->assertArrayNotHasKey('queues', $result['sqs']);
    }

    public function testGetAllIncludesStationDriverConnections(): void
    {
        $mockDriver = $this->createStub(DriverInterface::class);
        $mockDriver->method('getDriverInfo')
            ->willReturn(['driver' => 'station', 'size' => 3]);

        $queueManager = $this->createStub(LaravelQueueManager::class);
        $queueManager->method('connection')->willReturn($mockDriver);

        config(['queue.connections' => [
            'my-conn' => ['driver' => 'station-redis', 'queue' => 'default'],
        ]]);

        $collector = new DriverInfoCollector($queueManager);
        $result = $collector->getAll();

        $this->assertArrayHasKey('my-conn', $result);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }
}

/**
 * Stub that implements both DriverInterface and AggregateDriverInfoInterface for testing.
 */
abstract class AggregateDriverStub implements AggregateDriverInfoInterface, DriverInterface {}
