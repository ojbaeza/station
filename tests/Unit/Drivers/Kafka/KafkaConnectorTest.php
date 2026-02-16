<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Kafka;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Drivers\Kafka\KafkaConnector;
use Station\Drivers\Kafka\KafkaQueue;

class KafkaConnectorTest extends TestCase
{
    private MockInterface&Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = Mockery::mock(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConnectReturnsKafkaQueue(): void
    {
        $connector = new KafkaConnector($this->events);

        $config = [
            'brokers' => '127.0.0.1:9092',
            'queue' => 'test-topic',
            'group_id' => 'station',
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(KafkaQueue::class, $queue);
    }

    public function testConnectUsesDefaultQueueWhenNotConfigured(): void
    {
        $connector = new KafkaConnector($this->events);

        $config = [
            'brokers' => '127.0.0.1:9092',
            'group_id' => 'station',
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(KafkaQueue::class, $queue);
    }

    public function testConnectWithMultipleBrokers(): void
    {
        $connector = new KafkaConnector($this->events);

        $config = [
            'brokers' => '127.0.0.1:9092,127.0.0.2:9092,127.0.0.3:9092',
            'queue' => 'test-topic',
            'group_id' => 'station-workers',
            'auto_offset_reset' => 'latest',
            'auto_commit' => true,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(KafkaQueue::class, $queue);
    }
}
