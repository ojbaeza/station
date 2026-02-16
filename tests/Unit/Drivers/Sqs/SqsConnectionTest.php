<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Sqs;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Exception;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Station\Drivers\Sqs\SqsConnection;

class SqsConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetQueueUrlBuildsUrlFromPrefix(): void
    {
        $connection = new SqsConnection([
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
        ]);

        $url = $connection->getQueueUrl('my-queue');

        $this->assertSame('https://sqs.us-east-1.amazonaws.com/123456789/my-queue', $url);
    }

    public function testGetQueueUrlWithSuffix(): void
    {
        $connection = new SqsConnection([
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
            'suffix' => '.fifo',
        ]);

        $url = $connection->getQueueUrl('my-queue');

        $this->assertSame('https://sqs.us-east-1.amazonaws.com/123456789/my-queue.fifo', $url);
    }

    public function testGetQueueUrlReturnsFullUrlAsIs(): void
    {
        $connection = new SqsConnection([]);

        $fullUrl = 'https://sqs.eu-west-1.amazonaws.com/999999/custom-queue';
        $url = $connection->getQueueUrl($fullUrl);

        $this->assertSame($fullUrl, $url);
    }

    public function testGetQueueUrlCachesResult(): void
    {
        $connection = new SqsConnection([
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
        ]);

        $url1 = $connection->getQueueUrl('my-queue');
        $url2 = $connection->getQueueUrl('my-queue');

        $this->assertSame($url1, $url2);
    }

    public function testIsConnectedReturnsFalseWhenNotConnected(): void
    {
        $connection = new SqsConnection([]);

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectClearsClientAndQueueUrls(): void
    {
        $connection = new SqsConnection([
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
        ]);

        // Build a queue URL to cache it
        $connection->getQueueUrl('my-queue');

        $connection->disconnect();

        $this->assertFalse($connection->isConnected());
    }

    public function testIsFifoReturnsFalseByDefault(): void
    {
        $connection = new SqsConnection([]);

        $this->assertFalse($connection->isFifo());
    }

    public function testIsFifoReturnsTrueWhenConfigured(): void
    {
        $connection = new SqsConnection([
            'fifo' => true,
        ]);

        $this->assertTrue($connection->isFifo());
    }

    public function testGetMessageGroupIdReturnsDefaultWhenNotProvided(): void
    {
        $connection = new SqsConnection([]);

        $this->assertSame('default', $connection->getMessageGroupId());
    }

    public function testGetMessageGroupIdReturnsProvidedValue(): void
    {
        $connection = new SqsConnection([]);

        $this->assertSame('custom-group', $connection->getMessageGroupId('custom-group'));
    }

    public function testGetMessageGroupIdReturnsConfiguredValue(): void
    {
        $connection = new SqsConnection([
            'message_group_id' => 'configured-group',
        ]);

        $this->assertSame('configured-group', $connection->getMessageGroupId());
    }

    public function testGetDeduplicationIdGeneratesHash(): void
    {
        $connection = new SqsConnection([]);

        $id = $connection->getDeduplicationId('test-payload');

        $this->assertNotEmpty($id);
        $this->assertSame(64, \strlen($id)); // SHA256 hash length
    }

    public function testGetDeduplicationIdReturnsEmptyForContentBasedDeduplication(): void
    {
        $connection = new SqsConnection([
            'content_based_deduplication' => true,
        ]);

        $id = $connection->getDeduplicationId('test-payload');

        $this->assertSame('', $id);
    }

    public function testGetWaitTimeReturnsDefault(): void
    {
        $connection = new SqsConnection([]);

        $this->assertSame(20, $connection->getWaitTime());
    }

    public function testGetWaitTimeReturnsConfiguredValue(): void
    {
        $connection = new SqsConnection([
            'wait_time' => 10,
        ]);

        $this->assertSame(10, $connection->getWaitTime());
    }

    public function testGetWaitTimeCapsat20(): void
    {
        $connection = new SqsConnection([
            'wait_time' => 30,
        ]);

        $this->assertSame(20, $connection->getWaitTime());
    }

    public function testGetVisibilityTimeoutReturnsDefault(): void
    {
        $connection = new SqsConnection([]);

        $this->assertSame(30, $connection->getVisibilityTimeout());
    }

    public function testGetVisibilityTimeoutReturnsConfiguredValue(): void
    {
        $connection = new SqsConnection([
            'visibility_timeout' => 60,
        ]);

        $this->assertSame(60, $connection->getVisibilityTimeout());
    }

    public function testIsConnectedReturnsTrueWhenClientWorks(): void
    {
        $connection = new SqsConnection([]);

        /** @var MockInterface&SqsClient $client */
        $client = Mockery::mock(SqsClient::class);
        $client->shouldReceive('listQueues')
            ->once()
            ->with(['MaxResults' => 1])
            ->andReturn(new Result([]));

        // Inject mocked client
        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('client');
        $property->setValue($connection, $client);

        $this->assertTrue($connection->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenClientThrows(): void
    {
        $connection = new SqsConnection([]);

        /** @var MockInterface&SqsClient $client */
        $client = Mockery::mock(SqsClient::class);
        $client->shouldReceive('listQueues')
            ->once()
            ->andThrow(new Exception('Connection failed'));

        // Inject mocked client
        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('client');
        $property->setValue($connection, $client);

        $this->assertFalse($connection->isConnected());
    }

    public function testGetQueueUrlHandlesTrailingSlashInPrefix(): void
    {
        $connection = new SqsConnection([
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789/',
        ]);

        $url = $connection->getQueueUrl('my-queue');

        $this->assertSame('https://sqs.us-east-1.amazonaws.com/123456789/my-queue', $url);
    }

    public function testGetDeduplicationIdGeneratesUniqueHashes(): void
    {
        $connection = new SqsConnection([]);

        $id1 = $connection->getDeduplicationId('payload');
        $id2 = $connection->getDeduplicationId('payload');

        // Should be different due to microtime and random_bytes
        $this->assertNotSame($id1, $id2);
    }

    public function testDisconnectClearsQueueUrlCache(): void
    {
        $connection = new SqsConnection([
            'prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789',
        ]);

        // Cache a URL
        $connection->getQueueUrl('test-queue');

        // Inject a mock client
        $reflection = new ReflectionClass($connection);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($connection, Mockery::mock(SqsClient::class));

        $connection->disconnect();

        // Verify queue URLs were cleared via reflection
        $queueUrlsProperty = $reflection->getProperty('queueUrls');
        $this->assertEmpty($queueUrlsProperty->getValue($connection));
    }

    public function testGetClientCreatesSqsClient(): void
    {
        $connection = new SqsConnection([
            'region' => 'us-west-2',
            'key' => 'test-key',
            'secret' => 'test-secret',
        ]);

        $client = $connection->getClient();

        $this->assertInstanceOf(SqsClient::class, $client);
    }

    public function testGetClientReturnsCachedClient(): void
    {
        $connection = new SqsConnection([
            'region' => 'us-east-1',
        ]);

        $client1 = $connection->getClient();
        $client2 = $connection->getClient();

        $this->assertSame($client1, $client2);
    }

    public function testGetClientWithEndpoint(): void
    {
        $connection = new SqsConnection([
            'region' => 'us-east-1',
            'endpoint' => 'http://localhost:4566',
        ]);

        $client = $connection->getClient();

        $this->assertInstanceOf(SqsClient::class, $client);
    }

    public function testGetClientWithToken(): void
    {
        $connection = new SqsConnection([
            'region' => 'us-east-1',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'token' => 'session-token',
        ]);

        $client = $connection->getClient();

        $this->assertInstanceOf(SqsClient::class, $client);
    }

    public function testGetClientWithDefaultRegion(): void
    {
        $connection = new SqsConnection([]);

        $client = $connection->getClient();

        $this->assertInstanceOf(SqsClient::class, $client);
    }

    public function testGetClientWithoutCredentials(): void
    {
        // When key is missing, credentials are not set explicitly
        $connection = new SqsConnection([
            'region' => 'us-east-1',
            'secret' => 'test-secret', // Only secret, no key
        ]);

        $client = $connection->getClient();

        $this->assertInstanceOf(SqsClient::class, $client);
    }
}
