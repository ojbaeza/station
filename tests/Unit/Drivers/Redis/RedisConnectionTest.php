<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Redis;

use Exception;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Drivers\Redis\RedisConnection;
use Station\Exceptions\ConnectionException;

class RedisConnectionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&RedisFactory $redis;

    private MockInterface&Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock(RedisFactory::class);
        $this->connection = Mockery::mock(Connection::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testGetConnectionEstablishesConnectionOnFirstCall(): void
    {
        $this->redis->shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($this->connection);

        $redisConnection = new RedisConnection($this->redis, []);

        $connection = $redisConnection->getConnection();

        $this->assertSame($this->connection, $connection);
    }

    public function testGetConnectionReusesExistingConnection(): void
    {
        $this->redis->shouldReceive('connection')
            ->once()
            ->andReturn($this->connection);

        $redisConnection = new RedisConnection($this->redis, []);

        // Call twice
        $redisConnection->getConnection();
        $redisConnection->getConnection();

        // Connection should only be created once
    }

    public function testGetConnectionUsesCustomConnectionName(): void
    {
        $this->redis->shouldReceive('connection')
            ->once()
            ->with('custom-connection')
            ->andReturn($this->connection);

        $redisConnection = new RedisConnection($this->redis, [
            'connection' => 'custom-connection',
        ]);

        $connection = $redisConnection->getConnection();

        $this->assertSame($this->connection, $connection);
    }

    public function testGetConnectionThrowsConnectionException(): void
    {
        $this->redis->shouldReceive('connection')
            ->once()
            ->andThrow(new Exception('Connection failed'));

        $redisConnection = new RedisConnection($this->redis, []);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to connect to Redis: Connection failed');

        $redisConnection->getConnection();
    }

    public function testGetPrefixReturnsDefaultPrefix(): void
    {
        $redisConnection = new RedisConnection($this->redis, []);

        $this->assertSame('station:', $redisConnection->getPrefix());
    }

    public function testGetPrefixReturnsCustomPrefix(): void
    {
        $redisConnection = new RedisConnection($this->redis, [
            'prefix' => 'custom:',
        ]);

        $this->assertSame('custom:', $redisConnection->getPrefix());
    }

    public function testKeyBuildsKeyWithPrefix(): void
    {
        $redisConnection = new RedisConnection($this->redis, []);

        $this->assertSame('station:queues:default', $redisConnection->key('queues:default'));
    }

    public function testKeyBuildsKeyWithCustomPrefix(): void
    {
        $redisConnection = new RedisConnection($this->redis, [
            'prefix' => 'app:',
        ]);

        $this->assertSame('app:queues:default', $redisConnection->key('queues:default'));
    }

    public function testIsConnectedReturnsFalseWhenNotConnected(): void
    {
        $redisConnection = new RedisConnection($this->redis, []);

        $this->assertFalse($redisConnection->isConnected());
    }

    public function testIsConnectedReturnsTrueWhenConnected(): void
    {
        $this->redis->shouldReceive('connection')
            ->once()
            ->andReturn($this->connection);

        $this->connection->shouldReceive('ping')
            ->once()
            ->andReturn(true);

        $redisConnection = new RedisConnection($this->redis, []);
        $redisConnection->getConnection();

        $this->assertTrue($redisConnection->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenPingFails(): void
    {
        $this->redis->shouldReceive('connection')
            ->once()
            ->andReturn($this->connection);

        $this->connection->shouldReceive('ping')
            ->once()
            ->andThrow(new Exception('Ping failed'));

        $redisConnection = new RedisConnection($this->redis, []);
        $redisConnection->getConnection();

        $this->assertFalse($redisConnection->isConnected());
    }

    public function testDisconnectClearsConnection(): void
    {
        $this->redis->shouldReceive('connection')
            ->twice()
            ->andReturn($this->connection);

        $redisConnection = new RedisConnection($this->redis, []);
        $redisConnection->getConnection();
        $redisConnection->disconnect();

        // After disconnect, isConnected should return false
        $this->assertFalse($redisConnection->isConnected());

        // Next getConnection should create a new connection
        $redisConnection->getConnection();
    }

    public function testReconnectDisconnectsAndReconnects(): void
    {
        $this->redis->shouldReceive('connection')
            ->twice()
            ->andReturn($this->connection);

        $redisConnection = new RedisConnection($this->redis, []);
        $redisConnection->getConnection();
        $redisConnection->reconnect();
    }
}
