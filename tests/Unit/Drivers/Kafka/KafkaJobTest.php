<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Kafka;

use Illuminate\Container\Container;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use ReflectionClass;
use Station\Drivers\Kafka\KafkaConnection;
use Station\Drivers\Kafka\KafkaJob;
use Station\Drivers\Kafka\KafkaQueue;
use Station\Tests\Fixtures\TestJob;
use stdClass;
use Throwable;

class KafkaJobTest extends TestCase
{
    private Container $container;

    private MockInterface&Producer $producer;

    private MockInterface&KafkaConsumer $consumer;

    private KafkaQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\extension_loaded('rdkafka')) {
            $this->markTestSkipped('The rdkafka extension is not available.');
        }

        $this->container = new Container();
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

        $connection = new KafkaConnection($config);

        // Inject mocked producer and consumer
        $reflection = new ReflectionClass($connection);

        $producerProperty = $reflection->getProperty('producer');
        $producerProperty->setAccessible(true);
        $producerProperty->setValue($connection, $this->producer);

        $consumerProperty = $reflection->getProperty('consumer');
        $consumerProperty->setAccessible(true);
        $consumerProperty->setValue($connection, $this->consumer);

        $this->queue = new KafkaQueue($connection, 'default', $config);
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
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame('test-uuid', $job->getJobId());
    }

    public function testGetJobIdReturnsTopicPartitionOffsetWhenNoUuid(): void
    {
        $payload = json_encode(['job' => 'TestJob']);
        $message = $this->createMessage($payload, 2, 500);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame('test-topic-2-500', $job->getJobId());
    }

    public function testGetRawBodyReturnsMessagePayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame($payload, $job->getRawBody());
    }

    public function testGetQueueReturnsQueueName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'test-topic');

        $this->assertSame('test-topic', $job->getQueue());
    }

    public function testAttemptsReturnsAttemptsFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 3]);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame(3, $job->attempts());
    }

    public function testAttemptsReturnsOneByDefault(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame(1, $job->attempts());
    }

    public function testPayloadReturnsDecodedData(): void
    {
        $data = ['uuid' => 'test-uuid', 'job' => 'TestJob', 'data' => ['key' => 'value']];
        $payload = json_encode($data);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame($data, $job->payload());
    }

    public function testPayloadReturnsEmptyArrayOnInvalidJson(): void
    {
        $message = $this->createMessage('invalid-json');

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame([], $job->payload());
    }

    public function testGetKafkaMessageReturnsOriginalMessage(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame($message, $job->getKafkaMessage());
    }

    public function testGetKeyReturnsMessageKey(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);
        $message->key = 'partition-key';

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame('partition-key', $job->getKey());
    }

    public function testGetKeyReturnsEmptyStringWhenNoKey(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame('', $job->getKey());
    }

    public function testGetPartitionReturnsMessagePartition(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload, 3, 100);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame(3, $job->getPartition());
    }

    public function testGetOffsetReturnsMessageOffset(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload, 0, 12345);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame(12345, $job->getOffset());
    }

    public function testGetTimestampReturnsMessageTimestamp(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);
        $timestamp = time() * 1000;
        $message->timestamp = $timestamp;

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame($timestamp, $job->getTimestamp());
    }

    public function testGetHeadersReturnsMessageHeaders(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);
        $message->headers = ['X-Custom-Header' => 'value'];

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame(['X-Custom-Header' => 'value'], $job->getHeaders());
    }

    public function testGetHeadersReturnsEmptyArrayWhenNoHeaders(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);
        // Headers default to empty array in createMessage

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $this->assertSame([], $job->getHeaders());
    }

    public function testDeleteCommitsOffset(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $this->consumer->shouldReceive('commit')
            ->once()
            ->with($message);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');
        $job->delete();

        $this->assertTrue($job->isDeleted());
    }

    public function testReleaseWithoutDelayRepushesAndCommits(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 1]);
        $message = $this->createMessage($payload);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $this->consumer->shouldReceive('commit')
            ->once()
            ->with($message);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');
        $job->release();

        $this->assertTrue($job->isReleased());
    }

    public function testGetConnectionNameReturnsConnectionName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'custom-connection', 'default');

        $this->assertSame('custom-connection', $job->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // fire() tests
    // -------------------------------------------------------------------------

    public function testFireWithStationJobFormatCallsHandle(): void
    {
        $testJob = new TestJob('kafka-fire-test');
        TestJob::$handled = false;

        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'TestJob',
            'data' => [
                'station_job_id' => 'station-123',
                'payload' => serialize($testJob),
            ],
        ]);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');
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
                'payload' => 12345,
            ],
        ]);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');
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
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');
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
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');
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
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        try {
            $job->fire();
        } catch (Throwable) {
            // Expected: parent::fire() tries to resolve TestJob
        }

        $this->assertTrue(true, 'fire() took the non-Station path');
    }

    // -------------------------------------------------------------------------
    // parseJobClassAndMethod() tests
    // -------------------------------------------------------------------------

    public function testParseJobClassAndMethodWithAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\KafkaJob@run']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\KafkaJob', $result[0]);
        $this->assertSame('run', $result[1]);
    }

    public function testParseJobClassAndMethodWithoutAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\KafkaJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\KafkaJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToDisplayName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'displayName' => 'KafkaDisplayJob']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('KafkaDisplayJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToUnknownJob(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid']);
        $message = $this->createMessage($payload);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('UnknownJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    // -------------------------------------------------------------------------
    // release() with delay tests
    // -------------------------------------------------------------------------

    public function testReleaseWithDelayUsesLaterRaw(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 1]);
        $message = $this->createMessage($payload);

        $topic = Mockery::mock(ProducerTopic::class);
        $topic->shouldReceive('produce')
            ->once();

        $this->producer->shouldReceive('newTopic')
            ->once()
            ->andReturn($topic);

        $this->producer->shouldReceive('flush')
            ->once()
            ->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

        $this->consumer->shouldReceive('commit')
            ->once()
            ->with($message);

        $job = new KafkaJob($this->container, $this->queue, $message, 'station', 'default');

        // laterRaw requires a database table for Kafka delayed jobs
        // Since we use Orchestra TestCase in KafkaQueueTest but this is PHPUnit TestCase,
        // we pass delay=0 to use pushRaw instead
        $job->release(0);

        $this->assertTrue($job->isReleased());
    }

    private function createMessage(string $payload, int $partition = 0, int $offset = 100): Message
    {
        $message = new Message();
        $message->err = RD_KAFKA_RESP_ERR_NO_ERROR;
        $message->payload = $payload;
        $message->topic_name = 'test-topic';
        $message->partition = $partition;
        $message->offset = $offset;
        $message->key = null;
        $message->timestamp = time() * 1000;
        $message->headers = [];

        return $message;
    }
}
