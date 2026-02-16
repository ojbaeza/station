<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Redis;

use Exception;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Contracts\AggregateDriverInfoInterface;
use Station\Drivers\Redis\RedisConnection;
use Station\Drivers\Redis\RedisJob;
use Station\Drivers\Redis\RedisQueue;
use Station\StationServiceProvider;

class RedisQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&RedisFactory $redis;

    private MockInterface&Connection $connection;

    private RedisConnection $redisConnection;

    private RedisQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->redisConnection = new RedisConnection($this->redis, $config);
        $this->queue = new RedisQueue($this->redisConnection, 'default', $config);
        $this->queue->setContainer($this->app);

        $this->createQueueStatusTable();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testPushRawAddsJobToQueue(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => []]);

        $this->connection->shouldReceive('rpush')
            ->once()
            ->with('station:queues:default', Mockery::any())
            ->andReturn(1);

        $id = $this->queue->pushRaw($payload, 'default');

        $this->assertNotEmpty($id);
    }

    public function testLaterRawAddsJobToDelayedSet(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => []]);

        $this->connection->shouldReceive('zadd')
            ->once()
            ->with('station:queues:default:delayed', Mockery::any(), Mockery::any())
            ->andReturn(1);

        $id = $this->queue->laterRaw(60, $payload, 'default');

        $this->assertNotEmpty($id);
    }

    public function testSizeReturnsQueueLength(): void
    {
        // Mock llen for waiting jobs
        $this->connection->shouldReceive('llen')
            ->once()
            ->with('station:queues:test')
            ->andReturn(20);

        // Mock zcard for delayed jobs
        $this->connection->shouldReceive('zcard')
            ->once()
            ->with('station:queues:test:delayed')
            ->andReturn(15);

        // Mock zcard for reserved jobs
        $this->connection->shouldReceive('zcard')
            ->once()
            ->with('station:queues:test:reserved')
            ->andReturn(7);

        $size = $this->queue->size('test');

        $this->assertSame(42, $size);
    }

    public function testPopReturnsNullWhenQueueIsEmpty(): void
    {
        // Allow any Redis operations needed for pop
        $this->connection->shouldReceive('get')->andReturn(null);
        $this->connection->shouldReceive('eval')->andReturn([]);
        $this->connection->shouldReceive('zrangebyscore')->andReturn([]);
        $this->connection->shouldReceive('lpop')->andReturn(null);

        $job = $this->queue->pop('default');

        $this->assertNull($job);
    }

    public function testPopReturnsJobWhenAvailable(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [],
        ]);

        // Allow any Redis operations needed for pop
        $this->connection->shouldReceive('get')->andReturn(null);
        $this->connection->shouldReceive('eval')->andReturn([]);
        $this->connection->shouldReceive('zrangebyscore')->andReturn([]);
        $this->connection->shouldReceive('lpop')->andReturn($payload);
        $this->connection->shouldReceive('zadd')->andReturn(1);

        $job = $this->queue->pop('default');

        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertSame('test-uuid', $job->getJobId());
    }

    public function testGetConnectionNameReturnsStationByDefault(): void
    {
        $this->assertSame('station', $this->queue->getConnectionName());
    }

    public function testSetConnectionNameUpdatesName(): void
    {
        $this->queue->setConnectionName('custom');

        $this->assertSame('custom', $this->queue->getConnectionName());
    }

    public function testPauseQueue(): void
    {
        $this->connection->shouldReceive('set')
            ->once()
            ->with('station:queues:test:paused', '1');

        $this->queue->pause('test');

        // Check if paused via Redis lookup
        $this->connection->shouldReceive('get')
            ->once()
            ->with('station:queues:test:paused')
            ->andReturn('1');

        $this->assertTrue($this->queue->isPaused('test'));
    }

    public function testResumeQueue(): void
    {
        // First pause
        $this->connection->shouldReceive('set')
            ->once()
            ->with('station:queues:test:paused', '1');

        $this->queue->pause('test');

        // Then resume
        $this->connection->shouldReceive('del')
            ->once()
            ->with('station:queues:test:paused');

        $this->queue->resume('test');

        // After resume, isPaused will check Redis
        $this->connection->shouldReceive('get')
            ->once()
            ->with('station:queues:test:paused')
            ->andReturn(null);

        $this->assertFalse($this->queue->isPaused('test'));
    }

    public function testIsPausedChecksRedisWhenNotCached(): void
    {
        $this->connection->shouldReceive('get')
            ->once()
            ->with('station:queues:test:paused')
            ->andReturn('1');

        $this->assertTrue($this->queue->isPaused('test'));
    }

    public function testClearRemovesAllJobs(): void
    {
        $this->connection->shouldReceive('llen')
            ->once()
            ->with('station:queues:test')
            ->andReturn(10);

        $this->connection->shouldReceive('zcard')
            ->once()
            ->with('station:queues:test:delayed')
            ->andReturn(5);

        $this->connection->shouldReceive('zcard')
            ->once()
            ->with('station:queues:test:reserved')
            ->andReturn(3);

        $this->connection->shouldReceive('del')
            ->once()
            ->with([
                'station:queues:test',
                'station:queues:test:delayed',
                'station:queues:test:reserved',
            ]);

        $count = $this->queue->clear('test');

        $this->assertSame(18, $count);
    }

    public function testHealthCheckReturnsConnectedOnSuccess(): void
    {
        $this->connection->shouldReceive('ping')
            ->once()
            ->andReturn(true);

        $health = $this->queue->healthCheck();

        $this->assertTrue($health['connected']);
        $this->assertArrayHasKey('latency_ms', $health);
    }

    public function testHealthCheckReturnsDisconnectedOnFailure(): void
    {
        $this->connection->shouldReceive('ping')
            ->once()
            ->andThrow(new Exception('Connection refused'));

        $health = $this->queue->healthCheck();

        $this->assertFalse($health['connected']);
        $this->assertSame(0, $health['latency_ms']);
        $this->assertSame('Connection refused', $health['message']);
    }

    public function testGetDeadLetterQueue(): void
    {
        $payload1 = json_encode(['job' => 'TestJob1', 'uuid' => 'uuid1']);
        $payload2 = json_encode(['job' => 'TestJob2', 'uuid' => 'uuid2']);

        $this->connection->shouldReceive('lrange')
            ->once()
            ->with('station:queues:test:failed', 0, 49)
            ->andReturn([$payload1, $payload2]);

        $dlq = $this->queue->getDeadLetterQueue('test');

        $this->assertCount(2, $dlq);
        $this->assertSame(0, $dlq[0]['id']);
        $this->assertSame($payload1, $dlq[0]['body']);
    }

    public function testRequeueFromDeadLetter(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'uuid' => 'test-uuid']);

        $this->connection->shouldReceive('lindex')
            ->once()
            ->with('station:queues:test:failed', 0)
            ->andReturn($payload);

        $this->connection->shouldReceive('lrem')
            ->once()
            ->with('station:queues:test:failed', $payload, 1)
            ->andReturn(1);

        $this->connection->shouldReceive('rpush')
            ->once()
            ->andReturn(1);

        $result = $this->queue->requeueFromDeadLetter('test', '0');

        $this->assertTrue($result);
    }

    public function testRequeueFromDeadLetterReturnsFalseWhenNotFound(): void
    {
        $this->connection->shouldReceive('lindex')
            ->once()
            ->with('station:queues:test:failed', 99)
            ->andReturn(null);

        $result = $this->queue->requeueFromDeadLetter('test', '99');

        $this->assertFalse($result);
    }

    public function testDeleteReserved(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->connection->shouldReceive('zrem')
            ->once()
            ->with('station:queues:test:reserved', $payload)
            ->andReturn(1);

        $this->queue->deleteReserved('test', $payload);

        // Assert the mock was called as expected
    }

    public function testMoveToFailed(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->connection->shouldReceive('zrem')
            ->once()
            ->with('station:queues:test:reserved', $payload)
            ->andReturn(1);

        $this->connection->shouldReceive('rpush')
            ->once()
            ->with('station:queues:test:failed', $payload)
            ->andReturn(1);

        $this->queue->moveToFailed('test', $payload);

        // Assert the mock was called as expected
    }

    public function testPopReturnsNullWhenPaused(): void
    {
        // Pause the queue
        $this->connection->shouldReceive('set')
            ->once()
            ->with('station:queues:test:paused', '1');

        $this->queue->pause('test');

        // isPaused will check Redis
        $this->connection->shouldReceive('get')
            ->once()
            ->with('station:queues:test:paused')
            ->andReturn('1');

        $job = $this->queue->pop('test');

        $this->assertNull($job);
    }

    public function testQueueImplementsAggregateDriverInfoInterface(): void
    {
        $this->assertInstanceOf(AggregateDriverInfoInterface::class, $this->queue);
    }

    public function testGetAllDriverInfoAggregatesMultipleQueues(): void
    {
        // SCAN returns queue keys
        $this->connection->shouldReceive('scan')
            ->once()
            ->andReturn([0, [
                'station:queues:default',
                'station:queues:default:delayed',
                'station:queues:default:reserved',
                'station:queues:high',
                'station:queues:high:delayed',
            ]]);

        // Per-queue size queries for 'default'
        $this->connection->shouldReceive('llen')
            ->with('station:queues:default')
            ->andReturn(10);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:default:delayed')
            ->andReturn(3);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:default:reserved')
            ->andReturn(2);

        // Per-queue size queries for 'high'
        $this->connection->shouldReceive('llen')
            ->with('station:queues:high')
            ->andReturn(20);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:high:delayed')
            ->andReturn(5);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:high:reserved')
            ->andReturn(1);

        // Redis server stats
        $this->connection->shouldReceive('info')
            ->with('memory')
            ->andReturn(['used_memory' => '1048576', 'used_memory_peak' => '2097152']);
        $this->connection->shouldReceive('info')
            ->with('clients')
            ->andReturn(['connected_clients' => '5']);
        $this->connection->shouldReceive('info')
            ->with('stats')
            ->andReturn(['instantaneous_ops_per_sec' => '100']);

        $result = $this->queue->getAllDriverInfo();

        $this->assertSame('redis', $result['driver']);
        $this->assertSame(41, $result['size']); // 10+3+2 + 20+5+1
        $this->assertSame(30, $result['ready']); // 10 + 20
        $this->assertSame(8, $result['delayed']); // 3 + 5
        $this->assertSame(3, $result['reserved']); // 2 + 1
        $this->assertArrayHasKey('queues', $result);
        $this->assertCount(2, $result['queues']);
        $this->assertSame(2, $result['queues_total']);
        $this->assertSame(15, $result['queues']['default']['size']);
        $this->assertSame(26, $result['queues']['high']['size']);
        $this->assertSame(1048576, $result['memory_used']);
        $this->assertSame(5, $result['connected_clients']);
    }

    public function testGetAllDriverInfoFallsBackOnScanFailure(): void
    {
        // SCAN throws an exception
        $this->connection->shouldReceive('scan')
            ->once()
            ->andThrow(new Exception('SCAN failed'));

        // Falls back to getDriverInfo which needs these calls
        $this->connection->shouldReceive('llen')
            ->with('station:queues:default')
            ->andReturn(5);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:default:delayed')
            ->andReturn(0);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:default:reserved')
            ->andReturn(0);

        // Server stats
        $this->connection->shouldReceive('info')
            ->with('memory')
            ->andReturn(['used_memory' => '0', 'used_memory_peak' => '0']);
        $this->connection->shouldReceive('info')
            ->with('clients')
            ->andReturn(['connected_clients' => '1']);
        $this->connection->shouldReceive('info')
            ->with('stats')
            ->andReturn(['instantaneous_ops_per_sec' => '0']);

        $result = $this->queue->getAllDriverInfo();

        $this->assertSame('redis', $result['driver']);
        $this->assertSame(5, $result['size']);
        $this->assertArrayNotHasKey('queues', $result);
    }

    public function testGetAllDriverInfoFallsBackWhenNoQueuesDiscovered(): void
    {
        // SCAN returns no matching keys
        $this->connection->shouldReceive('scan')
            ->once()
            ->andReturn([0, []]);

        // Falls back to single-queue getDriverInfo
        $this->connection->shouldReceive('llen')
            ->with('station:queues:default')
            ->andReturn(0);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:default:delayed')
            ->andReturn(0);
        $this->connection->shouldReceive('zcard')
            ->with('station:queues:default:reserved')
            ->andReturn(0);

        $this->connection->shouldReceive('info')
            ->with('memory')
            ->andReturn(['used_memory' => '0', 'used_memory_peak' => '0']);
        $this->connection->shouldReceive('info')
            ->with('clients')
            ->andReturn(['connected_clients' => '1']);
        $this->connection->shouldReceive('info')
            ->with('stats')
            ->andReturn(['instantaneous_ops_per_sec' => '0']);

        $result = $this->queue->getAllDriverInfo();

        $this->assertSame('redis', $result['driver']);
        $this->assertArrayNotHasKey('queues', $result);
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
