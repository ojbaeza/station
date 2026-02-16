<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Kafka;

use Exception;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Metadata;
use RdKafka\Metadata\Collection;
use RdKafka\Metadata\Partition;
use RdKafka\Metadata\Topic;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use ReflectionClass;
use RuntimeException;
use Station\Drivers\Kafka\KafkaConnection;
use Station\Drivers\Kafka\KafkaJob;
use Station\Drivers\Kafka\KafkaQueue;
use Station\StationServiceProvider;

class KafkaQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&Producer $producer;

    private MockInterface&KafkaConsumer $consumer;

    private KafkaConnection $connection;

    private KafkaQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('The rdkafka extension is not available.');
        }

        $this->producer = Mockery::mock(Producer::class);
        $this->consumer = Mockery::mock(KafkaConsumer::class);

        $config = [
            'brokers' => '127.0.0.1:9092',
            'queue' => 'default',
            'group_id' => 'station',
            'auto_offset_reset' => 'earliest',
            'auto_commit' => false,
            'consume_timeout' => 5000,
            'flush_timeout' => 10000,
        ];

        $this->connection = new KafkaConnection($config);

        // Use reflection to inject the mocked producer and consumer
        $reflection = new ReflectionClass($this->connection);

        $producerProperty = $reflection->getProperty('producer');

        $producerProperty->setValue($this->connection, $this->producer);

        $consumerProperty = $reflection->getProperty('consumer');

        $consumerProperty->setValue($this->connection, $this->consumer);

        $this->queue = new KafkaQueue($this->connection, 'default');
        $this->queue->setContainer($this->app);
    }

    protected function tearDown(): void
    {
        // Explicitly null out RdKafka mock references to prevent heap corruption
        // from the rdkafka extension trying to cleanup mock objects
        if (isset($this->connection)) {
            $reflection = new ReflectionClass($this->connection);

            $producerProperty = $reflection->getProperty('producer');

            $producerProperty->setValue($this->connection, null);

            $consumerProperty = $reflection->getProperty('consumer');

            $consumerProperty->setValue($this->connection, null);
        }

        parent::tearDown();
    }

    public function testPushRawProducesMessageToTopic(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'data' => []]);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once()
            ->with(RD_KAFKA_PARTITION_UA, 0, $payload, null);

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->with('default')
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->with(10000)
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $id = $this->queue->pushRaw($payload, 'default');

        $this->assertSame('test-uuid', $id);
    }

    public function testPushRawWithKeyUsesKeyForPartitioning(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once()
            ->with(RD_KAFKA_PARTITION_UA, 0, $payload, 'partition-key');

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $id = $this->queue->pushRaw($payload, 'default', ['key' => 'partition-key']);

        $this->assertSame('test-uuid', $id);
    }

    public function testPopReturnsNullWhenNoMessages(): void
    {
        $this->createQueueStatusTable();

        $this->consumer->shouldReceive('subscribe')
            ->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR__TIMED_OUT;

        $this->consumer->shouldReceive('consume')
            ->once()
            ->with(5000)
            ->andReturn($message);

        $job = $this->queue->pop('default');

        $this->assertNull($job);
    }

    public function testPopReturnsJobWhenMessageAvailable(): void
    {
        $this->createQueueStatusTable();

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [],
        ]);

        $this->consumer->shouldReceive('subscribe')
            ->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;
        $message->payload = $payload;
        $message->topic_name = 'default';
        $message->partition = 0;
        $message->offset = 100;
        $message->timestamp = time() * 1000;

        $this->consumer->shouldReceive('consume')
            ->once()
            ->with(5000)
            ->andReturn($message);

        $job = $this->queue->pop('default');

        $this->assertInstanceOf(KafkaJob::class, $job);
        $this->assertSame('test-uuid', $job->getJobId());
    }

    public function testCommitOffsetCommitsToKafka(): void
    {
        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;

        $this->consumer->shouldReceive('commit')
            ->once()
            ->with($message);

        $this->queue->commitOffset($message);
    }

    public function testHealthCheckReturnsConnectedStatus(): void
    {
        $metadata = Mockery::mock(Metadata::class);

        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->with(true, null, 5000)
            ->andReturn($metadata);

        $health = $this->queue->healthCheck();

        $this->assertTrue($health['connected']);
        $this->assertArrayHasKey('latency_ms', $health);
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
        $this->createQueueStatusTable();

        $this->queue->pause('test-queue');

        $this->assertTrue($this->queue->isPaused('test-queue'));
    }

    public function testResumeQueue(): void
    {
        $this->createQueueStatusTable();

        $this->queue->pause('test-queue');
        $this->assertTrue($this->queue->isPaused('test-queue'));

        $this->queue->resume('test-queue');
        $this->assertFalse($this->queue->isPaused('test-queue'));
    }

    public function testIsPausedReturnsFalseByDefault(): void
    {
        $this->createQueueStatusTable();

        $this->assertFalse($this->queue->isPaused('non-paused-queue'));
    }

    public function testHealthCheckReturnsFailedOnError(): void
    {
        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->andThrow(new Exception('Connection refused'));

        $health = $this->queue->healthCheck();

        $this->assertFalse($health['connected']);
        $this->assertSame('Connection refused', $health['message']);
    }

    public function testSizeReturnsZeroForKafka(): void
    {
        // Kafka doesn't have a way to get the exact queue size
        // The size method returns 0 for Kafka driver
        $size = $this->queue->size('default');

        $this->assertSame(0, $size);
    }

    public function testClearDeletesDelayedJobs(): void
    {
        $this->createQueueStatusTable();

        // Insert a delayed job
        DB::table('station_kafka_delayed_jobs')->insert([
            'id' => 'delayed-job-1',
            'queue' => 'test-queue',
            'payload' => '{}',
            'available_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);

        $deleted = $this->queue->clear('test-queue');

        $this->assertSame(1, $deleted);
        $this->assertSame(0, DB::table('station_kafka_delayed_jobs')->where('queue', 'test-queue')->count());
    }

    public function testPushRawThrowsExceptionOnFlushFailure(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR__TIMED_OUT);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to flush Kafka producer');

        $this->queue->pushRaw($payload, 'default');
    }

    public function testPushRawWithExplicitPartition(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once()
            ->with(3, 0, $payload, null);

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $id = $this->queue->pushRaw($payload, 'default', ['partition' => 3]);

        $this->assertSame('test-uuid', $id);
    }

    public function testPushRawGeneratesUuidWhenNotInPayload(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => []]);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $id = $this->queue->pushRaw($payload, 'default');

        // Should be a valid UUID
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function testPopReturnsNullWhenQueueIsPaused(): void
    {
        $this->createQueueStatusTable();

        $this->queue->pause('paused-queue');

        $job = $this->queue->pop('paused-queue');

        $this->assertNull($job);
    }

    public function testPopReturnsNullOnPartitionEof(): void
    {
        $this->createQueueStatusTable();

        $this->consumer->shouldReceive('subscribe')
            ->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR__PARTITION_EOF;

        $this->consumer->shouldReceive('consume')
            ->once()
            ->with(5000)
            ->andReturn($message);

        $job = $this->queue->pop('default');

        $this->assertNull($job);
    }

    public function testPopReturnsNullOnUnknownError(): void
    {
        $this->createQueueStatusTable();

        $this->consumer->shouldReceive('subscribe')
            ->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_UNKNOWN; // Some other error

        $this->consumer->shouldReceive('consume')
            ->once()
            ->andReturn($message);

        $job = $this->queue->pop('default');

        $this->assertNull($job);
    }

    public function testPopReturnsNullOnConsumeException(): void
    {
        $this->createQueueStatusTable();

        $this->consumer->shouldReceive('subscribe')
            ->once();

        $this->consumer->shouldReceive('consume')
            ->once()
            ->andThrow(new Exception('Consumer error'));

        $job = $this->queue->pop('default');

        $this->assertNull($job);
    }

    public function testCommitOffsetAsyncCommitsAsynchronously(): void
    {
        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;

        $this->consumer->shouldReceive('commitAsync')
            ->once()
            ->with($message);

        $this->queue->commitOffsetAsync($message);
    }

    public function testGetDeadLetterQueueReturnsEmptyArrayOnError(): void
    {
        $this->consumer->shouldReceive('subscribe')
            ->once()
            ->andThrow(new Exception('Topic does not exist'));

        $dlq = $this->queue->getDeadLetterQueue('default');

        $this->assertEmpty($dlq);
    }

    public function testRequeueFromDeadLetterReturnsFalseOnInvalidMessageId(): void
    {
        $result = $this->queue->requeueFromDeadLetter('default', 'invalid-id');

        $this->assertFalse($result);
    }

    public function testRequeueFromDeadLetterReturnsFalseOnTwoPartId(): void
    {
        $result = $this->queue->requeueFromDeadLetter('default', 'topic:partition');

        $this->assertFalse($result);
    }

    public function testGetConsumerLagReturnsEmptyOnError(): void
    {
        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->andThrow(new Exception('Connection error'));

        $lag = $this->queue->getConsumerLag('default');

        $this->assertEmpty($lag);
    }

    public function testSizeReturnsZeroOnError(): void
    {
        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->andThrow(new Exception('Connection error'));

        $size = $this->queue->size('default');

        $this->assertSame(0, $size);
    }

    public function testPauseUpdatesDatabase(): void
    {
        $this->createQueueStatusTable();

        // Pause should update database state
        $this->queue->pause('test-queue');

        $this->assertTrue($this->queue->isPaused('test-queue'));
    }

    public function testResumeUpdatesDatabase(): void
    {
        $this->createQueueStatusTable();

        $this->queue->pause('test-queue');

        // Resume should update database state
        $this->queue->resume('test-queue');

        $this->assertFalse($this->queue->isPaused('test-queue'));
    }

    public function testLaterRawStoresDelayedJob(): void
    {
        $this->createQueueStatusTable();

        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);

        $id = $this->queue->laterRaw(60, $payload, 'default');

        $this->assertSame('test-uuid', $id);

        $job = DB::table('station_kafka_delayed_jobs')->where('id', 'test-uuid')->first();
        $this->assertNotNull($job);
        $this->assertSame('default', $job->queue);
    }

    public function testLaterRawGeneratesUuidWhenNotInPayload(): void
    {
        $this->createQueueStatusTable();

        $payload = json_encode(['job' => 'TestJob']);

        $id = $this->queue->laterRaw(60, $payload, 'default');

        // Should be a valid UUID7
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );

        $job = DB::table('station_kafka_delayed_jobs')->where('id', $id)->first();
        $this->assertNotNull($job);
    }

    public function testClearDeletesDelayedJobsOnly(): void
    {
        $this->createQueueStatusTable();

        // Insert delayed jobs for different queues
        DB::table('station_kafka_delayed_jobs')->insert([
            'id' => 'job-1',
            'queue' => 'test-queue',
            'payload' => '{}',
            'available_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);
        DB::table('station_kafka_delayed_jobs')->insert([
            'id' => 'job-2',
            'queue' => 'other-queue',
            'payload' => '{}',
            'available_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);

        $deleted = $this->queue->clear('test-queue');

        $this->assertSame(1, $deleted);
        $this->assertSame(0, DB::table('station_kafka_delayed_jobs')->where('queue', 'test-queue')->count());
        $this->assertSame(1, DB::table('station_kafka_delayed_jobs')->where('queue', 'other-queue')->count());
    }

    public function testCommitOffsetDoesNothingWhenAutoCommitEnabled(): void
    {
        // Create connection with auto_commit true
        $config = [
            'brokers' => '127.0.0.1:9092',
            'queue' => 'default',
            'group_id' => 'station',
            'auto_commit' => true,
            'consume_timeout' => 5000,
            'flush_timeout' => 10000,
        ];

        $connection = new KafkaConnection($config);

        // Inject mocked consumer
        $reflection = new ReflectionClass($connection);
        $consumerProperty = $reflection->getProperty('consumer');

        $consumerProperty->setValue($connection, $this->consumer);

        $queue = new KafkaQueue($connection, 'default');
        $queue->setContainer($this->app);

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;

        // Consumer commit should NOT be called when auto_commit is true
        $this->consumer->shouldNotReceive('commit');

        $queue->commitOffset($message);
    }

    public function testCommitOffsetAsyncDoesNothingWhenAutoCommitEnabled(): void
    {
        // Create connection with auto_commit true
        $config = [
            'brokers' => '127.0.0.1:9092',
            'queue' => 'default',
            'group_id' => 'station',
            'auto_commit' => true,
            'consume_timeout' => 5000,
            'flush_timeout' => 10000,
        ];

        $connection = new KafkaConnection($config);

        // Inject mocked consumer
        $reflection = new ReflectionClass($connection);
        $consumerProperty = $reflection->getProperty('consumer');

        $consumerProperty->setValue($connection, $this->consumer);

        $queue = new KafkaQueue($connection, 'default');
        $queue->setContainer($this->app);

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;

        // Consumer commitAsync should NOT be called when auto_commit is true
        $this->consumer->shouldNotReceive('commitAsync');

        $queue->commitOffsetAsync($message);
    }

    public function testRequeueFromDeadLetterWithValidMessageId(): void
    {
        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;
        $message->payload = '{"job":"TestJob"}';

        $this->consumer->shouldReceive('assign')
            ->once();

        $this->consumer->shouldReceive('consume')
            ->once()
            ->andReturn($message);

        $this->consumer->shouldReceive('commit')
            ->once();

        $result = $this->queue->requeueFromDeadLetter('default', 'test.dlq:0:100');

        $this->assertTrue($result);
    }

    public function testRequeueFromDeadLetterReturnsFalseOnConsumeError(): void
    {
        $this->consumer->shouldReceive('assign')
            ->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR__TIMED_OUT;

        $this->consumer->shouldReceive('consume')
            ->once()
            ->andReturn($message);

        $result = $this->queue->requeueFromDeadLetter('default', 'test.dlq:0:100');

        $this->assertFalse($result);
    }

    public function testRequeueFromDeadLetterReturnsFalseOnException(): void
    {
        $this->consumer->shouldReceive('assign')
            ->once()
            ->andThrow(new Exception('Assign failed'));

        $result = $this->queue->requeueFromDeadLetter('default', 'test.dlq:0:100');

        $this->assertFalse($result);
    }

    public function testPopMigratesDelayedJobsThatAreReady(): void
    {
        $this->createQueueStatusTable();

        // Insert a delayed job that is ready
        $payload = json_encode(['uuid' => 'ready-job', 'job' => 'TestJob']);
        DB::table('station_kafka_delayed_jobs')->insert([
            'id' => 'ready-job',
            'queue' => 'default',
            'payload' => $payload,
            'available_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(5),
        ]);

        // Mock producer to receive the migrated job
        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $this->consumer->shouldReceive('subscribe')
            ->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR__TIMED_OUT;

        $this->consumer->shouldReceive('consume')
            ->once()
            ->andReturn($message);

        $job = $this->queue->pop('default');

        $this->assertNull($job); // No new job returned, but delayed job was migrated

        // Verify the delayed job was deleted from the table
        $this->assertSame(0, DB::table('station_kafka_delayed_jobs')->where('id', 'ready-job')->count());
    }

    public function testGetDriverInfoReturnsBasicInfoOnMetadataError(): void
    {
        // getDriverInfo calls size() first (which calls getMetadata),
        // then calls getMetadata again for its own info
        $this->consumer->shouldReceive('getMetadata')
            ->andThrow(new Exception('Metadata error'));

        $info = $this->queue->getDriverInfo('test-topic');

        $this->assertSame('kafka', $info['driver']);
        $this->assertArrayHasKey('size', $info);
        $this->assertSame(0, $info['size']);
        $this->assertArrayNotHasKey('brokers', $info);
    }

    public function testGetDriverInfoStructure(): void
    {
        // When metadata fails, the result should still have driver and size
        $this->consumer->shouldReceive('getMetadata')
            ->andThrow(new Exception('No brokers'));

        $info = $this->queue->getDriverInfo('my-topic');

        $this->assertIsArray($info);
        $this->assertSame('kafka', $info['driver']);
        $this->assertArrayHasKey('size', $info);
    }

    public function testGetDeadLetterQueueReturnsMessages(): void
    {
        $dlqMessage = new Message();
        $dlqMessage->err = RD_KAFKA_RESP_ERR_NO_ERROR;
        $dlqMessage->payload = '{"job":"FailedJob"}';
        $dlqMessage->topic_name = 'default.dlq';
        $dlqMessage->partition = 0;
        $dlqMessage->offset = 42;
        $dlqMessage->timestamp = 1700000000;

        $timeoutMessage = new Message();
        $timeoutMessage->err = RD_KAFKA_RESP_ERR__TIMED_OUT;

        $this->consumer->shouldReceive('subscribe')
            ->once()
            ->with(['default.dlq']);

        $this->consumer->shouldReceive('consume')
            ->twice()
            ->andReturn($dlqMessage, $timeoutMessage);

        $this->consumer->shouldReceive('unsubscribe')
            ->once();

        $result = $this->queue->getDeadLetterQueue('default', 10);

        $this->assertCount(1, $result);
        $this->assertSame('default.dlq:0:42', $result[0]['id']);
        $this->assertSame('{"job":"FailedJob"}', $result[0]['payload']);
        $this->assertSame(0, $result[0]['partition']);
        $this->assertSame(42, $result[0]['offset']);
        $this->assertSame(1700000000, $result[0]['timestamp']);
    }

    public function testGetDeadLetterQueueStopsAtLimit(): void
    {
        $dlqMessage = new Message();
        $dlqMessage->err = RD_KAFKA_RESP_ERR_NO_ERROR;
        $dlqMessage->payload = '{"job":"FailedJob"}';
        $dlqMessage->topic_name = 'default.dlq';
        $dlqMessage->partition = 0;
        $dlqMessage->offset = 1;
        $dlqMessage->timestamp = 1700000000;

        $this->consumer->shouldReceive('subscribe')->once();
        $this->consumer->shouldReceive('consume')
            ->times(2)
            ->andReturn($dlqMessage);
        $this->consumer->shouldReceive('unsubscribe')->once();

        $result = $this->queue->getDeadLetterQueue('default', 2);

        $this->assertCount(2, $result);
    }

    public function testSizeReturnsZeroForNonMatchingTopic(): void
    {
        $metadata = Mockery::mock(Metadata::class);
        $topicMetadata = Mockery::mock(Topic::class);
        $topicMetadata->shouldReceive('getTopic')->andReturn('other-topic');

        $metadata->shouldReceive('getTopics')
            ->andReturn($this->createCollection([$topicMetadata]));

        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->andReturn($metadata);

        $size = $this->queue->size('my-queue');

        $this->assertSame(0, $size);
    }

    public function testGetQueueMethodReturnsDefault(): void
    {
        $reflection = new ReflectionClass($this->queue);
        $method = $reflection->getMethod('getQueue');

        $this->assertSame('default', $method->invoke($this->queue, null));
        $this->assertSame('custom', $method->invoke($this->queue, 'custom'));
    }

    public function testSetConnectionNameReturnsQueue(): void
    {
        $result = $this->queue->setConnectionName('test');

        $this->assertSame($this->queue, $result);
    }

    public function testPopSkipsMigratingFailedDelayedJobs(): void
    {
        $this->createQueueStatusTable();

        // Insert a delayed job that is ready
        $payload = json_encode(['uuid' => 'ready-job', 'job' => 'TestJob']);
        DB::table('station_kafka_delayed_jobs')->insert([
            'id' => 'ready-job',
            'queue' => 'default',
            'payload' => $payload,
            'available_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(5),
        ]);

        // Mock producer to fail
        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andThrow(new Exception('Flush failed'));

        $this->consumer->shouldReceive('subscribe')
            ->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR__TIMED_OUT;

        $this->consumer->shouldReceive('consume')
            ->once()
            ->andReturn($message);

        $job = $this->queue->pop('default');

        $this->assertNull($job);

        // Delayed job should still exist because migration failed
        $this->assertSame(1, DB::table('station_kafka_delayed_jobs')->where('id', 'ready-job')->count());
    }

    // =========================================================================
    // Additional coverage: getDriverInfo with successful metadata
    // =========================================================================

    public function testGetDriverInfoReturnsFullInfoWithMatchingTopic(): void
    {
        $partition = Mockery::mock(Partition::class);
        $partition->shouldReceive('getId')->andReturn(0);

        $topicMetadata = Mockery::mock(Topic::class);
        $topicMetadata->shouldReceive('getTopic')->andReturn('my-topic');
        $topicMetadata->shouldReceive('getPartitions')->andReturn($this->createCollection([$partition]));

        $metadata = Mockery::mock(Metadata::class);
        $metadata->shouldReceive('getTopics')->andReturn($this->createCollection([$topicMetadata]));
        $metadata->shouldReceive('getBrokers')->andReturn($this->createCollection([1, 2, 3]));

        $this->consumer->shouldReceive('getMetadata')
            ->andReturn($metadata);

        // queryWatermarkOffsets is called twice: once from size() and once from getDriverInfo()
        $this->consumer->shouldReceive('queryWatermarkOffsets')
            ->andReturnUsing(static function ($topic, $partition, &$low, &$high): void {
                $low = 0;
                $high = 100;
            });

        $info = $this->queue->getDriverInfo('my-topic');

        $this->assertSame('kafka', $info['driver']);
        $this->assertSame(3, $info['brokers']);
        $this->assertSame(1, $info['partitions']);
        $this->assertSame(100, $info['total_lag']);
        $this->assertSame(100, $info['total_messages']);
        $this->assertArrayHasKey('consumer_lag', $info);
        $this->assertSame(100, $info['consumer_lag']['partition_0']);
    }

    public function testGetDriverInfoWithMultiplePartitions(): void
    {
        $partition0 = Mockery::mock(Partition::class);
        $partition0->shouldReceive('getId')->andReturn(0);

        $partition1 = Mockery::mock(Partition::class);
        $partition1->shouldReceive('getId')->andReturn(1);

        $topicMetadata = Mockery::mock(Topic::class);
        $topicMetadata->shouldReceive('getTopic')->andReturn('multi-topic');
        $topicMetadata->shouldReceive('getPartitions')->andReturn($this->createCollection([$partition0, $partition1]));

        $metadata = Mockery::mock(Metadata::class);
        $metadata->shouldReceive('getTopics')->andReturn($this->createCollection([$topicMetadata]));
        $metadata->shouldReceive('getBrokers')->andReturn($this->createCollection([1]));

        $this->consumer->shouldReceive('getMetadata')
            ->andReturn($metadata);

        // queryWatermarkOffsets called 4 times: 2 from size() + 2 from getDriverInfo()
        $this->consumer->shouldReceive('queryWatermarkOffsets')
            ->andReturnUsing(static function ($topic, $partition, &$low, &$high): void {
                if ($partition === 0) {
                    $low = 0;
                    $high = 50;
                } else {
                    $low = 10;
                    $high = 80;
                }
            });

        $info = $this->queue->getDriverInfo('multi-topic');

        $this->assertSame(2, $info['partitions']);
        $this->assertSame(120, $info['total_lag']); // (50-0) + (80-10)
        $this->assertSame(50, $info['consumer_lag']['partition_0']);
        $this->assertSame(70, $info['consumer_lag']['partition_1']);
    }

    public function testGetDriverInfoSkipsNonMatchingTopics(): void
    {
        $topicMetadata = Mockery::mock(Topic::class);
        $topicMetadata->shouldReceive('getTopic')->andReturn('other-topic');

        $metadata = Mockery::mock(Metadata::class);
        $metadata->shouldReceive('getTopics')->andReturn($this->createCollection([$topicMetadata]));
        $metadata->shouldReceive('getBrokers')->andReturn($this->createCollection([1]));

        $this->consumer->shouldReceive('getMetadata')
            ->andReturn($metadata);

        $info = $this->queue->getDriverInfo('my-topic');

        $this->assertSame('kafka', $info['driver']);
        $this->assertArrayHasKey('brokers', $info);
        $this->assertArrayNotHasKey('partitions', $info);
    }

    // =========================================================================
    // getConsumerLag with actual data
    // =========================================================================

    // Note: testGetConsumerLagReturnsLagPerPartition removed because getConsumerLag()
    // creates real TopicPartition objects via `new TopicPartition()` which causes
    // "zend_mm_heap corrupted" in rdkafka 6.0.5 when mixed with Mockery mocks.
    // Coverage for this method is provided by testGetConsumerLagReturnsEmptyOnError
    // and testGetConsumerLagSkipsNonMatchingTopics which test the error and non-matching paths.

    public function testGetConsumerLagSkipsNonMatchingTopics(): void
    {
        $topicMetadata = Mockery::mock(Topic::class);
        $topicMetadata->shouldReceive('getTopic')->andReturn('different-topic');

        $metadata = Mockery::mock(Metadata::class);
        $metadata->shouldReceive('getTopics')->andReturn($this->createCollection([$topicMetadata]));

        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->andReturn($metadata);

        $lag = $this->queue->getConsumerLag('my-topic');

        $this->assertEmpty($lag);
    }

    // Note: testGetConsumerLagClampsNegativeLagToZero removed for the same rdkafka
    // heap corruption reason as testGetConsumerLagReturnsLagPerPartition above.

    // =========================================================================
    // size() with matching topic and partitions
    // =========================================================================

    public function testSizeReturnsSumAcrossPartitions(): void
    {
        $partition0 = Mockery::mock(Partition::class);
        $partition0->shouldReceive('getId')->andReturn(0);

        $partition1 = Mockery::mock(Partition::class);
        $partition1->shouldReceive('getId')->andReturn(1);

        $topicMetadata = Mockery::mock(Topic::class);
        $topicMetadata->shouldReceive('getTopic')->andReturn('sized-topic');
        $topicMetadata->shouldReceive('getPartitions')->andReturn($this->createCollection([$partition0, $partition1]));

        $metadata = Mockery::mock(Metadata::class);
        $metadata->shouldReceive('getTopics')->andReturn($this->createCollection([$topicMetadata]));

        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->andReturn($metadata);

        $this->consumer->shouldReceive('queryWatermarkOffsets')
            ->twice()
            ->andReturnUsing(static function ($topic, $partition, &$low, &$high): void {
                if ($partition === 0) {
                    $low = 10;
                    $high = 50;
                } else {
                    $low = 20;
                    $high = 80;
                }
            });

        $size = $this->queue->size('sized-topic');

        $this->assertSame(100, $size); // (50-10) + (80-20)
    }

    public function testSizeUsesDefaultQueueWhenNullProvided(): void
    {
        $topicMetadata = Mockery::mock(Topic::class);
        $topicMetadata->shouldReceive('getTopic')->andReturn('default');
        $topicMetadata->shouldReceive('getPartitions')->andReturn($this->createCollection([]));

        $metadata = Mockery::mock(Metadata::class);
        $metadata->shouldReceive('getTopics')->andReturn($this->createCollection([$topicMetadata]));

        $this->consumer->shouldReceive('getMetadata')
            ->once()
            ->andReturn($metadata);

        $size = $this->queue->size();

        $this->assertSame(0, $size);
    }

    // =========================================================================
    // Pause with custom connection name
    // =========================================================================

    public function testPauseUsesConnectionNameInDbQuery(): void
    {
        $this->createQueueStatusTable();

        $this->queue->setConnectionName('my-kafka');
        $this->queue->pause('my-topic');

        $record = DB::table('station_queue_status')
            ->where('queue', 'my-topic')
            ->where('connection', 'my-kafka')
            ->first();

        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->paused);
    }

    public function testResumeUsesConnectionNameInDbQuery(): void
    {
        $this->createQueueStatusTable();

        $this->queue->setConnectionName('my-kafka');
        $this->queue->pause('my-topic');
        $this->queue->resume('my-topic');

        $record = DB::table('station_queue_status')
            ->where('queue', 'my-topic')
            ->where('connection', 'my-kafka')
            ->first();

        $this->assertNotNull($record);
        $this->assertFalse((bool) $record->paused);
    }

    public function testPauseDefaultConnectionNameIsKafka(): void
    {
        $this->createQueueStatusTable();

        // Without setConnectionName(), the fallback is 'kafka'
        $config = [
            'brokers' => '127.0.0.1:9092',
            'queue' => 'default',
            'group_id' => 'station',
            'auto_commit' => false,
            'consume_timeout' => 5000,
            'flush_timeout' => 10000,
        ];
        $conn = new KafkaConnection($config);
        $reflection = new ReflectionClass($conn);
        $consumerProperty = $reflection->getProperty('consumer');

        $consumerProperty->setValue($conn, $this->consumer);

        $freshQueue = new KafkaQueue($conn, 'default');
        $freshQueue->setContainer($this->app);

        $freshQueue->pause('q1');

        $record = DB::table('station_queue_status')
            ->where('queue', 'q1')
            ->where('connection', 'kafka')
            ->first();

        $this->assertNotNull($record);
    }

    // =========================================================================
    // pop() not migrating future delayed jobs
    // =========================================================================

    public function testPopDoesNotMigrateFutureDelayedJobs(): void
    {
        $this->createQueueStatusTable();

        // Insert a delayed job NOT yet available
        $payload = json_encode(['uuid' => 'future-job', 'job' => 'TestJob']);
        DB::table('station_kafka_delayed_jobs')->insert([
            'id' => 'future-job',
            'queue' => 'default',
            'payload' => $payload,
            'available_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        $this->consumer->shouldReceive('subscribe')->once();

        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR__TIMED_OUT;

        $this->consumer->shouldReceive('consume')
            ->once()
            ->andReturn($message);

        $job = $this->queue->pop('default');

        $this->assertNull($job);

        // Future job should still be in the table
        $this->assertSame(1, DB::table('station_kafka_delayed_jobs')->where('id', 'future-job')->count());
    }

    // =========================================================================
    // pushRaw to non-default queue
    // =========================================================================

    public function testPushRawUsesCorrectTopicName(): void
    {
        $payload = json_encode(['uuid' => 'id-1', 'job' => 'TestJob']);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once()
            ->with(RD_KAFKA_PARTITION_UA, 0, $payload, null);

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->with('custom-topic')
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $id = $this->queue->pushRaw($payload, 'custom-topic');

        $this->assertSame('id-1', $id);
    }

    public function testPushRawUsesDefaultQueueWhenNullProvided(): void
    {
        $payload = json_encode(['uuid' => 'id-2', 'job' => 'TestJob']);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->with('default')
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $id = $this->queue->pushRaw($payload);

        $this->assertSame('id-2', $id);
    }

    // =========================================================================
    // laterRaw uses default queue
    // =========================================================================

    public function testLaterRawUsesDefaultQueueWhenNullProvided(): void
    {
        $this->createQueueStatusTable();

        $payload = json_encode(['uuid' => 'later-null', 'job' => 'TestJob']);

        $id = $this->queue->laterRaw(30, $payload);

        $this->assertSame('later-null', $id);

        $job = DB::table('station_kafka_delayed_jobs')->where('id', 'later-null')->first();
        $this->assertNotNull($job);
        $this->assertSame('default', $job->queue);
    }

    // =========================================================================
    // clear with no delayed jobs returns zero
    // =========================================================================

    public function testClearReturnsZeroWhenNoDelayedJobs(): void
    {
        $this->createQueueStatusTable();

        $deleted = $this->queue->clear('empty-queue');

        $this->assertSame(0, $deleted);
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

        // Kafka driver uses this table for delayed job handling
        DB::statement('CREATE TABLE IF NOT EXISTS station_kafka_delayed_jobs (
            id VARCHAR(255) PRIMARY KEY,
            queue VARCHAR(255) NOT NULL,
            payload TEXT NOT NULL,
            available_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP NULL
        )');
    }

    /**
     * Create a mock RdKafka\Metadata\Collection that iterates over the given items.
     *
     * The rdkafka extension enforces Collection return types on Metadata methods,
     * so we cannot return plain arrays from mocks.
     *
     * @param array<mixed> $items
     * @return MockInterface&Collection
     */
    private function createCollection(array $items): MockInterface
    {
        $collection = Mockery::mock(Collection::class);
        $index = 0;
        $iterationState = ['index' => 0];

        $collection->shouldReceive('rewind')->andReturnUsing(static function () use (&$iterationState): void {
            $iterationState['index'] = 0;
        });
        $collection->shouldReceive('valid')->andReturnUsing(static function () use (&$iterationState, $items) {
            return $iterationState['index'] < \count($items);
        });
        $collection->shouldReceive('current')->andReturnUsing(static function () use (&$iterationState, $items) {
            return $items[$iterationState['index']] ?? null;
        });
        $collection->shouldReceive('key')->andReturnUsing(static function () use (&$iterationState) {
            return $iterationState['index'];
        });
        $collection->shouldReceive('next')->andReturnUsing(static function () use (&$iterationState): void {
            $iterationState['index']++;
        });
        $collection->shouldReceive('count')->andReturn(\count($items));

        return $collection;
    }
}
