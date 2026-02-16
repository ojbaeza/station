<?php

declare(strict_types=1);

namespace Station\Drivers\Sqs;

use Aws\Sqs\SqsClient;
use Station\Exceptions\ConnectionException;
use Throwable;

final class SqsConnection
{
    private ?SqsClient $client = null;

    /** @var array<string, string> */
    private array $queueUrls = [];

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Get the SQS client.
     */
    public function getClient(): SqsClient
    {
        if ($this->client === null) {
            $this->connect();
        }

        if ($this->client === null) {
            throw new ConnectionException('Failed to initialize SQS client');
        }

        return $this->client;
    }

    /**
     * Get the queue URL for a queue name.
     */
    public function getQueueUrl(string $queue): string
    {
        if (isset($this->queueUrls[$queue])) {
            return $this->queueUrls[$queue];
        }

        $prefix = $this->config['prefix'] ?? '';
        $suffix = $this->config['suffix'] ?? '';

        // Build the queue URL
        if (str_starts_with($queue, 'https://')) {
            $url = $queue;
        } else {
            $url = rtrim($prefix, '/') . '/' . $queue . $suffix;
        }

        $this->queueUrls[$queue] = $url;

        return $url;
    }

    /**
     * Check if connected.
     */
    public function isConnected(): bool
    {
        if ($this->client === null) {
            return false;
        }

        try {
            $this->client->listQueues(['MaxResults' => 1]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Disconnect.
     */
    public function disconnect(): void
    {
        $this->client = null;
        $this->queueUrls = [];
    }

    /**
     * Check if using FIFO queues.
     */
    public function isFifo(): bool
    {
        return $this->config['fifo'] ?? false;
    }

    /**
     * Get message group ID for FIFO queues.
     */
    public function getMessageGroupId(?string $groupId = null): string
    {
        return $groupId ?? $this->config['message_group_id'] ?? 'default';
    }

    /**
     * Get deduplication ID for FIFO queues.
     */
    public function getDeduplicationId(string $payload): string
    {
        if ($this->config['content_based_deduplication'] ?? false) {
            return '';
        }

        return hash('sha256', $payload . microtime(true) . random_bytes(8));
    }

    /**
     * Get wait time for long polling.
     */
    public function getWaitTime(): int
    {
        return min($this->config['wait_time'] ?? 20, 20);
    }

    /**
     * Get visibility timeout.
     */
    public function getVisibilityTimeout(): int
    {
        return $this->config['visibility_timeout'] ?? 30;
    }

    /**
     * Establish the connection.
     */
    private function connect(): void
    {
        try {
            $options = [
                'region' => $this->config['region'] ?? 'us-east-1',
                'version' => 'latest',
            ];

            // Add credentials if provided
            if (isset($this->config['key']) && isset($this->config['secret'])) {
                $options['credentials'] = [
                    'key' => $this->config['key'],
                    'secret' => $this->config['secret'],
                    'token' => $this->config['token'] ?? null,
                ];
            }

            // Add endpoint for LocalStack
            if (isset($this->config['endpoint'])) {
                $options['endpoint'] = $this->config['endpoint'];
            }

            $this->client = new SqsClient($options);
        } catch (Throwable $e) {
            throw new ConnectionException(
                'Failed to connect to SQS: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
