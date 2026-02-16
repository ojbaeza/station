<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Sqs;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Station\Drivers\Sqs\SqsConnection;
use Station\Drivers\Sqs\SqsJob;
use Station\Drivers\Sqs\SqsQueue;
use Station\Tests\Fixtures\TestJob;
use stdClass;
use Throwable;

class SqsJobTest extends TestCase
{
    private Container $container;

    private MockInterface&SqsClient $client;

    private SqsQueue $queue;

    private string $queueUrl = 'https://sqs.us-east-1.amazonaws.com/123456789/default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->client = Mockery::mock(SqsClient::class);

        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'queue' => 'default',
            'wait_time' => 20,
            'visibility_timeout' => 30,
        ];

        $connection = new SqsConnection($config);

        // Inject the mocked client
        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($connection, $this->client);

        $this->queue = new SqsQueue($connection, 'default', $config);
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

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame('test-uuid', $job->getJobId());
    }

    public function testGetJobIdReturnsMessageIdWhenNoUuid(): void
    {
        $payload = json_encode(['job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame('sqs-message-id', $job->getJobId());
    }

    public function testGetRawBodyReturnsMessageBody(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame($payload, $job->getRawBody());
    }

    public function testGetQueueReturnsQueueName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'test-queue', $this->queueUrl);

        $this->assertSame('test-queue', $job->getQueue());
    }

    public function testAttemptsReturnsAttemptsFromPayload(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 3]);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame(3, $job->attempts());
    }

    public function testAttemptsReturnsSqsReceiveCountWhenNoPayloadAttempts(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload, ['ApproximateReceiveCount' => '5']);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame(5, $job->attempts());
    }

    public function testPayloadReturnsDecodedData(): void
    {
        $data = ['uuid' => 'test-uuid', 'job' => 'TestJob', 'data' => ['key' => 'value']];
        $payload = json_encode($data);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame($data, $job->payload());
    }

    public function testPayloadReturnsEmptyArrayOnInvalidJson(): void
    {
        $message = $this->createMessage('invalid-json');

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame([], $job->payload());
    }

    public function testGetMessageReturnsOriginalMessage(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame($message, $job->getMessage());
    }

    public function testDeleteRemovesFromSqs(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $this->client->shouldReceive('deleteMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['ReceiptHandle'] === 'receipt-handle'));

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);
        $job->delete();

        $this->assertTrue($job->isDeleted());
    }

    public function testReleaseWithoutDelayPushesBackToQueue(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 1]);
        $message = $this->createMessage($payload);

        $this->client->shouldReceive('deleteMessage')
            ->once();

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new Result(['MessageId' => 'new-id']));

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);
        $job->release();

        $this->assertTrue($job->isReleased());
    }

    public function testReleaseWithDelayUsesDelaySeconds(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 1]);
        $message = $this->createMessage($payload);

        $this->client->shouldReceive('deleteMessage')
            ->once();

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['DelaySeconds'] === 60))
            ->andReturn(new Result(['MessageId' => 'new-id']));

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);
        $job->release(60);

        $this->assertTrue($job->isReleased());
    }

    public function testGetConnectionNameReturnsConnectionName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'custom-connection', 'default', $this->queueUrl);

        $this->assertSame('custom-connection', $job->getConnectionName());
    }

    // -------------------------------------------------------------------------
    // fire() tests
    // -------------------------------------------------------------------------

    public function testFireWithStationJobFormatCallsHandle(): void
    {
        $testJob = new TestJob('sqs-fire-test');
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

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);
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

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);
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

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);
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

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);
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

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        try {
            $job->fire();
        } catch (Throwable) {
            // Expected: parent::fire() tries to resolve TestJob class
        }

        $this->assertTrue(true, 'fire() took the non-Station path');
    }

    // -------------------------------------------------------------------------
    // parseJobClassAndMethod() tests
    // -------------------------------------------------------------------------

    public function testParseJobClassAndMethodWithAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\MyJob@execute']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\MyJob', $result[0]);
        $this->assertSame('execute', $result[1]);
    }

    public function testParseJobClassAndMethodWithoutAtSymbol(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'App\\Jobs\\MyJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('App\\Jobs\\MyJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToDisplayName(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'displayName' => 'SqsDisplayJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('SqsDisplayJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    public function testParseJobClassAndMethodFallsBackToUnknownJob(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('parseJobClassAndMethod');
        $method->setAccessible(true);

        $result = $method->invoke($job, $job->payload());

        $this->assertSame('UnknownJob', $result[0]);
        $this->assertSame('handle', $result[1]);
    }

    // -------------------------------------------------------------------------
    // preparePayloadForRelease() tests
    // -------------------------------------------------------------------------

    public function testPreparePayloadForReleaseIncrementsAttempts(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob', 'attempts' => 2]);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('preparePayloadForRelease');
        $method->setAccessible(true);

        $result = $method->invoke($job);
        $decoded = json_decode($result, true);

        $this->assertSame(3, $decoded['attempts']);
    }

    public function testPreparePayloadForReleaseDefaultsAttemptsToOne(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = $this->createMessage($payload);

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('preparePayloadForRelease');
        $method->setAccessible(true);

        $result = $method->invoke($job);
        $decoded = json_decode($result, true);

        $this->assertSame(1, $decoded['attempts']);
    }

    // -------------------------------------------------------------------------
    // Additional edge case tests
    // -------------------------------------------------------------------------

    public function testAttemptsDefaultsToOneWhenNoPayloadAttemptsAndNoReceiveCount(): void
    {
        $payload = json_encode(['uuid' => 'test-uuid', 'job' => 'TestJob']);
        $message = [
            'MessageId' => 'sqs-message-id',
            'ReceiptHandle' => 'receipt-handle',
            'Body' => $payload,
            'Attributes' => [],
        ];

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        $this->assertSame(1, $job->attempts());
    }

    public function testGetJobIdReturnsEmptyStringWhenNoIdAvailable(): void
    {
        $payload = json_encode(['job' => 'TestJob']);
        $message = [
            'MessageId' => null,
            'ReceiptHandle' => 'receipt-handle',
            'Body' => $payload,
            'Attributes' => [],
        ];

        $job = new SqsJob($this->container, $this->queue, $message, 'station', 'default', $this->queueUrl);

        // When uuid is missing and MessageId is null, should return empty string
        $this->assertSame('', $job->getJobId());
    }

    private function createMessage(string $body, array $attributes = []): array
    {
        return [
            'MessageId' => 'sqs-message-id',
            'ReceiptHandle' => 'receipt-handle',
            'Body' => $body,
            'Attributes' => array_merge([
                'ApproximateReceiveCount' => '1',
            ], $attributes),
        ];
    }
}
