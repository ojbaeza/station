<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Kafka;

use Exception;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;
use ReflectionClass;
use Station\Drivers\Kafka\KafkaConnection;

class KafkaConnectionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testGetGroupIdReturnsDefault(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertSame('station', $connection->getGroupId());
    }

    public function testGetGroupIdReturnsConfiguredValue(): void
    {
        $connection = new KafkaConnection([
            'group_id' => 'custom-group',
        ]);

        $this->assertSame('custom-group', $connection->getGroupId());
    }

    public function testGetDefaultTopicReturnsDefault(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertSame('default', $connection->getDefaultTopic());
    }

    public function testGetDefaultTopicReturnsConfiguredValue(): void
    {
        $connection = new KafkaConnection([
            'queue' => 'custom-topic',
        ]);

        $this->assertSame('custom-topic', $connection->getDefaultTopic());
    }

    public function testGetConsumeTimeoutReturnsDefault(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertSame(5000, $connection->getConsumeTimeout());
    }

    public function testGetConsumeTimeoutReturnsConfiguredValue(): void
    {
        $connection = new KafkaConnection([
            'consume_timeout' => 10000,
        ]);

        $this->assertSame(10000, $connection->getConsumeTimeout());
    }

    public function testGetFlushTimeoutReturnsDefault(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertSame(10000, $connection->getFlushTimeout());
    }

    public function testGetFlushTimeoutReturnsConfiguredValue(): void
    {
        $connection = new KafkaConnection([
            'flush_timeout' => 30000,
        ]);

        $this->assertSame(30000, $connection->getFlushTimeout());
    }

    public function testIsAutoCommitReturnsFalseByDefault(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertFalse($connection->isAutoCommit());
    }

    public function testIsAutoCommitReturnsTrueWhenConfigured(): void
    {
        $connection = new KafkaConnection([
            'auto_commit' => true,
        ]);

        $this->assertTrue($connection->isAutoCommit());
    }

    public function testGetBrokersReturnsDefault(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertSame('127.0.0.1:9092', $connection->getBrokers());
    }

    public function testGetBrokersReturnsConfiguredValue(): void
    {
        $connection = new KafkaConnection([
            'brokers' => 'kafka1:9092,kafka2:9092,kafka3:9092',
        ]);

        $this->assertSame('kafka1:9092,kafka2:9092,kafka3:9092', $connection->getBrokers());
    }

    public function testIsConnectedReturnsFalseWhenNotConnected(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertFalse($connection->isConnected());
    }

    public function testIsConnectedReturnsTrueWhenProducerConnected(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('The rdkafka extension is not available.');
        }

        $producer = Mockery::mock(Producer::class);
        $producer->shouldReceive('flush')
            ->once()
            ->with(1000)
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $connection = new KafkaConnection([]);
        $this->injectProducer($connection, $producer);

        $this->assertTrue($connection->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenFlushFails(): void
    {
        $producer = Mockery::mock(Producer::class);
        $producer->shouldReceive('flush')
            ->once()
            ->andThrow(new Exception('Flush failed'));

        $connection = new KafkaConnection([]);
        $this->injectProducer($connection, $producer);

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectClearsConnections(): void
    {
        $consumer = Mockery::mock(KafkaConsumer::class);
        $consumer->shouldReceive('unsubscribe')
            ->once();

        $producer = Mockery::mock(Producer::class);

        $connection = new KafkaConnection([]);
        $this->injectProducer($connection, $producer);
        $this->injectConsumer($connection, $consumer);

        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testSubscribeSubscribesToTopic(): void
    {
        $consumer = Mockery::mock(KafkaConsumer::class);
        $consumer->shouldReceive('subscribe')
            ->once()
            ->with(['test-topic']);

        $connection = new KafkaConnection([]);
        $this->injectConsumer($connection, $consumer);

        $connection->subscribe('test-topic');

        // Verify the mock was called correctly
    }

    public function testSubscribeDoesNotResubscribeToSameTopic(): void
    {
        $consumer = Mockery::mock(KafkaConsumer::class);
        $consumer->shouldReceive('subscribe')
            ->once()
            ->with(['test-topic']);

        $connection = new KafkaConnection([]);
        $this->injectConsumer($connection, $consumer);

        $connection->subscribe('test-topic');
        $connection->subscribe('test-topic'); // Should not call subscribe again

        // The once() expectation ensures subscribe was only called once
    }

    public function testSubscribeToMultipleTopics(): void
    {
        $consumer = Mockery::mock(KafkaConsumer::class);
        $consumer->shouldReceive('subscribe')
            ->once()
            ->with(['topic1']);
        $consumer->shouldReceive('subscribe')
            ->once()
            ->with(['topic1', 'topic2']);

        $connection = new KafkaConnection([]);
        $this->injectConsumer($connection, $consumer);

        $connection->subscribe('topic1');
        $connection->subscribe('topic2');

        // Verify both subscribe calls occurred correctly
    }

    public function testIsConnectedReturnsTrueWithConsumerOnly(): void
    {
        $consumer = Mockery::mock(KafkaConsumer::class);

        $connection = new KafkaConnection([]);
        $this->injectConsumer($connection, $consumer);

        // With consumer but no producer, isConnected should check and return true
        // (producer is null, so the try block returns true without calling flush)
        $this->assertTrue($connection->isConnected());
    }

    public function testDisconnectWithConsumerOnly(): void
    {
        $consumer = Mockery::mock(KafkaConsumer::class);
        $consumer->shouldReceive('unsubscribe')
            ->once();

        $connection = new KafkaConnection([]);
        $this->injectConsumer($connection, $consumer);

        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectWithProducerOnly(): void
    {
        $producer = Mockery::mock(Producer::class);

        $connection = new KafkaConnection([]);
        $this->injectProducer($connection, $producer);

        // No consumer, so unsubscribe won't be called
        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectClearsSubscribedTopics(): void
    {
        $consumer = Mockery::mock(KafkaConsumer::class);
        $consumer->shouldReceive('subscribe')
            ->once()
            ->with(['test-topic']);
        $consumer->shouldReceive('unsubscribe')
            ->once();

        $connection = new KafkaConnection([]);
        $this->injectConsumer($connection, $consumer);

        // Subscribe to a topic
        $connection->subscribe('test-topic');

        // Disconnect
        $connection->disconnect();

        // Re-inject consumer and subscribe again - should call subscribe
        $consumer2 = Mockery::mock(KafkaConsumer::class);
        $consumer2->shouldReceive('subscribe')
            ->once()
            ->with(['test-topic']);
        $this->injectConsumer($connection, $consumer2);

        // This should call subscribe again because topics were cleared
        $connection->subscribe('test-topic');
    }

    public function testAllConfigDefaultsAreCorrect(): void
    {
        $connection = new KafkaConnection([]);

        $this->assertSame('station', $connection->getGroupId());
        $this->assertSame('default', $connection->getDefaultTopic());
        $this->assertSame(5000, $connection->getConsumeTimeout());
        $this->assertSame(10000, $connection->getFlushTimeout());
        $this->assertFalse($connection->isAutoCommit());
        $this->assertSame('127.0.0.1:9092', $connection->getBrokers());
    }

    public function testAllConfigValuesCanBeOverridden(): void
    {
        $connection = new KafkaConnection([
            'group_id' => 'custom-group',
            'queue' => 'custom-topic',
            'consume_timeout' => 10000,
            'flush_timeout' => 20000,
            'auto_commit' => true,
            'brokers' => 'kafka-1:9092,kafka-2:9092',
        ]);

        $this->assertSame('custom-group', $connection->getGroupId());
        $this->assertSame('custom-topic', $connection->getDefaultTopic());
        $this->assertSame(10000, $connection->getConsumeTimeout());
        $this->assertSame(20000, $connection->getFlushTimeout());
        $this->assertTrue($connection->isAutoCommit());
        $this->assertSame('kafka-1:9092,kafka-2:9092', $connection->getBrokers());
    }

    public function testIsConnectedReturnsFalseWhenBothAreNull(): void
    {
        $connection = new KafkaConnection([]);

        // Without injecting any producer or consumer, both should be null
        $this->assertFalse($connection->isConnected());
    }

    public function testGetProducerCreatesConnection(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'log_level' => 0,
        ]);

        $producer = $connection->getProducer();

        $this->assertInstanceOf(Producer::class, $producer);
    }

    public function testGetProducerReturnsCachedInstance(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'log_level' => 0,
        ]);

        $producer1 = $connection->getProducer();
        $producer2 = $connection->getProducer();

        $this->assertSame($producer1, $producer2);
    }

    public function testGetConsumerCreatesConnection(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'group_id' => 'test-group',
            'log_level' => 0,
        ]);

        $consumer = $connection->getConsumer();

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
    }

    public function testGetConsumerReturnsCachedInstance(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'group_id' => 'test-group',
            'log_level' => 0,
        ]);

        $consumer1 = $connection->getConsumer();
        $consumer2 = $connection->getConsumer();

        $this->assertSame($consumer1, $consumer2);
    }

    public function testGetProducerWithSecuritySettings(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'security_protocol' => 'SASL_PLAINTEXT',
            'sasl_mechanisms' => 'PLAIN',
            'sasl_username' => 'user',
            'sasl_password' => 'pass',
            'log_level' => 0,
        ]);

        $producer = $connection->getProducer();

        $this->assertInstanceOf(Producer::class, $producer);
    }

    public function testGetProducerWithIdempotence(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'idempotent' => true,
            'acks' => -1,
            'log_level' => 0,
        ]);

        $producer = $connection->getProducer();

        $this->assertInstanceOf(Producer::class, $producer);
    }

    public function testGetConsumerWithSecuritySettings(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'group_id' => 'test-group',
            'security_protocol' => 'SASL_PLAINTEXT',
            'sasl_mechanisms' => 'PLAIN',
            'sasl_username' => 'user',
            'sasl_password' => 'pass',
            'log_level' => 0,
        ]);

        $consumer = $connection->getConsumer();

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
    }

    public function testGetConsumerWithCustomTimeouts(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'group_id' => 'test-group',
            'session_timeout' => 60000,
            'heartbeat_interval' => 10000,
            'auto_offset_reset' => 'latest',
            'log_level' => 0,
        ]);

        $consumer = $connection->getConsumer();

        $this->assertInstanceOf(KafkaConsumer::class, $consumer);
    }

    public function testGetProducerWithMessageTimeout(): void
    {
        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka extension not available');
        }

        $connection = new KafkaConnection([
            'brokers' => 'localhost:9092',
            'message_timeout' => 60000,
            'log_level' => 0,
        ]);

        $producer = $connection->getProducer();

        $this->assertInstanceOf(Producer::class, $producer);
    }

    private function injectProducer(KafkaConnection $connection, MockInterface $producer): void
    {
        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('producer');
        $property->setValue($connection, $producer);
    }

    private function injectConsumer(KafkaConnection $connection, MockInterface $consumer): void
    {
        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('consumer');
        $property->setValue($connection, $consumer);
    }
}
