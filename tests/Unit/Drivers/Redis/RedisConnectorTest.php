<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Redis;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Drivers\Redis\RedisConnector;
use Station\Drivers\Redis\RedisQueue;

class RedisConnectorTest extends TestCase
{
    private MockInterface&RedisFactory $redis;

    private MockInterface&Dispatcher $events;

    private MockInterface&Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock(RedisFactory::class);
        $this->events = Mockery::mock(Dispatcher::class);
        $this->connection = Mockery::mock(Connection::class);

        $this->redis->shouldReceive('connection')
            ->andReturn($this->connection);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConnectReturnsRedisQueue(): void
    {
        $connector = new RedisConnector($this->redis, $this->events);

        $config = [
            'connection' => 'default',
            'queue' => 'test-queue',
            'retry_after' => 90,
            'block_for' => 5,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(RedisQueue::class, $queue);
    }

    public function testConnectUsesDefaultQueueWhenNotConfigured(): void
    {
        $connector = new RedisConnector($this->redis, $this->events);

        $config = [
            'connection' => 'default',
            'retry_after' => 90,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(RedisQueue::class, $queue);
    }

    public function testConnectWithCustomConfiguration(): void
    {
        $connector = new RedisConnector($this->redis, $this->events);

        $config = [
            'connection' => 'custom',
            'queue' => 'custom-queue',
            'retry_after' => 120,
            'block_for' => 10,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(RedisQueue::class, $queue);
    }
}
