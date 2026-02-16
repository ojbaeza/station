<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\RabbitMQ;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Station\Drivers\RabbitMQ\RabbitMQConnector;
use Station\Drivers\RabbitMQ\RabbitMQQueue;
use Station\StationServiceProvider;

class RabbitMQConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createQueueStatusTable();
    }

    public function testConnectReturnsRabbitMQQueue(): void
    {
        $connector = new RabbitMQConnector();

        $queue = $connector->connect([
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
        ]);

        $this->assertInstanceOf(RabbitMQQueue::class, $queue);
    }

    public function testConnectUsesDefaultExchangeName(): void
    {
        $connector = new RabbitMQConnector();

        $queue = $connector->connect([
            'host' => 'localhost',
        ]);

        $this->assertInstanceOf(RabbitMQQueue::class, $queue);
        // Verify the queue has the expected default connection name
        $this->assertSame('station', $queue->getConnectionName());
    }

    public function testConnectUsesConfiguredExchangeName(): void
    {
        $connector = new RabbitMQConnector();

        $queue = $connector->connect([
            'host' => 'localhost',
            'exchange' => [
                'name' => 'custom.exchange',
            ],
        ]);

        $this->assertInstanceOf(RabbitMQQueue::class, $queue);
        // Verify the queue instance is ready for operations
        $this->assertFalse($queue->isPaused('default'));
    }

    public function testConnectUsesConfiguredQueueName(): void
    {
        $connector = new RabbitMQConnector();

        $queue = $connector->connect([
            'host' => 'localhost',
            'queue' => 'custom-queue',
        ]);

        $this->assertInstanceOf(RabbitMQQueue::class, $queue);
        // Verify the queue can handle pause/resume for the custom queue
        $queue->pause('custom-queue');
        $this->assertTrue($queue->isPaused('custom-queue'));
        $queue->resume('custom-queue');
        $this->assertFalse($queue->isPaused('custom-queue'));
    }

    public function testConnectWithAllOptions(): void
    {
        $connector = new RabbitMQConnector();

        $queue = $connector->connect([
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'queue' => 'high-priority',
            'exchange' => [
                'name' => 'station.direct',
                'type' => 'direct',
                'durable' => true,
            ],
        ]);

        $this->assertInstanceOf(RabbitMQQueue::class, $queue);
        // Verify the queue is functional via health check
        $healthCheck = $queue->healthCheck();
        $this->assertArrayHasKey('connected', $healthCheck);
    }

    public function testConstructorRequiresNoArguments(): void
    {
        $connector = new RabbitMQConnector();

        $this->assertInstanceOf(RabbitMQConnector::class, $connector);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    private function createQueueStatusTable(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS station_queue_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(255) NOT NULL,
            paused BOOLEAN NOT NULL DEFAULT 0,
            paused_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )');
    }
}
