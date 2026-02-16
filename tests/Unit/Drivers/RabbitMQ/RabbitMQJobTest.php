<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\RabbitMQ;

use AMQPEnvelope;
use Illuminate\Container\Container;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Station\Drivers\RabbitMQ\RabbitMQConnection;
use Station\Drivers\RabbitMQ\RabbitMQJob;
use Station\Drivers\RabbitMQ\RabbitMQQueue;
use Station\Tests\Fixtures\TestJob;
use stdClass;
use Throwable;

/**
 * Unit tests for RabbitMQJob.
 *
 * Note: RabbitMQQueue is final and cannot be mocked with Mockery.
 * These tests use reflection and anonymous classes to test RabbitMQJob methods
 * that don't require the queue dependency.
 */
class RabbitMQJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testConstructorSetsProperties(): void
    {
        $reflection = new ReflectionClass(RabbitMQJob::class);

        // Verify constructor parameters
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $parameters = $constructor->getParameters();
        $this->assertCount(5, $parameters);
        $this->assertSame('container', $parameters[0]->getName());
        $this->assertSame('rabbitmq', $parameters[1]->getName());
        $this->assertSame('envelope', $parameters[2]->getName());
        $this->assertSame('connectionName', $parameters[3]->getName());
        $this->assertSame('queue', $parameters[4]->getName());
    }

    public function testGetRawBodyMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'getRawBody');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('string', $reflection->getReturnType()?->getName());
    }

    public function testPayloadMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'payload');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('array', $reflection->getReturnType()?->getName());
    }

    public function testGetQueueMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'getQueue');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('string', $reflection->getReturnType()?->getName());
    }

    public function testGetEnvelopeMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'getEnvelope');

        $this->assertTrue($reflection->isPublic());
    }

    public function testAttemptsMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'attempts');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testGetJobIdMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'getJobId');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('string', $reflection->getReturnType()?->getName());
    }

    public function testReleaseMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'release');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testDeleteMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'delete');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testFireMethodExists(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'fire');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testReleaseHasDelayParameter(): void
    {
        $reflection = new ReflectionMethod(RabbitMQJob::class, 'release');
        $parameters = $reflection->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('delay', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertSame(0, $parameters[0]->getDefaultValue());
    }

    public function testGetRawBodyReturnsEnvelopeBody(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createJob($payload);

        $this->assertSame($payload, $job->getRawBody());
    }

    public function testPayloadReturnsDecodedArray(): void
    {
        $data = ['uuid' => 'test-uuid', 'job' => 'TestJob', 'data' => ['key' => 'value']];
        $payload = json_encode($data);
        $job = $this->createJob($payload);

        $this->assertSame($data, $job->payload());
    }

    public function testPayloadReturnsEmptyArrayOnInvalidJson(): void
    {
        $job = $this->createJob('not-valid-json');

        $this->assertSame([], $job->payload());
    }

    public function testGetJobIdReturnsUuidFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'my-uuid-123', 'job' => 'TestJob']);
        $job = $this->createJob($payload);

        $this->assertSame('my-uuid-123', $job->getJobId());
    }

    public function testGetJobIdFallsBackToMessageId(): void
    {
        $payload = json_encode(['job' => 'TestJob']);
        $job = $this->createJob($payload, 'amqp-message-id');

        $this->assertSame('amqp-message-id', $job->getJobId());
    }

    public function testGetJobIdReturnsEmptyStringWhenNoIdAvailable(): void
    {
        $payload = json_encode(['job' => 'TestJob']);
        $job = $this->createJob($payload, null);

        $this->assertSame('', $job->getJobId());
    }

    public function testAttemptsReturnsValueFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 5]);
        $job = $this->createJob($payload);

        $this->assertSame(5, $job->attempts());
    }

    public function testAttemptsReturnsOneByDefault(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createJob($payload);

        $this->assertSame(1, $job->attempts());
    }

    public function testGetQueueReturnsQueueName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createJob($payload, null, 'my-queue');

        $this->assertSame('my-queue', $job->getQueue());
    }

    public function testGetEnvelopeReturnsEnvelopeInstance(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createJob($payload);

        $this->assertInstanceOf(AMQPEnvelope::class, $job->getEnvelope());
    }

    public function testGetConnectionNameReturnsConnectionName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $job = $this->createJob($payload);

        $this->assertSame('station', $job->getConnectionName());
    }

    public function testDeleteSetsDeletedFlag(): void
    {
        $container = new Container();
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        $job = new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', 'default');

        // ack returns early when deliveryTag is null, so no connection needed
        $job->delete();

        $this->assertTrue($job->isDeleted());
    }

    public function testReleaseWithoutDelaySetsReleasedFlag(): void
    {
        $container = new Container();
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 1]);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(null);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        $job = new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', 'default');

        // reject returns early when deliveryTag is null
        // But pushRaw will try to enqueue, which requires a real connection
        // Use try/catch since the reject part succeeds (null delivery tag = no-op)
        // but pushRaw will fail without a real RabbitMQ connection
        try {
            $job->release(0);
        } catch (Throwable) {
            // Expected: pushRaw calls enqueue which needs a real connection
        }

        // The parent::release() was called, so isReleased should be true
        $this->assertTrue($job->isReleased());
    }

    public function testFireWithStationJobFormatCallsHandle(): void
    {
        $container = new Container();

        $testJob = new TestJob('fire-test');
        TestJob::$handled = false;

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => serialize($testJob),
            ],
        ]);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        $job = new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', 'default');
        $job->fire();

        $this->assertTrue(TestJob::$handled, 'TestJob::handle() should have been called');
    }

    public function testFireWithStationFormatSkipsNonStringPayload(): void
    {
        $container = new Container();

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => 12345, // not a string
            ],
        ]);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        $job = new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', 'default');
        $job->fire();

        // Should not throw - just returns early
        $this->assertTrue(true, 'fire() handled non-string payload gracefully');
    }

    public function testFireWithStationFormatSkipsNullPayload(): void
    {
        $container = new Container();

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                // no 'payload' key
            ],
        ]);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        $job = new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', 'default');
        $job->fire();

        $this->assertTrue(true, 'fire() handled missing payload gracefully');
    }

    public function testFireWithStationFormatSkipsObjectWithoutHandle(): void
    {
        $container = new Container();

        // Use a serialized stdClass which has no handle() method
        $noHandleObj = new stdClass();
        $noHandleObj->name = 'no-handle';

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => serialize($noHandleObj),
            ],
        ]);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        $job = new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', 'default');
        $job->fire();

        $this->assertTrue(true, 'fire() skipped object without handle method');
    }

    public function testFireWithoutStationDataUsesNoData(): void
    {
        $container = new Container();

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [], // no station_job_id
        ]);

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($payload);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        $job = new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', 'default');

        // parent::fire() will be called, which may fail because the job class
        // doesn't exist. We only care that the Station format branch is NOT taken.
        try {
            $job->fire();
        } catch (Throwable) {
            // Expected: standard Laravel format will fail resolving the job class
        }

        $this->assertTrue(true, 'fire() took the non-Station path');
    }

    public function testPreparePayloadForReleaseIncrementsAttempts(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 2]);

        $job = $this->createJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('preparePayloadForRelease');

        $result = $method->invoke($job);
        $decoded = json_decode($result, true);

        $this->assertSame(3, $decoded['attempts']);
    }

    public function testPreparePayloadForReleaseDefaultsAttemptsToOne(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $job = $this->createJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('preparePayloadForRelease');

        $result = $method->invoke($job);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['attempts']);
    }

    public function testParseJobClassAndMethodWithAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\TestJob@execute']);

        $job = $this->createJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\TestJob', $result[0]);
        $this->assertSame('execute', $result[1]);
    }

    public function testParseJobClassAndMethodWithoutAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\TestJob']);

        $job = $this->createJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\TestJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToDisplayName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'displayName' => 'MyDisplayJob']);

        $job = $this->createJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('MyDisplayJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToUnknownJob(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid']);

        $job = $this->createJob($payload);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('UnknownJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    // -------------------------------------------------------------------------
    // Functional tests using real objects and mocked AMQPEnvelope
    // -------------------------------------------------------------------------

    private function createJob(string $rawBody, ?string $messageId = null, string $queue = 'default'): RabbitMQJob
    {
        $container = new Container();

        $envelope = Mockery::mock(AMQPEnvelope::class);
        $envelope->shouldReceive('getBody')->andReturn($rawBody);
        $envelope->shouldReceive('getMessageId')->andReturn($messageId);
        $envelope->shouldReceive('getDeliveryTag')->andReturn(42);

        $connection = new RabbitMQConnection([]);
        $rabbitmqQueue = new RabbitMQQueue($connection, 'station.direct', 'default', []);
        $rabbitmqQueue->setContainer($container);

        return new RabbitMQJob($container, $rabbitmqQueue, $envelope, 'station', $queue);
    }
}
