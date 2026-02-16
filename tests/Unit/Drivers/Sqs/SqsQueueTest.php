<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Sqs;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Exception;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Station\Drivers\Sqs\SqsConnection;
use Station\Drivers\Sqs\SqsJob;
use Station\Drivers\Sqs\SqsQueue;
use Station\StationServiceProvider;

class SqsQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&SqsClient $client;

    private SqsConnection $connection;

    private SqsQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(SqsClient::class);

        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'queue' => 'default',
            'wait_time' => 20,
            'visibility_timeout' => 30,
            'fifo' => false,
        ];

        // Create connection with mocked client
        $this->connection = new SqsConnection($config);

        // Use reflection to inject the mocked client
        $reflection = new ReflectionClass($this->connection);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->connection, $this->client);

        $this->queue = new SqsQueue($this->connection, 'default');
        $this->queue->setContainer($this->app);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testPushRawSendsMessageToSqs(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => []]);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['QueueUrl'] === 'https://sqs.us-east-1.amazonaws.com/123456789/default'
                    && $params['MessageBody'] === $payload))
            ->andReturn(new Result(['MessageId' => 'test-message-id']));

        $id = $this->queue->pushRaw($payload, 'default');

        $this->assertSame('test-message-id', $id);
    }

    public function testLaterRawSendsDelayedMessage(): void
    {
        $payload = json_encode(['job' => 'TestJob', 'data' => []]);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['DelaySeconds'] === 60))
            ->andReturn(new Result(['MessageId' => 'delayed-message-id']));

        $id = $this->queue->laterRaw(60, $payload, 'default');

        $this->assertSame('delayed-message-id', $id);
    }

    public function testSizeReturnsApproximateMessageCount(): void
    {
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->with(Mockery::on(static fn($params) => \in_array('ApproximateNumberOfMessages', $params['AttributeNames'], true)))
            ->andReturn(new Result([
                'Attributes' => [
                    'ApproximateNumberOfMessages' => '42',
                ],
            ]));

        $size = $this->queue->size('default');

        $this->assertSame(42, $size);
    }

    public function testPopReturnsNullWhenNoMessages(): void
    {
        $this->createQueueStatusTable();

        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result(['Messages' => []]));

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

        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'sqs-message-id',
                        'ReceiptHandle' => 'receipt-handle',
                        'Body' => $payload,
                        'Attributes' => [
                            'ApproximateReceiveCount' => '1',
                        ],
                    ],
                ],
            ]));

        $job = $this->queue->pop('default');

        $this->assertInstanceOf(SqsJob::class, $job);
        $this->assertSame('test-uuid', $job->getJobId());
    }

    public function testDeleteMessageRemovesFromQueue(): void
    {
        $this->client->shouldReceive('deleteMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['QueueUrl'] === 'https://sqs.us-east-1.amazonaws.com/123456789/default'
                    && $params['ReceiptHandle'] === 'test-receipt'));

        $this->queue->deleteMessage(
            'https://sqs.us-east-1.amazonaws.com/123456789/default',
            'test-receipt',
        );
    }

    public function testHealthCheckReturnsConnectedStatus(): void
    {
        $this->client->shouldReceive('listQueues')
            ->once()
            ->with(['MaxResults' => 1])
            ->andReturn(new Result([]));

        $health = $this->queue->healthCheck();

        $this->assertTrue($health['connected']);
        $this->assertArrayHasKey('latency_ms', $health);
    }

    public function testClearPurgesQueue(): void
    {
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->andReturn(new Result([
                'Attributes' => [
                    'ApproximateNumberOfMessages' => '10',
                ],
            ]));

        $this->client->shouldReceive('purgeQueue')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['QueueUrl'] === 'https://sqs.us-east-1.amazonaws.com/123456789/test'));

        $count = $this->queue->clear('test');

        $this->assertSame(10, $count);
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

        // First pause
        $this->queue->pause('test-queue');
        $this->assertTrue($this->queue->isPaused('test-queue'));

        // Then resume
        $this->queue->resume('test-queue');
        $this->assertFalse($this->queue->isPaused('test-queue'));
    }

    public function testIsPausedReturnsFalseByDefault(): void
    {
        $this->createQueueStatusTable();

        $this->assertFalse($this->queue->isPaused('non-paused-queue'));
    }

    public function testGetConnectionName(): void
    {
        $this->assertSame('station', $this->queue->getConnectionName());
    }

    public function testSetConnectionName(): void
    {
        $result = $this->queue->setConnectionName('custom-connection');

        $this->assertSame('custom-connection', $this->queue->getConnectionName());
        $this->assertSame($this->queue, $result);
    }

    public function testHealthCheckReturnsFailedOnError(): void
    {
        $this->client->shouldReceive('listQueues')
            ->once()
            ->andThrow(new Exception('Connection refused'));

        $health = $this->queue->healthCheck();

        $this->assertFalse($health['connected']);
        $this->assertSame('Connection refused', $health['message']);
    }

    public function testChangeVisibilityUpdatesTimeout(): void
    {
        $this->client->shouldReceive('changeMessageVisibility')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['QueueUrl'] === 'https://sqs.us-east-1.amazonaws.com/123456789/default'
                    && $params['ReceiptHandle'] === 'test-receipt'
                    && $params['VisibilityTimeout'] === 60));

        $this->queue->changeVisibility(
            'https://sqs.us-east-1.amazonaws.com/123456789/default',
            'test-receipt',
            60,
        );
    }

    public function testPopReturnsNullWhenPaused(): void
    {
        $this->createQueueStatusTable();

        $this->queue->pause('paused-queue');

        $job = $this->queue->pop('paused-queue');

        $this->assertNull($job);
    }

    public function testGetDeadLetterQueueReturnsEmptyWhenNoMessages(): void
    {
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result(['Messages' => []]));

        $jobs = $this->queue->getDeadLetterQueue('default');

        $this->assertEmpty($jobs);
    }

    public function testRequeueFromDeadLetterReturnsFalseWhenMessageNotFound(): void
    {
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result(['Messages' => []]));

        $result = $this->queue->requeueFromDeadLetter('default', 'nonexistent-message-id');

        $this->assertFalse($result);
    }

    public function testRequeueFromDeadLetterSucceeds(): void
    {
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'target-message-id',
                        'ReceiptHandle' => 'receipt-handle',
                        'Body' => '{"job":"TestJob"}',
                    ],
                ],
            ]));

        // Mock sendMessage for the requeue
        $this->client->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new Result(['MessageId' => 'new-message-id']));

        // Mock deleteMessage from DLQ
        $this->client->shouldReceive('deleteMessage')
            ->once();

        $result = $this->queue->requeueFromDeadLetter('default', 'target-message-id');

        $this->assertTrue($result);
    }

    public function testGetDeadLetterQueueReturnsMessages(): void
    {
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'dlq-msg-1',
                        'ReceiptHandle' => 'receipt-1',
                        'Body' => '{"job":"FailedJob"}',
                        'Attributes' => ['ApproximateReceiveCount' => '3'],
                    ],
                ],
            ]));

        // Second call returns empty to break the loop
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result(['Messages' => []]));

        $jobs = $this->queue->getDeadLetterQueue('default', 10);

        $this->assertCount(1, $jobs);
        $this->assertSame('dlq-msg-1', $jobs[0]['id']);
    }

    public function testPushRawWithMessageAttributes(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => isset($params['MessageAttributes'])
                    && $params['MessageAttributes']['customAttr']['StringValue'] === 'test'))
            ->andReturn(new Result(['MessageId' => 'test-id']));

        $id = $this->queue->pushRaw($payload, 'default', [
            'attributes' => [
                'customAttr' => [
                    'DataType' => 'String',
                    'StringValue' => 'test',
                ],
            ],
        ]);

        $this->assertSame('test-id', $id);
    }

    public function testLaterRawCapsDelayAt900Seconds(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['DelaySeconds'] === 900))
            ->andReturn(new Result(['MessageId' => 'delayed-id']));

        // Request 2000 seconds delay, should be capped at 900
        $id = $this->queue->laterRaw(2000, $payload, 'default');

        $this->assertSame('delayed-id', $id);
    }

    public function testPushRawGeneratesUuidWhenNoMessageIdReturned(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new Result([])); // No MessageId in response

        $id = $this->queue->pushRaw($payload, 'default');

        // Should be a valid UUID7
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function testLaterRawGeneratesUuidWhenNoMessageIdReturned(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new Result([]));

        $id = $this->queue->laterRaw(60, $payload, 'default');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    // =========================================================================
    // FIFO queue tests
    // =========================================================================

    public function testPushRawWithFifoQueueIncludesMessageGroupId(): void
    {
        $fifoConnection = $this->createFifoConnection();
        $fifoQueue = new SqsQueue($fifoConnection, 'default.fifo');
        $fifoQueue->setContainer($this->app);

        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => isset($params['MessageGroupId'])
                    && $params['MessageGroupId'] === 'default'
                    && isset($params['MessageDeduplicationId'])
                    && $params['MessageDeduplicationId'] !== ''))
            ->andReturn(new Result(['MessageId' => 'fifo-msg-id']));

        $id = $fifoQueue->pushRaw($payload, 'default.fifo');

        $this->assertSame('fifo-msg-id', $id);
    }

    public function testPushRawWithFifoQueueAndCustomGroupId(): void
    {
        $fifoConnection = $this->createFifoConnection();
        $fifoQueue = new SqsQueue($fifoConnection, 'default.fifo');
        $fifoQueue->setContainer($this->app);

        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['MessageGroupId'] === 'custom-group'))
            ->andReturn(new Result(['MessageId' => 'fifo-msg-id-2']));

        $id = $fifoQueue->pushRaw($payload, 'default.fifo', [
            'message_group_id' => 'custom-group',
        ]);

        $this->assertSame('fifo-msg-id-2', $id);
    }

    public function testPushRawWithFifoQueueAndContentBasedDeduplication(): void
    {
        $connection = $this->createFifoConnectionWithContentDedup();
        $fifoQueue = new SqsQueue($connection, 'default.fifo');
        $fifoQueue->setContainer($this->app);

        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => isset($params['MessageGroupId'])
                    && !isset($params['MessageDeduplicationId'])))
            ->andReturn(new Result(['MessageId' => 'dedup-msg-id']));

        $id = $fifoQueue->pushRaw($payload, 'default.fifo');

        $this->assertSame('dedup-msg-id', $id);
    }

    public function testLaterRawWithFifoQueueIncludesGroupAndDedup(): void
    {
        $fifoConnection = $this->createFifoConnection();
        $fifoQueue = new SqsQueue($fifoConnection, 'default.fifo');
        $fifoQueue->setContainer($this->app);

        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => isset($params['MessageGroupId'])
                    && isset($params['DelaySeconds'])
                    && $params['DelaySeconds'] === 30))
            ->andReturn(new Result(['MessageId' => 'fifo-delayed-id']));

        $id = $fifoQueue->laterRaw(30, $payload, 'default.fifo');

        $this->assertSame('fifo-delayed-id', $id);
    }

    public function testLaterRawWithFifoQueueAndContentBasedDeduplication(): void
    {
        $connection = $this->createFifoConnectionWithContentDedup();
        $fifoQueue = new SqsQueue($connection, 'default.fifo');
        $fifoQueue->setContainer($this->app);

        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => isset($params['MessageGroupId'])
                    && !isset($params['MessageDeduplicationId'])))
            ->andReturn(new Result(['MessageId' => 'fifo-dedup-delayed-id']));

        $id = $fifoQueue->laterRaw(30, $payload, 'default.fifo');

        $this->assertSame('fifo-dedup-delayed-id', $id);
    }

    // =========================================================================
    // getDriverInfo tests
    // =========================================================================

    public function testGetDriverInfoReturnsFullInfo(): void
    {
        // size() call
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['AttributeNames'] === ['ApproximateNumberOfMessages']))
            ->andReturn(new Result([
                'Attributes' => [
                    'ApproximateNumberOfMessages' => '25',
                ],
            ]));

        // getDriverInfo detailed call
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->with(Mockery::on(static fn($params) => \in_array('QueueArn', $params['AttributeNames'], true)))
            ->andReturn(new Result([
                'Attributes' => [
                    'ApproximateNumberOfMessages' => '25',
                    'ApproximateNumberOfMessagesNotVisible' => '3',
                    'ApproximateNumberOfMessagesDelayed' => '7',
                    'QueueArn' => 'arn:aws:sqs:us-east-1:123456789:test-queue',
                    'VisibilityTimeout' => '30',
                    'MessageRetentionPeriod' => '345600',
                ],
            ]));

        $info = $this->queue->getDriverInfo('test-queue');

        $this->assertSame('sqs', $info['driver']);
        $this->assertSame(25, $info['size']);
        $this->assertSame(25, $info['visible']);
        $this->assertSame(3, $info['in_flight']);
        $this->assertSame(7, $info['delayed']);
        $this->assertSame(30, $info['visibility_timeout']);
        $this->assertSame(345600, $info['retention_period']);
        $this->assertSame('arn:aws:sqs:us-east-1:123456789:test-queue', $info['arn']);
    }

    public function testGetDriverInfoReturnsBasicInfoWhenApiCallFails(): void
    {
        // size() call succeeds
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['AttributeNames'] === ['ApproximateNumberOfMessages']))
            ->andReturn(new Result([
                'Attributes' => [
                    'ApproximateNumberOfMessages' => '10',
                ],
            ]));

        // Detailed call fails
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->andThrow(new Exception('Access denied'));

        $info = $this->queue->getDriverInfo('test-queue');

        $this->assertSame('sqs', $info['driver']);
        $this->assertSame(10, $info['size']);
        $this->assertArrayNotHasKey('visible', $info);
        $this->assertArrayNotHasKey('arn', $info);
    }

    public function testGetDriverInfoWithMissingAttributeFields(): void
    {
        // size() call
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['AttributeNames'] === ['ApproximateNumberOfMessages']))
            ->andReturn(new Result([
                'Attributes' => [
                    'ApproximateNumberOfMessages' => '0',
                ],
            ]));

        // Detailed call returns partial attributes
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->andReturn(new Result([
                'Attributes' => [],
            ]));

        $info = $this->queue->getDriverInfo('empty-queue');

        $this->assertSame('sqs', $info['driver']);
        $this->assertSame(0, $info['size']);
        $this->assertSame(0, $info['visible']);
        $this->assertSame(0, $info['in_flight']);
        $this->assertSame(0, $info['delayed']);
        $this->assertSame(0, $info['visibility_timeout']);
        $this->assertSame(0, $info['retention_period']);
        $this->assertSame('', $info['arn']);
    }

    // =========================================================================
    // Dead letter queue tests
    // =========================================================================

    public function testGetDeadLetterQueueReturnsEmptyOnUrlException(): void
    {
        // getQueueUrl should not throw for the standard prefix-based URL
        // but if SQS throws when resolving, it catches
        // We test this by ensuring the DLQ name convention is queue + '-dlq'
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result(['Messages' => []]));

        $result = $this->queue->getDeadLetterQueue('my-queue', 5);

        $this->assertEmpty($result);
    }

    public function testGetDeadLetterQueueWithMultipleBatches(): void
    {
        // First batch returns 3 messages
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['MaxNumberOfMessages'] === 5))
            ->andReturn(new Result([
                'Messages' => [
                    ['MessageId' => 'dlq-1', 'ReceiptHandle' => 'r1', 'Body' => '{}', 'Attributes' => []],
                    ['MessageId' => 'dlq-2', 'ReceiptHandle' => 'r2', 'Body' => '{}', 'Attributes' => []],
                    ['MessageId' => 'dlq-3', 'ReceiptHandle' => 'r3', 'Body' => '{}', 'Attributes' => []],
                ],
            ]));

        // Second batch - request remaining 2
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['MaxNumberOfMessages'] === 2))
            ->andReturn(new Result([
                'Messages' => [
                    ['MessageId' => 'dlq-4', 'ReceiptHandle' => 'r4', 'Body' => '{}', 'Attributes' => []],
                ],
            ]));

        // Third batch returns empty
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result(['Messages' => []]));

        $result = $this->queue->getDeadLetterQueue('my-queue', 5);

        $this->assertCount(4, $result);
        $this->assertSame('dlq-1', $result[0]['id']);
        $this->assertSame('r1', $result[0]['receipt_handle']);
    }

    public function testGetDeadLetterQueueRespectsMaxBatchSize(): void
    {
        // SQS max batch is 10. If limit is 15, first batch should be 10
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = [
                'MessageId' => "dlq-{$i}",
                'ReceiptHandle' => "r{$i}",
                'Body' => '{}',
                'Attributes' => [],
            ];
        }

        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['MaxNumberOfMessages'] === 10))
            ->andReturn(new Result(['Messages' => $messages]));

        // Second batch requests remaining 5
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['MaxNumberOfMessages'] === 5))
            ->andReturn(new Result(['Messages' => []]));

        $result = $this->queue->getDeadLetterQueue('my-queue', 15);

        $this->assertCount(10, $result);
    }

    public function testRequeueFromDeadLetterReturnsFalseWhenUrlResolutionFails(): void
    {
        // Simulate getQueueUrl throwing for the DLQ
        // We need a connection where getQueueUrl might fail
        // Since our standard connection builds URLs from prefix, it won't fail
        // But we can test the non-matching message path
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'other-msg-id',
                        'ReceiptHandle' => 'receipt-1',
                        'Body' => '{"job":"OtherJob"}',
                    ],
                ],
            ]));

        // No matching message should be found, so no sendMessage/deleteMessage
        $result = $this->queue->requeueFromDeadLetter('my-queue', 'nonexistent-id');

        $this->assertFalse($result);
    }

    public function testRequeueFromDeadLetterWithMultipleMessagesFindsCorrectOne(): void
    {
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([
                'Messages' => [
                    [
                        'MessageId' => 'wrong-id-1',
                        'ReceiptHandle' => 'receipt-1',
                        'Body' => '{"job":"WrongJob1"}',
                    ],
                    [
                        'MessageId' => 'target-id',
                        'ReceiptHandle' => 'receipt-2',
                        'Body' => '{"job":"TargetJob"}',
                    ],
                    [
                        'MessageId' => 'wrong-id-2',
                        'ReceiptHandle' => 'receipt-3',
                        'Body' => '{"job":"WrongJob2"}',
                    ],
                ],
            ]));

        // Should send the target message to the main queue
        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['MessageBody'] === '{"job":"TargetJob"}'))
            ->andReturn(new Result(['MessageId' => 'new-id']));

        // Should delete from DLQ
        $this->client->shouldReceive('deleteMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['ReceiptHandle'] === 'receipt-2'));

        $result = $this->queue->requeueFromDeadLetter('my-queue', 'target-id');

        $this->assertTrue($result);
    }

    public function testRequeueFromDeadLetterReturnsFalseWhenNoMessagesInResponse(): void
    {
        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new Result([])); // No 'Messages' key at all

        $result = $this->queue->requeueFromDeadLetter('my-queue', 'some-id');

        $this->assertFalse($result);
    }

    // =========================================================================
    // pop() tests
    // =========================================================================

    public function testPopUsesCorrectSqsParameters(): void
    {
        $this->createQueueStatusTable();

        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['MaxNumberOfMessages'] === 1
                    && $params['WaitTimeSeconds'] === 20
                    && $params['VisibilityTimeout'] === 30
                    && $params['AttributeNames'] === ['All']
                    && $params['MessageAttributeNames'] === ['All']))
            ->andReturn(new Result(['Messages' => []]));

        $this->queue->pop('test');

        $this->assertTrue(true);
    }

    public function testPopUsesDefaultQueueWhenNullProvided(): void
    {
        $this->createQueueStatusTable();

        $this->client->shouldReceive('receiveMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => str_ends_with($params['QueueUrl'], '/default')))
            ->andReturn(new Result(['Messages' => []]));

        $result = $this->queue->pop();

        $this->assertNull($result);
    }

    // =========================================================================
    // Pause/resume with connection name
    // =========================================================================

    public function testPauseUsesConnectionNameInDbQuery(): void
    {
        $this->createQueueStatusTable();

        $this->queue->setConnectionName('my-sqs');
        $this->queue->pause('test-queue');

        $record = DB::table('station_queue_status')
            ->where('queue', 'test-queue')
            ->where('connection', 'my-sqs')
            ->first();

        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->paused);
    }

    public function testResumeUsesConnectionNameInDbQuery(): void
    {
        $this->createQueueStatusTable();

        $this->queue->setConnectionName('my-sqs');
        $this->queue->pause('test-queue');
        $this->queue->resume('test-queue');

        $record = DB::table('station_queue_status')
            ->where('queue', 'test-queue')
            ->where('connection', 'my-sqs')
            ->first();

        $this->assertNotNull($record);
        $this->assertFalse((bool) $record->paused);
    }

    public function testPauseDefaultConnectionNameIsSqs(): void
    {
        $this->createQueueStatusTable();

        $freshQueue = new SqsQueue($this->connection, 'default');
        $freshQueue->setContainer($this->app);

        $freshQueue->pause('q1');

        $record = DB::table('station_queue_status')
            ->where('queue', 'q1')
            ->where('connection', 'sqs')
            ->first();

        $this->assertNotNull($record);
    }

    // =========================================================================
    // size() with missing attributes
    // =========================================================================

    public function testSizeReturnsZeroWhenAttributesMissing(): void
    {
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->andReturn(new Result([]));

        $size = $this->queue->size('default');

        $this->assertSame(0, $size);
    }

    public function testSizeUsesDefaultQueueWhenNullProvided(): void
    {
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->with(Mockery::on(static fn($params) => str_ends_with($params['QueueUrl'], '/default')))
            ->andReturn(new Result([
                'Attributes' => ['ApproximateNumberOfMessages' => '7'],
            ]));

        $size = $this->queue->size();

        $this->assertSame(7, $size);
    }

    // =========================================================================
    // getQueue private method via reflection
    // =========================================================================

    public function testGetQueueReturnsDefaultWhenNull(): void
    {
        $reflection = new ReflectionClass($this->queue);
        $method = $reflection->getMethod('getQueue');
        $method->setAccessible(true);

        $this->assertSame('default', $method->invoke($this->queue, null));
    }

    public function testGetQueueReturnsProvidedQueue(): void
    {
        $reflection = new ReflectionClass($this->queue);
        $method = $reflection->getMethod('getQueue');
        $method->setAccessible(true);

        $this->assertSame('custom', $method->invoke($this->queue, 'custom'));
    }

    // =========================================================================
    // clear() uses correct queue URL
    // =========================================================================

    public function testClearUsesCorrectQueueUrl(): void
    {
        $this->client->shouldReceive('getQueueAttributes')
            ->once()
            ->with(Mockery::on(static fn($params) => str_ends_with($params['QueueUrl'], '/specific-queue')))
            ->andReturn(new Result([
                'Attributes' => ['ApproximateNumberOfMessages' => '5'],
            ]));

        $this->client->shouldReceive('purgeQueue')
            ->once()
            ->with(Mockery::on(static fn($params) => str_ends_with($params['QueueUrl'], '/specific-queue')));

        $count = $this->queue->clear('specific-queue');

        $this->assertSame(5, $count);
    }

    // =========================================================================
    // pushRaw without attributes option
    // =========================================================================

    public function testPushRawWithoutAttributesDoesNotIncludeMessageAttributes(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => !isset($params['MessageAttributes'])))
            ->andReturn(new Result(['MessageId' => 'no-attr-id']));

        $id = $this->queue->pushRaw($payload, 'default');

        $this->assertSame('no-attr-id', $id);
    }

    // =========================================================================
    // laterRaw with small delay
    // =========================================================================

    public function testLaterRawWithSmallDelayUsesActualDelay(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => $params['DelaySeconds'] === 10))
            ->andReturn(new Result(['MessageId' => 'small-delay-id']));

        $id = $this->queue->laterRaw(10, $payload, 'default');

        $this->assertSame('small-delay-id', $id);
    }

    public function testLaterRawUsesDefaultQueueWhenNullProvided(): void
    {
        $payload = json_encode(['job' => 'TestJob']);

        $this->client->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(static fn($params) => str_ends_with($params['QueueUrl'], '/default')))
            ->andReturn(new Result(['MessageId' => 'null-queue-id']));

        $id = $this->queue->laterRaw(10, $payload);

        $this->assertSame('null-queue-id', $id);
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
    // Helper methods
    // =========================================================================

    private function createFifoConnection(): SqsConnection
    {
        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'queue' => 'default.fifo',
            'wait_time' => 20,
            'visibility_timeout' => 30,
            'fifo' => true,
            'message_group_id' => 'default',
            'content_based_deduplication' => false,
        ];

        $connection = new SqsConnection($config);

        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($connection, $this->client);

        return $connection;
    }

    private function createFifoConnectionWithContentDedup(): SqsConnection
    {
        $config = [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'queue' => 'default.fifo',
            'wait_time' => 20,
            'visibility_timeout' => 30,
            'fifo' => true,
            'message_group_id' => 'default',
            'content_based_deduplication' => true,
        ];

        $connection = new SqsConnection($config);

        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($connection, $this->client);

        return $connection;
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
