<?php

declare(strict_types=1);

namespace Station\Drivers\RabbitMQ;

use AMQPChannelException;
use AMQPConnectionException;
use AMQPEnvelope;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Station\Contracts\AggregateDriverInfoInterface;
use Station\Contracts\DriverInterface;
use Station\Drivers\Traits\ChecksPauseStatus;
use Throwable;

final class RabbitMQQueue extends Queue implements AggregateDriverInfoInterface, DriverInterface, QueueContract
{
    use ChecksPauseStatus;

    /**
     * @param array<string, mixed> $driverConfig
     */
    public function __construct(
        private readonly RabbitMQConnection $connection,
        private readonly string $exchange,
        private readonly string $defaultQueue,
        private array $driverConfig = [],
    ) {}

    /**
     * Get the size of the queue.
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);
        $amqpQueue = $this->connection->getQueue($queue);

        return $amqpQueue->declareQueue();
    }

    /**
     * Push a new job onto the queue.
     */
    public function push($job, $data = '', $queue = null)
    {
        $queueName = $this->getQueue($queue);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queueName, $data),
            $queueName,
            null,
            fn($payload, $queue) => $this->enqueue($queue, $payload),
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param array<string, mixed> $options
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->enqueue($queue, $payload, $options);
    }

    /**
     * Push a new job onto the queue after a delay.
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $queueName = $this->getQueue($queue);
        $delayMs = (int) ($this->secondsUntil($delay) * 1000);

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queueName, $data),
            $queueName,
            $delay,
            fn($payload, $queue) => $this->enqueue($queue, $payload, ['delay' => $delayMs]),
        );
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        if ($this->isPaused($queue)) {
            return null;
        }

        try {
            $amqpQueue = $this->connection->getQueue($queue);

            // Get message without auto-acknowledge (we'll ack manually after job completes)
            // AMQPQueue::get() returns false when no message is available
            $envelope = $amqpQueue->get();

            if ($envelope === false || !$envelope instanceof AMQPEnvelope) { // @phpstan-ignore identical.alwaysFalse
                return null;
            }

            return new RabbitMQJob(
                $this->container,
                $this,
                $envelope,
                $this->getConnectionName(),
                $queue,
            );
        } catch (AMQPChannelException|AMQPConnectionException $e) {
            // Channel or connection lost, try to reconnect
            $this->connection->reconnect();

            return null;
        }
    }

    /**
     * Clear all jobs from a queue.
     */
    public function clear(string $queue): int
    {
        $amqpQueue = $this->connection->getQueue($queue);
        $count = $amqpQueue->declareQueue();
        $amqpQueue->purge();

        return $count;
    }

    /**
     * Pause a queue.
     */
    public function pause(string $queue): void
    {
        DB::table(config('station.storage.database.table_prefix', 'station_') . 'queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'rabbitmq'],
            ['paused' => true, 'paused_at' => now(), 'updated_at' => now()],
        );

        $this->pauseCache[$queue] = true;
        $this->pauseCacheTime[$queue] = microtime(true);
    }

    /**
     * Resume a paused queue.
     */
    public function resume(string $queue): void
    {
        DB::table(config('station.storage.database.table_prefix', 'station_') . 'queue_status')->updateOrInsert(
            ['queue' => $queue, 'connection' => $this->connectionName ?: 'rabbitmq'],
            ['paused' => false, 'paused_at' => null, 'updated_at' => now()],
        );

        $this->pauseCache[$queue] = false;
        $this->pauseCacheTime[$queue] = microtime(true);
    }

    /**
     * Get the dead letter queue contents.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDeadLetterQueue(string $queue, int $limit = 50): array
    {
        $dlqName = $queue . '.dlq';
        $messages = [];

        try {
            $dlq = $this->connection->getQueue($dlqName);
            $count = 0;

            while ($count < $limit) {
                $envelope = $dlq->get(AMQP_NOPARAM);

                if (!$envelope instanceof AMQPEnvelope) {
                    break;
                }

                $deliveryTag = $envelope->getDeliveryTag();
                $messages[] = [
                    'id' => $deliveryTag ?? 0,
                    'body' => $envelope->getBody(),
                    'headers' => $envelope->getHeaders(),
                    'routing_key' => $envelope->getRoutingKey(),
                    'timestamp' => $envelope->getTimestamp(),
                ];

                // Nack+requeue so the message stays in the DLQ (read-only peek)
                $dlq->nack((int) ($deliveryTag ?? 0), AMQP_REQUEUE);

                $count++;
            }
        } catch (Throwable) {
            // DLQ might not exist
        }

        return $messages;
    }

    /**
     * Requeue a message from the dead letter queue.
     */
    public function requeueFromDeadLetter(string $queue, string $messageId): bool
    {
        $dlqName = $queue . '.dlq';

        try {
            $dlq = $this->connection->getQueue($dlqName);

            // Find and republish the message
            while (true) {
                $envelope = $dlq->get();

                if (!$envelope instanceof AMQPEnvelope) {
                    break;
                }

                $deliveryTag = $envelope->getDeliveryTag();
                if ($deliveryTag === null) {
                    continue;
                }

                if ((string) $deliveryTag === $messageId) {
                    // Republish to original queue
                    $this->pushRaw($envelope->getBody(), $queue);
                    $dlq->ack($deliveryTag);

                    return true;
                }

                // Put it back (nack without requeue)
                $dlq->nack($deliveryTag, AMQP_REQUEUE);
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Get driver-specific health status.
     *
     * @return array{connected: bool, latency_ms: int, message?: string}
     */
    public function healthCheck(): array
    {
        $start = microtime(true);

        try {
            $this->connection->getConnection();
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'connected' => true,
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'latency_ms' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get driver-specific detailed info for metrics dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDriverInfo(string $queue): array
    {
        $info = [
            'driver' => 'rabbitmq',
            'size' => $this->size($queue),
        ];

        $managementUrl = $this->driverConfig['management_url']
            ?? config('queue.connections.' . $this->getConnectionName() . '.management_url');

        if (!$managementUrl) {
            $info['management_api'] = false;

            return $info;
        }

        try {
            $firstHost = $this->driverConfig['hosts'][0] ?? [];
            $user = $this->driverConfig['management_user']
                ?? config('queue.connections.' . $this->getConnectionName() . '.management_user')
                ?? $this->driverConfig['user'] ?? $firstHost['username'] ?? 'guest';
            $password = $this->driverConfig['management_password']
                ?? config('queue.connections.' . $this->getConnectionName() . '.management_password')
                ?? $this->driverConfig['password'] ?? $firstHost['password'] ?? 'guest';
            $vhost = rawurlencode($this->driverConfig['vhost'] ?? $firstHost['vhost'] ?? '/');

            $client = new Factory();

            $queueData = $client->withBasicAuth($user, $password)
                ->timeout(5)
                ->get("{$managementUrl}/api/queues/{$vhost}/{$queue}")
                ->json();

            if ($queueData) {
                $info['management_api'] = true;
                $info['messages_ready'] = $queueData['messages_ready'] ?? 0;
                $info['messages_unacked'] = $queueData['messages_unacknowledged'] ?? 0;
                $info['consumers'] = $queueData['consumers'] ?? 0;
                $info['publish_rate'] = $queueData['message_stats']['publish_details']['rate'] ?? 0;
                $info['deliver_rate'] = $queueData['message_stats']['deliver_get_details']['rate'] ?? 0;
                $info['memory'] = $queueData['memory'] ?? 0;
            }

            // Check DLQ
            $dlqData = $client->withBasicAuth($user, $password)
                ->timeout(5)
                ->get("{$managementUrl}/api/queues/{$vhost}/{$queue}.dlq")
                ->json();

            $info['dlq_size'] = $dlqData['messages'] ?? 0;
        } catch (Throwable) {
            $info['management_api'] = false;
        }

        return $info;
    }

    /**
     * Get driver info aggregated across all discoverable queues.
     *
     * @return array<string, mixed>
     */
    public function getAllDriverInfo(): array
    {
        $managementUrl = $this->driverConfig['management_url']
            ?? config('queue.connections.' . $this->getConnectionName() . '.management_url');

        if (!$managementUrl) {
            return $this->getDriverInfo($this->defaultQueue);
        }

        try {
            $firstHost = $this->driverConfig['hosts'][0] ?? [];
            $user = $this->driverConfig['management_user']
                ?? config('queue.connections.' . $this->getConnectionName() . '.management_user')
                ?? $this->driverConfig['user'] ?? $firstHost['username'] ?? 'guest';
            $password = $this->driverConfig['management_password']
                ?? config('queue.connections.' . $this->getConnectionName() . '.management_password')
                ?? $this->driverConfig['password'] ?? $firstHost['password'] ?? 'guest';
            $vhost = rawurlencode($this->driverConfig['vhost'] ?? $firstHost['vhost'] ?? '/');

            $client = new Factory();

            /** @var array<int, array<string, mixed>>|null $allQueues */
            $allQueues = $client->withBasicAuth($user, $password)
                ->timeout(5)
                ->get("{$managementUrl}/api/queues/{$vhost}")
                ->json();

            if (!\is_array($allQueues)) {
                return $this->getDriverInfo($this->defaultQueue);
            }

            $totalSize = 0;
            $totalReady = 0;
            $totalUnacked = 0;
            $totalConsumers = 0;
            $totalPublishRate = 0.0;
            $totalDeliverRate = 0.0;
            $totalMemory = 0;
            $totalDlqSize = 0;
            $queues = [];
            $queueCount = 0;

            foreach ($allQueues as $queueData) {
                $name = $queueData['name'] ?? '';

                // Skip DLQ queues from main listing
                if (str_ends_with($name, '.dlq')) {
                    $totalDlqSize += (int) ($queueData['messages'] ?? 0);

                    continue;
                }

                $messages = (int) ($queueData['messages'] ?? 0);
                $ready = (int) ($queueData['messages_ready'] ?? 0);
                $unacked = (int) ($queueData['messages_unacknowledged'] ?? 0);
                $consumers = (int) ($queueData['consumers'] ?? 0);
                $publishRate = (float) ($queueData['message_stats']['publish_details']['rate'] ?? 0);
                $deliverRate = (float) ($queueData['message_stats']['deliver_get_details']['rate'] ?? 0);
                $memory = (int) ($queueData['memory'] ?? 0);

                $totalSize += $messages;
                $totalReady += $ready;
                $totalUnacked += $unacked;
                $totalConsumers += $consumers;
                $totalPublishRate += $publishRate;
                $totalDeliverRate += $deliverRate;
                $totalMemory += $memory;
                $queueCount++;

                if (\count($queues) < 10) {
                    $queues[$name] = [
                        'size' => $messages,
                        'messages_ready' => $ready,
                        'messages_unacked' => $unacked,
                        'consumers' => $consumers,
                        'publish_rate' => $publishRate,
                        'deliver_rate' => $deliverRate,
                        'memory' => $memory,
                    ];
                }
            }

            return [
                'driver' => 'rabbitmq',
                'management_api' => true,
                'size' => $totalSize,
                'messages_ready' => $totalReady,
                'messages_unacked' => $totalUnacked,
                'consumers' => $totalConsumers,
                'publish_rate' => $totalPublishRate,
                'deliver_rate' => $totalDeliverRate,
                'memory' => $totalMemory,
                'dlq_size' => $totalDlqSize,
                'queues' => $queues,
                'queues_total' => $queueCount,
            ];
        } catch (Throwable) {
            return $this->getDriverInfo($this->defaultQueue);
        }
    }

    /**
     * Get the connection name.
     */
    public function getConnectionName(): string
    {
        // @phpstan-ignore nullCoalesce.property (connectionName can be unset before setConnectionName is called)
        return $this->connectionName ?? 'station';
    }

    /**
     * Set the connection name.
     */
    public function setConnectionName($name)
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Acknowledge a message.
     */
    public function ack(AMQPEnvelope $envelope, string $queue): void
    {
        $deliveryTag = $envelope->getDeliveryTag();
        if ($deliveryTag === null) {
            return;
        }

        $amqpQueue = $this->connection->getQueue($queue);
        $amqpQueue->ack($deliveryTag);
    }

    /**
     * Reject a message.
     */
    public function reject(AMQPEnvelope $envelope, string $queue, bool $requeue = false): void
    {
        $deliveryTag = $envelope->getDeliveryTag();
        if ($deliveryTag === null) {
            return;
        }

        $amqpQueue = $this->connection->getQueue($queue);
        $amqpQueue->nack($deliveryTag, $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
    }

    /**
     * Enqueue a message.
     *
     * @param array<string, mixed> $options
     */
    private function enqueue(?string $queue, string $payload, array $options = []): string
    {
        $queue = $this->getQueue($queue);
        // Ensure queue exists and is bound to exchange before publishing
        $this->connection->getQueue($queue);
        $exchange = $this->connection->getExchange($this->exchange);

        $attributes = [
            'delivery_mode' => 2, // Persistent
            'content_type' => 'application/json',
            'message_id' => Uuid::uuid7()->toString(),
        ];

        // Handle delayed messages
        if (isset($options['delay']) && $options['delay'] > 0) {
            $attributes['headers'] = ['x-delay' => $options['delay']];
            $delayedExchange = $this->connection->getExchange(
                $this->driverConfig['delayed']['exchange'] ?? 'station.delayed',
                'x-delayed-message',
            );
            $delayedExchange->publish($payload, $queue, AMQP_NOPARAM, $attributes);
        } else {
            $exchange->publish($payload, $queue, AMQP_NOPARAM, $attributes);
        }

        return $attributes['message_id'];
    }

    /**
     * Get the queue name.
     */
    private function getQueue(?string $queue): string
    {
        return $queue ?? $this->defaultQueue;
    }
}
