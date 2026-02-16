<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\RabbitMQ;

use AMQPChannel;
use AMQPConnection;
use AMQPExchange;
use AMQPQueue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Station\Drivers\RabbitMQ\RabbitMQConnection;
use Station\Exceptions\ConnectionException;

class RabbitMQConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testIsConnectedReturnsFalseWhenNoConnection(): void
    {
        $connection = new RabbitMQConnection([]);

        $this->assertFalse($connection->isConnected());
    }

    public function testIsConnectedReturnsTrueWhenConnected(): void
    {
        $amqpConn = Mockery::mock(AMQPConnection::class);
        $amqpConn->shouldReceive('isConnected')
            ->once()
            ->andReturn(true);

        $connection = new RabbitMQConnection([]);
        $this->injectConnection($connection, $amqpConn);

        $this->assertTrue($connection->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenDisconnected(): void
    {
        $amqpConn = Mockery::mock(AMQPConnection::class);
        $amqpConn->shouldReceive('isConnected')
            ->once()
            ->andReturn(false);

        $connection = new RabbitMQConnection([]);
        $this->injectConnection($connection, $amqpConn);

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectClearsConnections(): void
    {
        $amqpConn = Mockery::mock(AMQPConnection::class);
        $amqpConn->shouldReceive('isConnected')
            ->andReturn(true);
        $amqpConn->shouldReceive('disconnect')
            ->once();

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')
            ->andReturn(true);

        $connection = new RabbitMQConnection([]);
        $this->injectConnection($connection, $amqpConn);
        $this->injectChannel($connection, $channel);

        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectWhenNotConnected(): void
    {
        $connection = new RabbitMQConnection([]);

        // Should not throw
        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testReconnectDisconnectsAndConnects(): void
    {
        // When we call reconnect without hosts configured, it throws
        $connection = new RabbitMQConnection([]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('No RabbitMQ hosts configured');

        $connection->reconnect();
    }

    public function testGetConnectionThrowsWhenNoHostsConfigured(): void
    {
        $connection = new RabbitMQConnection([]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('No RabbitMQ hosts configured');

        $connection->getConnection();
    }

    public function testGetConnectionReturnsExistingConnection(): void
    {
        $amqpConn = Mockery::mock(AMQPConnection::class);
        $amqpConn->shouldReceive('isConnected')
            ->andReturn(true);

        $connection = new RabbitMQConnection([]);
        $this->injectConnection($connection, $amqpConn);

        $result = $connection->getConnection();

        $this->assertSame($amqpConn, $result);
    }

    public function testGetChannelReturnsChannel(): void
    {
        $amqpConn = Mockery::mock(AMQPConnection::class);
        $amqpConn->shouldReceive('isConnected')
            ->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')
            ->andReturn(true);

        $connection = new RabbitMQConnection([]);
        $this->injectConnection($connection, $amqpConn);
        $this->injectChannel($connection, $channel);

        $result = $connection->getChannel();

        $this->assertSame($channel, $result);
    }

    public function testGetExchangeReturnsCachedExchange(): void
    {
        $exchange = Mockery::mock(AMQPExchange::class);

        $connection = new RabbitMQConnection([]);
        $this->injectExchange($connection, 'test-exchange', $exchange);

        $result = $connection->getExchange('test-exchange');

        $this->assertSame($exchange, $result);
    }

    public function testGetQueueReturnsCachedQueue(): void
    {
        $queue = Mockery::mock(AMQPQueue::class);

        $connection = new RabbitMQConnection([]);
        $this->injectQueue($connection, 'test-queue', $queue);

        $result = $connection->getQueue('test-queue');

        $this->assertSame($queue, $result);
    }

    public function testDisconnectWithChannelNotConnected(): void
    {
        $amqpConn = Mockery::mock(AMQPConnection::class);
        $amqpConn->shouldReceive('isConnected')
            ->andReturn(true);
        $amqpConn->shouldReceive('disconnect')
            ->once();

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('isConnected')
            ->andReturn(false);

        $connection = new RabbitMQConnection([]);
        $this->injectConnection($connection, $amqpConn);
        $this->injectChannel($connection, $channel);

        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectClearsExchangesAndQueues(): void
    {
        $amqpConn = Mockery::mock(AMQPConnection::class);
        $amqpConn->shouldReceive('isConnected')
            ->andReturn(true);
        $amqpConn->shouldReceive('disconnect')
            ->once();

        $exchange = Mockery::mock(AMQPExchange::class);
        $queue = Mockery::mock(AMQPQueue::class);

        $connection = new RabbitMQConnection([]);
        $this->injectConnection($connection, $amqpConn);
        $this->injectExchange($connection, 'test-exchange', $exchange);
        $this->injectQueue($connection, 'test-queue', $queue);

        $connection->disconnect();

        // After disconnect, accessing getExchange should fail without hosts
        $this->expectException(ConnectionException::class);
        $connection->getExchange('test-exchange');
    }

    public function testConnectWithMultipleHostsFailsAll(): void
    {
        $connection = new RabbitMQConnection([
            'hosts' => [
                ['host' => 'invalid-host-1', 'port' => 5672],
                ['host' => 'invalid-host-2', 'port' => 5672],
            ],
            'options' => [
                'connection_timeout' => 1,
            ],
        ]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to connect to RabbitMQ');

        $connection->getConnection();
    }

    private function injectConnection(RabbitMQConnection $conn, MockInterface $amqpConn): void
    {
        $reflection = new ReflectionClass($conn);
        $property = $reflection->getProperty('connection');
        $property->setValue($conn, $amqpConn);
    }

    private function injectChannel(RabbitMQConnection $conn, MockInterface $channel): void
    {
        $reflection = new ReflectionClass($conn);
        $property = $reflection->getProperty('channel');
        $property->setValue($conn, $channel);
    }

    private function injectExchange(RabbitMQConnection $conn, string $name, MockInterface $exchange): void
    {
        $reflection = new ReflectionClass($conn);
        $property = $reflection->getProperty('exchanges');
        $exchanges = $property->getValue($conn);
        $exchanges[$name] = $exchange;
        $property->setValue($conn, $exchanges);
    }

    private function injectQueue(RabbitMQConnection $conn, string $name, MockInterface $queue): void
    {
        $reflection = new ReflectionClass($conn);
        $property = $reflection->getProperty('queues');
        $queues = $property->getValue($conn);
        $queues[$name] = $queue;
        $property->setValue($conn, $queues);
    }
}
