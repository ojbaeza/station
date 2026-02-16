<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Sqs;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Drivers\Sqs\SqsConnector;
use Station\Drivers\Sqs\SqsQueue;

class SqsConnectorTest extends TestCase
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

    public function testConnectReturnsSqsQueue(): void
    {
        $connector = new SqsConnector($this->events);

        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'queue' => 'test-queue',
            'wait_time' => 20,
            'visibility_timeout' => 30,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }

    public function testConnectUsesDefaultQueueWhenNotConfigured(): void
    {
        $connector = new SqsConnector($this->events);

        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }

    public function testConnectWithFifoQueue(): void
    {
        $connector = new SqsConnector($this->events);

        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'queue' => 'test-queue.fifo',
            'fifo' => true,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(SqsQueue::class, $queue);
    }
}
