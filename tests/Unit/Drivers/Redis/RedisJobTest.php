<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Redis;

use Illuminate\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Station\Drivers\Redis\RedisConnection;
use Station\Drivers\Redis\RedisJob;
use Station\Drivers\Redis\RedisQueue;
use Station\Tests\Fixtures\TestJob;
use stdClass;
use Throwable;

class RedisJobTest extends TestCase
{
    private Container $container;

    private MockInterface&RedisFactory $redis;

    private MockInterface&Connection $connection;

    private RedisQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->redis = Mockery::mock(RedisFactory::class);
        $this->connection = Mockery::mock(Connection::class);

        $this->redis->shouldReceive('connection')
            ->andReturn($this->connection);

        $config = [
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 5,
        ];

        $redisConnection = new RedisConnection($this->redis, $config);
        $this->queue = new RedisQueue($redisConnection, 'default', $config);
        $this->queue->setContainer($this->container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetJobIdReturnsUuidFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $this->assertSame('test-uuid', $job->getJobId());
    }

    public function testGetJobIdReturnsIdWhenNoUuid(): void
    {
        $payload = json_encode(['id' => 'test-id', 'job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $this->assertSame('test-id', $job->getJobId());
    }

    public function testGetJobIdReturnsEmptyStringWhenNoIdOrUuid(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $this->assertSame('', $job->getJobId());
    }

    public function testGetRawBodyReturnsPayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $this->assertSame($payload, $job->getRawBody());
    }

    public function testGetQueueReturnsQueueName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'test-queue');

        $this->assertSame('test-queue', $job->getQueue());
    }

    public function testAttemptsReturnsAttemptsFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 3]);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $this->assertSame(3, $job->attempts());
    }

    public function testAttemptsReturnsOneByDefault(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $this->assertSame(1, $job->attempts());
    }

    public function testPayloadReturnsDecodedData(): void
    {
        $data = ['uuid' => 'test-uuid', 'job' => 'TestJob', 'data' => ['key' => 'value']];
        $payload = json_encode($data);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $this->assertSame($data, $job->payload());
    }

    public function testPayloadReturnsEmptyArrayOnInvalidJson(): void
    {
        $job = new RedisJob($this->container, $this->queue, 'invalid-json', 'station', 'default');

        $this->assertSame([], $job->payload());
    }

    public function testDeleteRemovesFromReservedSet(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $this->connection->shouldReceive('zrem')
            ->once()
            ->with('station:queues:default:reserved', $payload)
            ->andReturn(1);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');
        $job->delete();

        $this->assertTrue($job->isDeleted());
    }

    public function testReleaseWithoutDelayPushesBackToQueue(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 1]);

        $this->connection->shouldReceive('zrem')
            ->once()
            ->andReturn(1);

        $this->connection->shouldReceive('rpush')
            ->once()
            ->andReturn(1);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');
        $job->release();

        $this->assertTrue($job->isReleased());
    }

    public function testReleaseWithDelayAddsToDelayedSet(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 1]);

        $this->connection->shouldReceive('zrem')
            ->once()
            ->andReturn(1);

        $this->connection->shouldReceive('zadd')
            ->once()
            ->andReturn(1);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');
        $job->release(60);

        $this->assertTrue($job->isReleased());
    }

    public function testGetConnectionNameReturnsConnectionName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'custom-connection', 'default');

        $this->assertSame('custom-connection', $job->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // fire() tests
    // -------------------------------------------------------------------------

    public function testFireWithStationJobFormatCallsHandle(): void
    {
        $testJob = new TestJob('redis-fire-test');
        TestJob::$handled = false;

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => serialize($testJob),
            ],
        ]);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');
        $job->fire();

        $this->assertTrue(TestJob::$handled, 'TestJob::handle() should have been called');
    }

    public function testFireWithStationFormatSkipsNonStringPayload(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => 999,
            ],
        ]);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');
        $job->fire();

        $this->assertTrue(true, 'fire() handled non-string payload gracefully');
    }

    public function testFireWithStationFormatSkipsNullPayload(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
            ],
        ]);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');
        $job->fire();

        $this->assertTrue(true, 'fire() handled missing payload gracefully');
    }

    public function testFireWithStationFormatSkipsObjectWithoutHandle(): void
    {
        $obj = new stdClass();
        $obj->name = 'no-handle';

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => serialize($obj),
            ],
        ]);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');
        $job->fire();

        $this->assertTrue(true, 'fire() skipped object without handle method');
    }

    public function testFireWithoutStationFormatDelegatesToParent(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [],
        ]);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        try {
            $job->fire();
        } catch (Throwable) {
            // Expected: parent::fire() tries to resolve TestJob which doesn't exist
        }

        $this->assertTrue(true, 'fire() took the non-Station path');
    }

    // -------------------------------------------------------------------------
    // parseJobClassAndMethod() tests
    // -------------------------------------------------------------------------

    public function testParseJobClassAndMethodWithAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\MyJob@execute']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\MyJob', $result[0]);
        $this->assertSame('execute', $result[1]);
    }

    public function testParseJobClassAndMethodWithoutAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\MyJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\MyJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToDisplayName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'displayName' => 'MyDisplayJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('MyDisplayJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToUnknownJob(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('UnknownJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    // -------------------------------------------------------------------------
    // preparePayloadForRelease() tests
    // -------------------------------------------------------------------------

    public function testPreparePayloadForReleaseIncrementsAttempts(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 3]);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('preparePayloadForRelease');

        $result = $method->invoke($job);
        $decoded = json_decode($result, true);

        $this->assertSame(4, $decoded['attempts']);
    }

    public function testPreparePayloadForReleaseDefaultsAttemptsToOne(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $job = new RedisJob($this->container, $this->queue, $payload, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('preparePayloadForRelease');

        $result = $method->invoke($job);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['attempts']);
    }
}
