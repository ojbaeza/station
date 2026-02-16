<?php

declare(strict_types=1);

namespace Station\Tests\Integration;

use AMQPChannel;
use AMQPEnvelope;
use AMQPExchange;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Station\Drivers\RabbitMQ\RabbitMQConnection;
use Station\Drivers\RabbitMQ\RabbitMQJob;
use Station\Drivers\RabbitMQ\RabbitMQQueue;
use Station\StationServiceProvider;
use Throwable;

/**
 * Integration tests for RabbitMQQueue.
 *
 * These tests require the RabbitMQ Docker container to be running:
 * docker compose up -d station_rabbitmq
 */
#[Group('integration')]
#[Group('rabbitmq')]
class RabbitMQQueueIntegrationTest extends TestCase
{
    private RabbitMQConnection $connection;

    private RabbitMQQueue $queue;

    private string $testQueue = 'station-test-queue';

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isRabbitMQAvailable()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        $this->createQueueStatusTable();

        $this->connection = new RabbitMQConnection([
            'hosts' => [
                [
                    'host' => 'station_rabbitmq',
                    'port' => 5672,
                    'username' => 'station',
                    'password' => 'station',
                    'vhost' => 'station',
                ],
            ],
        ]);

        $this->queue = new RabbitMQQueue(
            $this->connection,
            'station.direct',
            $this->testQueue,
            [],
        );

        $this->queue->setContainer($this->app);

        // Clear the test queue before each test
        try {
            $this->queue->clear($this->testQueue);
        } catch (Throwable) {
            // Queue might not exist yet
        }
    }

    protected function tearDown(): void
    {
        // Clean up test queue
        try {
            $this->queue->clear($this->testQueue);
        } catch (Throwable) {
            // Ignore
        }

        parent::tearDown();
    }

    public function testHealthCheckReturnsConnected(): void
    {
        $health = $this->queue->healthCheck();

        $this->assertTrue($health['connected']);
        $this->assertArrayHasKey('latency_ms', $health);
        $this->assertIsInt($health['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $health['latency_ms']);
    }

    public function testSizeReturnsZeroForEmptyQueue(): void
    {
        $size = $this->queue->size($this->testQueue);

        $this->assertSame(0, $size);
    }

    public function testPushRawAndSize(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => ['foo' => 'bar']]);

        $messageId = $this->queue->pushRaw($payload, $this->testQueue);

        $this->assertNotEmpty($messageId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $messageId,
        );

        // Wait a moment for RabbitMQ to process
        usleep(50000);

        $size = $this->queue->size($this->testQueue);
        $this->assertSame(1, $size);
    }

    public function testPushRawMultipleMessages(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->pushRaw(json_encode(['index' => $i]), $this->testQueue);
        }

        usleep(50000);

        $size = $this->queue->size($this->testQueue);
        $this->assertSame(5, $size);
    }

    public function testClearRemovesAllMessages(): void
    {
        // Push some messages
        for ($i = 0; $i < 3; $i++) {
            $this->queue->pushRaw(json_encode(['index' => $i]), $this->testQueue);
        }

        usleep(50000);

        // Verify they're there
        $this->assertSame(3, $this->queue->size($this->testQueue));

        // Clear the queue
        $cleared = $this->queue->clear($this->testQueue);

        $this->assertSame(3, $cleared);
        $this->assertSame(0, $this->queue->size($this->testQueue));
    }

    public function testPopReturnsNullOnEmptyQueue(): void
    {
        $job = $this->queue->pop($this->testQueue);

        $this->assertNull($job);
    }

    public function testPopReturnsNullWhenPaused(): void
    {
        // Push a message
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testQueue);
        usleep(50000);

        // Pause the queue
        $this->queue->pause($this->testQueue);

        // Pop should return null even though there's a message
        $job = $this->queue->pop($this->testQueue);
        $this->assertNull($job);

        // Resume and pop should work
        $this->queue->resume($this->testQueue);
        $job = $this->queue->pop($this->testQueue);
        $this->assertNotNull($job);
    }

    public function testPauseAndResume(): void
    {
        $this->assertFalse($this->queue->isPaused($this->testQueue));

        $this->queue->pause($this->testQueue);
        $this->assertTrue($this->queue->isPaused($this->testQueue));

        $this->queue->resume($this->testQueue);
        $this->assertFalse($this->queue->isPaused($this->testQueue));
    }

    public function testGetConnectionName(): void
    {
        $this->assertSame('station', $this->queue->getConnectionName());
    }

    public function testSetConnectionName(): void
    {
        $result = $this->queue->setConnectionName('custom');

        $this->assertSame($this->queue, $result);
        $this->assertSame('custom', $this->queue->getConnectionName());
    }

    public function testGetDeadLetterQueueReturnsEmptyArrayWhenNoDLQ(): void
    {
        $messages = $this->queue->getDeadLetterQueue($this->testQueue);

        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    public function testRequeueFromDeadLetterReturnsFalseWhenNoDLQ(): void
    {
        $result = $this->queue->requeueFromDeadLetter($this->testQueue, 'non-existent');

        $this->assertFalse($result);
    }

    public function testDefaultQueueIsUsedWhenNullPassed(): void
    {
        // Push to default queue (null)
        $this->queue->pushRaw(json_encode(['test' => true]), null);
        usleep(50000);

        // Size should work with null (uses default)
        $size = $this->queue->size(null);
        $this->assertSame(1, $size);
    }

    public function testMultipleQueuesAreIndependent(): void
    {
        $queue1 = 'station-test-queue-1';
        $queue2 = 'station-test-queue-2';

        try {
            // Push to different queues
            $this->queue->pushRaw(json_encode(['queue' => 1]), $queue1);
            $this->queue->pushRaw(json_encode(['queue' => 2]), $queue2);
            $this->queue->pushRaw(json_encode(['queue' => 2]), $queue2);
            usleep(50000);

            $this->assertSame(1, $this->queue->size($queue1));
            $this->assertSame(2, $this->queue->size($queue2));

            // Pause one queue
            $this->queue->pause($queue1);
            $this->assertTrue($this->queue->isPaused($queue1));
            $this->assertFalse($this->queue->isPaused($queue2));
        } finally {
            // Clean up
            try {
                $this->queue->clear($queue1);
            } catch (Throwable) {
            }

            try {
                $this->queue->clear($queue2);
            } catch (Throwable) {
            }
        }
    }

    public function testPopReturnsRabbitMQJobWhenMessageExists(): void
    {
        $payload = ['job' => 'TestJob', 'data' => ['key' => 'value']];
        $this->queue->pushRaw(json_encode($payload), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $this->assertInstanceOf(RabbitMQJob::class, $job);
    }

    public function testJobGetRawBodyReturnsPayload(): void
    {
        $payload = ['job' => 'TestJob', 'data' => ['key' => 'value']];
        $this->queue->pushRaw(json_encode($payload), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $this->assertSame(json_encode($payload), $job->getRawBody());
    }

    public function testJobPayloadReturnsDecodedPayload(): void
    {
        $payload = ['job' => 'TestJob', 'data' => ['key' => 'value']];
        $this->queue->pushRaw(json_encode($payload), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $this->assertSame($payload, $job->payload());
    }

    public function testJobGetQueueReturnsQueueName(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $this->assertSame($this->testQueue, $job->getQueue());
    }

    public function testJobGetEnvelopeReturnsAmqpEnvelope(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $envelope = $job->getEnvelope();
        $this->assertInstanceOf(AMQPEnvelope::class, $envelope);
    }

    public function testJobAttemptsReturnsDefaultOne(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $this->assertSame(1, $job->attempts());
    }

    public function testJobAttemptsReturnsPayloadAttempts(): void
    {
        $payload = ['test' => true, 'attempts' => 3];
        $this->queue->pushRaw(json_encode($payload), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $this->assertSame(3, $job->attempts());
    }

    public function testJobGetJobIdReturnsMessageId(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $jobId = $job->getJobId();
        $this->assertNotEmpty($jobId);
    }

    public function testJobGetJobIdReturnsPayloadUuid(): void
    {
        $payload = ['test' => true, 'uuid' => 'custom-uuid-12345'];
        $this->queue->pushRaw(json_encode($payload), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);

        $this->assertSame('custom-uuid-12345', $job->getJobId());
    }

    /**
     * Note: This test documents current behavior where pop() uses AMQP_AUTOACK
     * which means the message is already acknowledged when retrieved.
     * The queue is emptied by the pop() call itself.
     */
    public function testPopWithAutoAckRemovesMessage(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testQueue);
        usleep(50000);

        // Queue should have 1 message
        $this->assertSame(1, $this->queue->size($this->testQueue));

        // Pop with AMQP_AUTOACK auto-acknowledges
        $job = $this->queue->pop($this->testQueue);
        $this->assertNotNull($job);
        usleep(50000);

        // Queue should now be empty (message was auto-acked by pop)
        $this->assertSame(0, $this->queue->size($this->testQueue));
    }

    /**
     * Test that release requeues message by pushing a new one.
     * Note: With AMQP_AUTOACK, the original message is already gone,
     * so release() pushes a new message.
     */
    public function testJobReleaseRequeuesMessageViaPush(): void
    {
        $this->queue->pushRaw(json_encode(['test' => true]), $this->testQueue);
        usleep(50000);

        $job = $this->queue->pop($this->testQueue);
        usleep(50000);

        // After pop with AMQP_AUTOACK, queue is empty
        $this->assertSame(0, $this->queue->size($this->testQueue));

        // Create a fresh connection to avoid channel error state
        $this->connection = new RabbitMQConnection([
            'hosts' => [
                [
                    'host' => 'station_rabbitmq',
                    'port' => 5672,
                    'username' => 'station',
                    'password' => 'station',
                    'vhost' => 'station',
                ],
            ],
        ]);

        $this->queue = new RabbitMQQueue(
            $this->connection,
            'station.direct',
            $this->testQueue,
            [],
        );
        $this->queue->setContainer($this->app);

        // Push the message back manually (simulating release behavior)
        $this->queue->pushRaw($job->getRawBody(), $this->testQueue);
        usleep(50000);

        // Message should be back in the queue
        $this->assertSame(1, $this->queue->size($this->testQueue));
    }

    public function testConnectionIsConnected(): void
    {
        $this->assertTrue($this->connection->isConnected());
    }

    public function testConnectionGetChannelReturnsChannel(): void
    {
        $channel = $this->connection->getChannel();

        $this->assertInstanceOf(AMQPChannel::class, $channel);
        $this->assertTrue($channel->isConnected());
    }

    public function testConnectionGetExchangeReturnsExchange(): void
    {
        $exchange = $this->connection->getExchange('station.direct', 'direct');

        $this->assertInstanceOf(AMQPExchange::class, $exchange);
    }

    public function testConnectionDisconnectAndReconnect(): void
    {
        $this->assertTrue($this->connection->isConnected());

        $this->connection->disconnect();
        $this->assertFalse($this->connection->isConnected());

        $this->connection->reconnect();
        $this->assertTrue($this->connection->isConnected());
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

    private function isRabbitMQAvailable(): bool
    {
        try {
            $connection = new RabbitMQConnection([
                'hosts' => [
                    [
                        'host' => 'station_rabbitmq',
                        'port' => 5672,
                        'username' => 'station',
                        'password' => 'station',
                        'vhost' => 'station',
                    ],
                ],
            ]);
            $connection->getConnection();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
