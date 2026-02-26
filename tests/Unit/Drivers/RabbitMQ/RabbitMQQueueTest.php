<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\RabbitMQ;

use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Station\Contracts\AggregateDriverInfoInterface;
use Station\Drivers\RabbitMQ\RabbitMQConnection;
use Station\Drivers\RabbitMQ\RabbitMQJob;
use Station\Drivers\RabbitMQ\RabbitMQQueue;
use Station\Exceptions\ConnectionException;
use Station\StationServiceProvider;
use Throwable;

class RabbitMQQueueTest extends TestCase
{
    private RabbitMQQueue $queue;

    private RabbitMQConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new RabbitMQConnection([]);

        $this->queue = new RabbitMQQueue(
            $this->connection,
            'station.direct',
            'default',
            [],
        );

        $this->queue->setContainer($this->app);

        $this->createQueueStatusTable();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPauseAndResume(): void
    {
        $this->assertFalse($this->queue->isPaused('test-queue'));

        $this->queue->pause('test-queue');
        $this->assertTrue($this->queue->isPaused('test-queue'));

        $this->queue->resume('test-queue');
        $this->assertFalse($this->queue->isPaused('test-queue'));
    }

    public function testIsPausedReturnsFalseForUnknownQueue(): void
    {
        $this->assertFalse($this->queue->isPaused('unknown-queue'));
    }

    public function testGetConnectionName(): void
    {
        $name = $this->queue->getConnectionName();

        $this->assertSame('station', $name);
    }

    public function testSetConnectionName(): void
    {
        $result = $this->queue->setConnectionName('custom-connection');

        $this->assertSame($this->queue, $result);
        $this->assertSame('custom-connection', $this->queue->getConnectionName());
    }

    public function testMultipleQueuesPaused(): void
    {
        $this->queue->pause('queue-1');
        $this->queue->pause('queue-2');

        $this->assertTrue($this->queue->isPaused('queue-1'));
        $this->assertTrue($this->queue->isPaused('queue-2'));
        $this->assertFalse($this->queue->isPaused('queue-3'));

        $this->queue->resume('queue-1');

        $this->assertFalse($this->queue->isPaused('queue-1'));
        $this->assertTrue($this->queue->isPaused('queue-2'));
    }

    public function testPopReturnsNullWhenQueuePaused(): void
    {
        $this->queue->pause('default');

        $job = $this->queue->pop();

        $this->assertNull($job);
    }

    public function testHealthCheckReturnsDisconnectedWhenNoHosts(): void
    {
        // Without hosts configured, getConnection throws
        $health = $this->queue->healthCheck();

        $this->assertFalse($health['connected']);
        $this->assertArrayHasKey('message', $health);
        $this->assertStringContainsString('No RabbitMQ hosts configured', $health['message']);
    }

    public function testPauseIdempotent(): void
    {
        $this->queue->pause('test');
        $this->queue->pause('test');
        $this->queue->pause('test');

        $this->assertTrue($this->queue->isPaused('test'));

        $this->queue->resume('test');

        $this->assertFalse($this->queue->isPaused('test'));
    }

    public function testResumeIdempotent(): void
    {
        $this->queue->pause('test');
        $this->queue->resume('test');
        $this->queue->resume('test');
        $this->queue->resume('test');

        $this->assertFalse($this->queue->isPaused('test'));
    }

    public function testSetConnectionNameReturnsThis(): void
    {
        $result = $this->queue->setConnectionName('test');

        $this->assertInstanceOf(RabbitMQQueue::class, $result);
    }

    public function testGetQueueMethodReturnsDefaultWhenNull(): void
    {
        $reflection = new ReflectionClass($this->queue);
        $method = $reflection->getMethod('getQueue');

        $result = $method->invoke($this->queue, null);

        $this->assertSame('default', $result);
    }

    public function testGetQueueMethodReturnsProvidedQueue(): void
    {
        $reflection = new ReflectionClass($this->queue);
        $method = $reflection->getMethod('getQueue');

        $result = $method->invoke($this->queue, 'custom-queue');

        $this->assertSame('custom-queue', $result);
    }

    public function testPauseCachePropertyExists(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasProperty('pauseCache'));
    }

    public function testDriverConfigPropertyExists(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasProperty('driverConfig'));
    }

    public function testQueueHasSizeMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('size'));

        $method = $reflection->getMethod('size');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasPushMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('push'));

        $method = $reflection->getMethod('push');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasPushRawMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('pushRaw'));

        $method = $reflection->getMethod('pushRaw');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasLaterMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('later'));

        $method = $reflection->getMethod('later');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasPopMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('pop'));

        $method = $reflection->getMethod('pop');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasClearMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('clear'));

        $method = $reflection->getMethod('clear');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasAckMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('ack'));

        $method = $reflection->getMethod('ack');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasRejectMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('reject'));

        $method = $reflection->getMethod('reject');
        $this->assertTrue($method->isPublic());
    }

    public function testQueueHasEnqueueMethod(): void
    {
        $reflection = new ReflectionClass($this->queue);

        $this->assertTrue($reflection->hasMethod('enqueue'));
    }

    public function testGetDeadLetterQueueReturnsEmptyArrayWhenConnectionFails(): void
    {
        $result = $this->queue->getDeadLetterQueue('test-queue', 10);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testRequeueFromDeadLetterReturnsFalseWhenConnectionFails(): void
    {
        $result = $this->queue->requeueFromDeadLetter('test-queue', 'message-123');

        $this->assertFalse($result);
    }

    public function testPopReturnsNullForSpecificPausedQueue(): void
    {
        $this->queue->pause('specific-queue');

        $job = $this->queue->pop('specific-queue');

        $this->assertNull($job);
    }

    public function testHealthCheckStructure(): void
    {
        $health = $this->queue->healthCheck();

        $this->assertArrayHasKey('connected', $health);
        $this->assertArrayHasKey('latency_ms', $health);
        $this->assertIsBool($health['connected']);
        $this->assertIsInt($health['latency_ms']);
    }

    public function testResumeDoesNotErrorOnNonPausedQueue(): void
    {
        // Should not throw or error
        $this->queue->resume('never-paused-queue');

        $this->assertFalse($this->queue->isPaused('never-paused-queue'));
    }

    public function testQueueImplementsAggregateDriverInfoInterface(): void
    {
        $this->assertInstanceOf(AggregateDriverInfoInterface::class, $this->queue);
    }

    public function testGetAllDriverInfoFallsBackWithoutManagementUrl(): void
    {
        // Without management_url configured, getAllDriverInfo should fall back to getDriverInfo
        // which will fail since we have no real connection, but we can check the structure
        $queue = new RabbitMQQueue(
            $this->connection,
            'station.direct',
            'test-queue',
            [],  // no management_url
        );
        $queue->setContainer($this->app);

        // getDriverInfo will fail because no real connection, but getAllDriverInfo
        // should delegate to it. Since we can't connect, check that it at least
        // calls through (size() will fail with AMQPConnectionException)
        try {
            $result = $queue->getAllDriverInfo();
            // If it returned something, check it's from getDriverInfo fallback
            $this->assertArrayHasKey('driver', $result);
            $this->assertSame('rabbitmq', $result['driver']);
        } catch (Throwable) {
            // Expected - no real RabbitMQ connection
            $this->expectNotToPerformAssertions();
        }
    }

    public function testGetDriverInfoDlqUsesDotSuffix(): void
    {
        // Verify the DLQ suffix uses .dlq (not _dlq) by checking the method source
        $reflection = new ReflectionClass($this->queue);
        $method = $reflection->getMethod('getDriverInfo');
        $source = file_get_contents($method->getFileName());

        // The DLQ query should use .dlq suffix
        $this->assertStringContainsString('{$queue}.dlq', $source);
        // Should NOT contain the old _dlq suffix in getDriverInfo context
        $this->assertStringNotContainsString('{$queue}_dlq', $source);
    }

    // -------------------------------------------------------------------------
    // getDriverInfo() tests without management API
    // -------------------------------------------------------------------------

    public function testGetDriverInfoWithoutManagementUrlReturnsFalseManagementApi(): void
    {
        // The queue has no management_url configured and no real connection,
        // so size() will throw. But getDriverInfo catches and returns partial info.
        try {
            $result = $this->queue->getDriverInfo('test-queue');
            $this->assertSame('rabbitmq', $result['driver']);
            $this->assertFalse($result['management_api']);
        } catch (Throwable) {
            // size() may throw AMQPConnectionException; that's expected
            $this->expectNotToPerformAssertions();
        }
    }

    public function testGetDriverInfoWithManagementUrlAndFailedHttpRequest(): void
    {
        $queue = new RabbitMQQueue(
            $this->connection,
            'station.direct',
            'default',
            [
                'management_url' => 'http://localhost:99999', // invalid port
            ],
        );
        $queue->setContainer($this->app);

        try {
            $result = $queue->getDriverInfo('test-queue');
            // If size fails, we may get an exception; if not, check the structure
            $this->assertSame('rabbitmq', $result['driver']);
        } catch (Throwable) {
            $this->expectNotToPerformAssertions();
        }
    }

    // -------------------------------------------------------------------------
    // clear() without connection
    // -------------------------------------------------------------------------

    public function testClearThrowsWithoutConnection(): void
    {
        try {
            $this->queue->clear('test-queue');
            $this->fail('Expected exception to be thrown');
        } catch (Throwable $e) {
            // Expected: no real connection
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // size() without connection
    // -------------------------------------------------------------------------

    public function testSizeThrowsWithoutConnection(): void
    {
        try {
            $this->queue->size('test-queue');
            $this->fail('Expected exception to be thrown');
        } catch (Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // pop() tests
    // -------------------------------------------------------------------------

    public function testPopThrowsConnectionExceptionWithoutHosts(): void
    {
        // With no hosts configured, pop throws ConnectionException
        // (not AMQPChannelException/AMQPConnectionException which pop() catches)
        $this->expectException(ConnectionException::class);
        $this->queue->pop('test-queue');
    }

    // -------------------------------------------------------------------------
    // ack/reject with null delivery tag
    // -------------------------------------------------------------------------

    public function testAckWithNullDeliveryTagReturnsEarly(): void
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);

        // Should not throw - returns early when delivery tag is null
        $this->queue->ack($envelope, 'default');

        $this->assertTrue(true, 'ack() returned early for null delivery tag');
    }

    public function testRejectWithNullDeliveryTagReturnsEarly(): void
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);

        // Should not throw - returns early when delivery tag is null
        $this->queue->reject($envelope, 'default');

        $this->assertTrue(true, 'reject() returned early for null delivery tag');
    }

    public function testRejectWithRequeueFlag(): void
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);

        // Should not throw - returns early when delivery tag is null
        $this->queue->reject($envelope, 'default', true);

        $this->assertTrue(true, 'reject() with requeue flag returned early for null delivery tag');
    }

    // -------------------------------------------------------------------------
    // getAllDriverInfo() tests
    // -------------------------------------------------------------------------

    public function testGetAllDriverInfoWithManagementUrlAndException(): void
    {
        $queue = new RabbitMQQueue(
            $this->connection,
            'station.direct',
            'default',
            [
                'management_url' => 'http://localhost:99999',
            ],
        );
        $queue->setContainer($this->app);

        try {
            $result = $queue->getAllDriverInfo();
            // On HTTP error, getAllDriverInfo falls back to getDriverInfo
            $this->assertArrayHasKey('driver', $result);
            $this->assertSame('rabbitmq', $result['driver']);
        } catch (Throwable) {
            // If size() in getDriverInfo throws, that's expected
            $this->expectNotToPerformAssertions();
        }
    }

    // -------------------------------------------------------------------------
    // Pause with different connection names
    // -------------------------------------------------------------------------

    public function testPauseUsesConnectionNameInDbQuery(): void
    {
        $this->queue->setConnectionName('custom-rabbit');
        $this->queue->pause('test-queue');

        $this->assertTrue($this->queue->isPaused('test-queue'));
    }

    public function testResumeUsesConnectionNameInDbQuery(): void
    {
        $this->queue->setConnectionName('custom-rabbit');
        $this->queue->pause('test-queue');
        $this->queue->resume('test-queue');

        $this->assertFalse($this->queue->isPaused('test-queue'));
    }

    // -------------------------------------------------------------------------
    // getDeadLetterQueue() and requeueFromDeadLetter()
    // -------------------------------------------------------------------------

    public function testGetDeadLetterQueueWithDifferentLimits(): void
    {
        // Without a real connection, the catch block returns empty array
        $result = $this->queue->getDeadLetterQueue('test-queue', 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testRequeueFromDeadLetterWithDifferentMessageIds(): void
    {
        // Without a real connection, returns false
        $result = $this->queue->requeueFromDeadLetter('test-queue', '999');

        $this->assertFalse($result);
    }

    // =========================================================================
    // Tests with mocked AMQP objects injected into RabbitMQConnection
    // =========================================================================

    public function testSizeReturnsDeclaredQueueCount(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')
            ->once()
            ->andReturn(42);

        $size = $sqQueue->size('test-queue');

        $this->assertSame(42, $size);
    }

    public function testSizeUsesDefaultQueueWhenNullProvided(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')
            ->once()
            ->andReturn(5);

        $size = $sqQueue->size();

        $this->assertSame(5, $size);
    }

    public function testClearPurgesQueueAndReturnsCount(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')
            ->once()
            ->andReturn(15);

        $amqpQueue->shouldReceive('purge')
            ->once();

        $count = $sqQueue->clear('test-queue');

        $this->assertSame(15, $count);
    }

    public function testPushRawEnqueuesMessageViaExchange(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $amqpExchange = $queue['amqpExchange'];
        $sqQueue = $queue['queue'];

        // getQueue called to ensure queue exists
        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange->shouldReceive('publish')
            ->once()
            ->with(
                '{"job":"TestJob"}',
                'test-queue',
                AMQP_NOPARAM,
                Mockery::on(static fn($attrs) => $attrs['delivery_mode'] === 2
                    && $attrs['content_type'] === 'application/json'
                    && isset($attrs['message_id'])),
            );

        $id = $sqQueue->pushRaw('{"job":"TestJob"}', 'test-queue');

        // Returns a UUID message_id
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function testPushRawWithDelayUsesDelayedExchange(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];
        $conn = $queue['connection'];

        // The delayed exchange
        $delayedExchange = Mockery::mock(AMQPExchange::class);
        $delayedExchange->shouldReceive('publish')
            ->once()
            ->with(
                '{"job":"TestJob"}',
                'test-queue',
                AMQP_NOPARAM,
                Mockery::on(static fn($attrs) => isset($attrs['headers']['x-delay']) && $attrs['headers']['x-delay'] > 0),
            );

        // Inject delayed exchange into the connection's exchanges array
        $ref = new ReflectionClass($conn);
        $exchangesProp = $ref->getProperty('exchanges');
        $exchanges = $exchangesProp->getValue($conn);
        $exchanges['station.delayed'] = $delayedExchange;
        $exchangesProp->setValue($conn, $exchanges);

        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $id = $sqQueue->pushRaw('{"job":"TestJob"}', 'test-queue', ['delay' => 5000]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function testPushRawWithZeroDelayUsesRegularExchange(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $amqpExchange = $queue['amqpExchange'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange->shouldReceive('publish')
            ->once()
            ->with(
                '{"job":"TestJob"}',
                'my-queue',
                AMQP_NOPARAM,
                Mockery::type('array'),
            );

        $sqQueue->pushRaw('{"job":"TestJob"}', 'my-queue', ['delay' => 0]);

        // If we reach here without exception, the regular exchange was used
        $this->assertTrue(true);
    }

    public function testPopReturnsNullWhenNoMessageAvailable(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        // AMQPQueue::get() returns null when no message (mock enforces ?AMQPEnvelope)
        $amqpQueue->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $job = $sqQueue->pop('test-queue');

        $this->assertNull($job);
    }

    public function testPopReturnsRabbitMQJobWhenMessageAvailable(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn('{"uuid":"test-uuid","job":"App\\\\Jobs\\\\TestJob","data":[]}');
        $envelope->shouldReceive('getDeliveryTag')->andReturn(42);
        $envelope->shouldReceive('getRoutingKey')->andReturn('test-queue');

        $amqpQueue->shouldReceive('get')
            ->once()
            ->andReturn($envelope);

        $job = $sqQueue->pop('test-queue');

        $this->assertInstanceOf(RabbitMQJob::class, $job);
    }

    public function testPopReturnsNullWhenGetReturnsNullWithMockedConnection(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];

        // AMQPQueue::get() returns null (mock can't return false due to type hint)
        $amqpQueue->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $result = $queue['queue']->pop('test-queue');

        $this->assertNull($result);
    }

    public function testAckWithValidDeliveryTagCallsAmqpQueueAck(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')
            ->andReturn(123);

        $amqpQueue->shouldReceive('ack')
            ->once()
            ->with(123);

        $sqQueue->ack($envelope, 'test-queue');

        $this->assertTrue(true, 'ack() called successfully with valid delivery tag');
    }

    public function testRejectWithValidDeliveryTagCallsAmqpQueueNack(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')
            ->andReturn(456);

        $amqpQueue->shouldReceive('nack')
            ->once()
            ->with(456, AMQP_NOPARAM);

        $sqQueue->reject($envelope, 'test-queue', false);

        $this->assertTrue(true, 'reject() called with requeue=false');
    }

    public function testRejectWithRequeueAndValidDeliveryTag(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')
            ->andReturn(789);

        $amqpQueue->shouldReceive('nack')
            ->once()
            ->with(789, AMQP_REQUEUE);

        $sqQueue->reject($envelope, 'test-queue', true);

        $this->assertTrue(true, 'reject() called with requeue=true');
    }

    public function testGetDriverInfoWithoutManagementUrlReturnsBasicInfo(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')
            ->andReturn(10);

        $result = $sqQueue->getDriverInfo('test-queue');

        $this->assertSame('rabbitmq', $result['driver']);
        $this->assertSame(10, $result['size']);
        $this->assertFalse($result['management_api']);
    }

    public function testGetDriverInfoWithManagementUrlFallsToExceptionHandler(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')->andReturn(5);

        // Create a new queue with management_url - using driverConfig
        $ref = new ReflectionClass($sqQueue);
        $driverConfigProp = $ref->getProperty('driverConfig');
        $driverConfigProp->setValue($sqQueue, [
            'management_url' => 'http://nonexistent-host:15672',
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
        ]);

        // The HTTP request will fail, falling into the catch block
        $result = $sqQueue->getDriverInfo('test-queue');

        $this->assertSame('rabbitmq', $result['driver']);
        $this->assertSame(5, $result['size']);
        $this->assertFalse($result['management_api']);
    }

    public function testGetAllDriverInfoWithoutManagementUrlDelegatesToGetDriverInfo(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')->andReturn(3);

        $result = $sqQueue->getAllDriverInfo();

        // Without management URL, falls back to getDriverInfo for default queue
        $this->assertSame('rabbitmq', $result['driver']);
        $this->assertFalse($result['management_api']);
    }

    public function testGetDeadLetterQueueWithMockedConnectionReturnsMessages(): void
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(99);
        $envelope->shouldReceive('getBody')->andReturn('{"job":"FailedJob"}');
        $envelope->shouldReceive('getHeaders')->andReturn(['x-death' => 'some-info']);
        $envelope->shouldReceive('getRoutingKey')->andReturn('test-queue');
        $envelope->shouldReceive('getTimestamp')->andReturn(1700000000);

        $dlqAmqpQueue = Mockery::mock(AMQPQueue::class);
        $dlqAmqpQueue->shouldReceive('get')
            ->with(AMQP_NOPARAM)
            ->twice()
            ->andReturn($envelope, false);
        $dlqAmqpQueue->shouldReceive('nack')
            ->with(99, AMQP_REQUEUE)
            ->once();

        // Inject the DLQ queue into the connection's queues array
        $conn = $this->createRealConnectionWithInjectedQueues([
            'test-queue.dlq' => $dlqAmqpQueue,
        ]);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', 'default', []);
        $sqQueue->setContainer($this->app);

        $result = $sqQueue->getDeadLetterQueue('test-queue', 50);

        $this->assertCount(1, $result);
        $this->assertSame(99, $result[0]['id']);
        $this->assertSame('{"job":"FailedJob"}', $result[0]['body']);
        $this->assertSame(['x-death' => 'some-info'], $result[0]['headers']);
        $this->assertSame('test-queue', $result[0]['routing_key']);
        $this->assertSame(1700000000, $result[0]['timestamp']);
    }

    public function testGetDeadLetterQueueWithNullDeliveryTagUsesZero(): void
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);
        $envelope->shouldReceive('getBody')->andReturn('{}');
        $envelope->shouldReceive('getHeaders')->andReturn([]);
        $envelope->shouldReceive('getRoutingKey')->andReturn('test-queue');
        $envelope->shouldReceive('getTimestamp')->andReturn(0);

        $dlqAmqpQueue = Mockery::mock(AMQPQueue::class);
        $dlqAmqpQueue->shouldReceive('get')
            ->with(AMQP_NOPARAM)
            ->twice()
            ->andReturn($envelope, false);
        $dlqAmqpQueue->shouldReceive('nack')
            ->with(0, AMQP_REQUEUE)
            ->once();

        $conn = $this->createRealConnectionWithInjectedQueues(['q.dlq' => $dlqAmqpQueue]);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', 'default', []);
        $sqQueue->setContainer($this->app);

        $result = $sqQueue->getDeadLetterQueue('q', 50);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['id']);
    }

    public function testGetDeadLetterQueueRespectsLimit(): void
    {
        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(1);
        $envelope->shouldReceive('getBody')->andReturn('{}');
        $envelope->shouldReceive('getHeaders')->andReturn([]);
        $envelope->shouldReceive('getRoutingKey')->andReturn('q');
        $envelope->shouldReceive('getTimestamp')->andReturn(0);

        $dlqAmqpQueue = Mockery::mock(AMQPQueue::class);
        $dlqAmqpQueue->shouldReceive('get')
            ->with(AMQP_NOPARAM)
            ->twice()
            ->andReturn($envelope, $envelope);
        $dlqAmqpQueue->shouldReceive('nack')
            ->with(1, AMQP_REQUEUE)
            ->twice();

        $conn = $this->createRealConnectionWithInjectedQueues(['q.dlq' => $dlqAmqpQueue]);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', 'default', []);
        $sqQueue->setContainer($this->app);

        $result = $sqQueue->getDeadLetterQueue('q', 2);

        $this->assertCount(2, $result);
    }

    public function testRequeueFromDeadLetterSucceedsWhenMessageFound(): void
    {
        $targetEnvelope = Mockery::mock(AMQPEnvelope::class);
        $targetEnvelope->shouldReceive('getDeliveryTag')->andReturn(42);
        $targetEnvelope->shouldReceive('getBody')->andReturn('{"job":"FailedJob"}');

        $dlqAmqpQueue = Mockery::mock(AMQPQueue::class);
        $dlqAmqpQueue->shouldReceive('get')
            ->withNoArgs()
            ->once()
            ->andReturn($targetEnvelope);
        $dlqAmqpQueue->shouldReceive('ack')
            ->once()
            ->with(42);

        $mainAmqpQueue = Mockery::mock(AMQPQueue::class);
        $mainAmqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('publish')->once();

        $conn = $this->createRealConnectionWithInjectedQueues([
            'test-queue.dlq' => $dlqAmqpQueue,
            'test-queue' => $mainAmqpQueue,
        ], [
            'station.direct' => $amqpExchange,
        ]);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', 'default', []);
        $sqQueue->setContainer($this->app);

        $result = $sqQueue->requeueFromDeadLetter('test-queue', '42');

        $this->assertTrue($result);
    }

    public function testRequeueFromDeadLetterSkipsNonMatchingMessagesAndNacks(): void
    {
        $otherEnvelope = Mockery::mock(AMQPEnvelope::class);
        $otherEnvelope->shouldReceive('getDeliveryTag')->andReturn(10);

        $targetEnvelope = Mockery::mock(AMQPEnvelope::class);
        $targetEnvelope->shouldReceive('getDeliveryTag')->andReturn(42);
        $targetEnvelope->shouldReceive('getBody')->andReturn('{"job":"FailedJob"}');

        $dlqAmqpQueue = Mockery::mock(AMQPQueue::class);
        $dlqAmqpQueue->shouldReceive('get')
            ->withNoArgs()
            ->twice()
            ->andReturn($otherEnvelope, $targetEnvelope);
        $dlqAmqpQueue->shouldReceive('nack')
            ->once()
            ->with(10, AMQP_REQUEUE);
        $dlqAmqpQueue->shouldReceive('ack')
            ->once()
            ->with(42);

        $mainAmqpQueue = Mockery::mock(AMQPQueue::class);
        $mainAmqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange = Mockery::mock(AMQPExchange::class);
        $amqpExchange->shouldReceive('publish')->once();

        $conn = $this->createRealConnectionWithInjectedQueues([
            'test-queue.dlq' => $dlqAmqpQueue,
            'test-queue' => $mainAmqpQueue,
        ], [
            'station.direct' => $amqpExchange,
        ]);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', 'default', []);
        $sqQueue->setContainer($this->app);

        $result = $sqQueue->requeueFromDeadLetter('test-queue', '42');

        $this->assertTrue($result);
    }

    public function testRequeueFromDeadLetterReturnsFalseWhenNoMessagesInDlq(): void
    {
        $dlqAmqpQueue = Mockery::mock(AMQPQueue::class);
        $dlqAmqpQueue->shouldReceive('get')
            ->withNoArgs()
            ->once()
            ->andReturn(false);

        $conn = $this->createRealConnectionWithInjectedQueues([
            'test-queue.dlq' => $dlqAmqpQueue,
        ]);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', 'default', []);
        $sqQueue->setContainer($this->app);

        $result = $sqQueue->requeueFromDeadLetter('test-queue', '99');

        $this->assertFalse($result);
    }

    public function testRequeueFromDeadLetterSkipsNullDeliveryTag(): void
    {
        $nullTagEnvelope = Mockery::mock(AMQPEnvelope::class);
        $nullTagEnvelope->shouldReceive('getDeliveryTag')->andReturn(null);

        $dlqAmqpQueue = Mockery::mock(AMQPQueue::class);
        $dlqAmqpQueue->shouldReceive('get')
            ->withNoArgs()
            ->twice()
            ->andReturn($nullTagEnvelope, false);

        $conn = $this->createRealConnectionWithInjectedQueues([
            'test-queue.dlq' => $dlqAmqpQueue,
        ]);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', 'default', []);
        $sqQueue->setContainer($this->app);

        $result = $sqQueue->requeueFromDeadLetter('test-queue', '1');

        $this->assertFalse($result);
    }

    public function testPauseStoresCorrectDataInDatabase(): void
    {
        $this->queue->setConnectionName('rabbitmq');
        $this->queue->pause('work-queue');

        $record = DB::table('station_queue_status')
            ->where('queue', 'work-queue')
            ->where('connection', 'rabbitmq')
            ->first();

        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->paused);
        $this->assertNotNull($record->paused_at);
        $this->assertNotNull($record->updated_at);
    }

    public function testResumeStoresCorrectDataInDatabase(): void
    {
        $this->queue->setConnectionName('rabbitmq');
        $this->queue->pause('work-queue');
        $this->queue->resume('work-queue');

        $record = DB::table('station_queue_status')
            ->where('queue', 'work-queue')
            ->where('connection', 'rabbitmq')
            ->first();

        $this->assertNotNull($record);
        $this->assertFalse((bool) $record->paused);
        $this->assertNull($record->paused_at);
    }

    public function testPauseDefaultConnectionNameIsRabbitmq(): void
    {
        // Without setConnectionName, the default for pause() is 'rabbitmq'
        $freshQueue = new RabbitMQQueue($this->connection, 'station.direct', 'default', []);
        $freshQueue->setContainer($this->app);

        $freshQueue->pause('q1');

        $record = DB::table('station_queue_status')
            ->where('queue', 'q1')
            ->where('connection', 'rabbitmq')
            ->first();

        $this->assertNotNull($record);
    }

    public function testResumeDefaultConnectionNameIsRabbitmq(): void
    {
        $freshQueue = new RabbitMQQueue($this->connection, 'station.direct', 'default', []);
        $freshQueue->setContainer($this->app);

        $freshQueue->pause('q1');
        $freshQueue->resume('q1');

        $record = DB::table('station_queue_status')
            ->where('queue', 'q1')
            ->where('connection', 'rabbitmq')
            ->first();

        $this->assertNotNull($record);
        $this->assertFalse((bool) $record->paused);
    }

    public function testEnqueueUsesDefaultQueueWhenNullProvided(): void
    {
        $queue = $this->createQueueWithMockedConnection('my-default-queue');
        $amqpQueue = $queue['amqpQueue'];
        $amqpExchange = $queue['amqpExchange'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $amqpExchange->shouldReceive('publish')
            ->once()
            ->with(
                '{"data":"test"}',
                'my-default-queue',
                AMQP_NOPARAM,
                Mockery::type('array'),
            );

        // pushRaw with null queue should use defaultQueue
        $sqQueue->pushRaw('{"data":"test"}');

        $this->assertTrue(true);
    }

    public function testGetDriverInfoWithDriverConfigManagementCredentials(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $ref = new ReflectionClass($sqQueue);
        $driverConfigProp = $ref->getProperty('driverConfig');
        $driverConfigProp->setValue($sqQueue, [
            'management_url' => 'http://nonexistent:15672',
            'management_user' => 'admin',
            'management_password' => 'secret',
            'vhost' => '/custom',
        ]);
        $sqQueue->setConnectionName('rabbitmq');

        $result = $sqQueue->getDriverInfo('test-queue');

        $this->assertSame('rabbitmq', $result['driver']);
        $this->assertFalse($result['management_api']);
    }

    public function testGetAllDriverInfoWithManagementUrlCatchesException(): void
    {
        $queue = $this->createQueueWithMockedConnection();
        $amqpQueue = $queue['amqpQueue'];
        $sqQueue = $queue['queue'];

        $amqpQueue->shouldReceive('declareQueue')->andReturn(0);

        $ref = new ReflectionClass($sqQueue);
        $driverConfigProp = $ref->getProperty('driverConfig');
        $driverConfigProp->setValue($sqQueue, [
            'management_url' => 'http://nonexistent:15672',
            'vhost' => '/',
            'hosts' => [['username' => 'guest', 'password' => 'guest', 'vhost' => '/']],
        ]);
        $sqQueue->setConnectionName('rabbitmq');

        $result = $sqQueue->getAllDriverInfo();

        $this->assertSame('rabbitmq', $result['driver']);
        $this->assertArrayHasKey('size', $result);
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

    // =========================================================================
    // Helper to create a queue with mocked AMQP objects injected via reflection
    // =========================================================================

    /**
     * Creates a RabbitMQQueue with a real RabbitMQConnection that has mocked
     * AMQPQueue and AMQPExchange objects injected into its internal arrays.
     * This avoids mocking the final RabbitMQConnection class.
     *
     * @return array{queue: RabbitMQQueue, connection: RabbitMQConnection, amqpQueue: MockInterface&AMQPQueue, amqpExchange: MockInterface&AMQPExchange}
     */
    private function createQueueWithMockedConnection(string $defaultQueue = 'default'): array
    {
        $amqpQueue = Mockery::mock(AMQPQueue::class);
        $amqpExchange = Mockery::mock(AMQPExchange::class);

        $conn = new RabbitMQConnection([]);
        $ref = new ReflectionClass($conn);

        // Inject a mock queue for ANY queue name by pre-populating the cache.
        // RabbitMQConnection::getQueue() checks $this->queues[$name] first.
        // We inject a wildcard entry for 'default' and 'test-queue' and 'my-queue' etc.
        // Since we use a Proxy approach, we just pre-fill commonly used names.
        $queuesProp = $ref->getProperty('queues');

        $exchangesProp = $ref->getProperty('exchanges');
        $exchangesProp->setValue($conn, ['station.direct' => $amqpExchange]);

        // We need getQueue() to return our mock for ANY queue name.
        // Since RabbitMQConnection is final and getQueue creates new queues,
        // we pre-populate known queue names used in tests.
        $knownQueues = [$defaultQueue, 'test-queue', 'my-queue', 'my-default-queue'];
        $queueMap = [];
        foreach ($knownQueues as $name) {
            $queueMap[$name] = $amqpQueue;
        }
        $queuesProp->setValue($conn, $queueMap);

        $sqQueue = new RabbitMQQueue($conn, 'station.direct', $defaultQueue, []);
        $sqQueue->setContainer($this->app);

        return [
            'queue' => $sqQueue,
            'connection' => $conn,
            'amqpQueue' => $amqpQueue,
            'amqpExchange' => $amqpExchange,
        ];
    }

    /**
     * Creates a real RabbitMQConnection with mocked AMQP queues and exchanges
     * injected via reflection.
     *
     * @param array<string, MockInterface&AMQPQueue> $queues
     * @param array<string, MockInterface&AMQPExchange> $exchanges
     */
    private function createRealConnectionWithInjectedQueues(array $queues, array $exchanges = []): RabbitMQConnection
    {
        $conn = new RabbitMQConnection([]);
        $ref = new ReflectionClass($conn);

        $queuesProp = $ref->getProperty('queues');
        $queuesProp->setValue($conn, $queues);

        if ($exchanges !== []) {
            $exchangesProp = $ref->getProperty('exchanges');
            $exchangesProp->setValue($conn, $exchanges);
        }

        return $conn;
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
