<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Beanstalkd;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Station\Drivers\Beanstalkd\BeanstalkdConnector;
use Station\Drivers\Beanstalkd\BeanstalkdQueue;

class BeanstalkdConnectorTest extends TestCase
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

    public function testConnectReturnsBeanstalkdQueue(): void
    {
        $connector = new BeanstalkdConnector($this->events);

        $config = [
            'host' => '127.0.0.1',
            'port' => 11300,
            'queue' => 'test-queue',
            'timeout' => 10,
            'ttr' => 60,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(BeanstalkdQueue::class, $queue);
    }

    public function testConnectUsesDefaultQueueWhenNotConfigured(): void
    {
        $connector = new BeanstalkdConnector($this->events);

        $config = [
            'host' => '127.0.0.1',
            'port' => 11300,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(BeanstalkdQueue::class, $queue);
    }

    public function testConnectWithCustomConfiguration(): void
    {
        $connector = new BeanstalkdConnector($this->events);

        $config = [
            'host' => 'beanstalkd.local',
            'port' => 11301,
            'queue' => 'custom-queue',
            'timeout' => 30,
            'ttr' => 120,
            'reserve_timeout' => 10,
            'priority' => 512,
        ];

        $queue = $connector->connect($config);

        $this->assertInstanceOf(BeanstalkdQueue::class, $queue);
    }
}
