<?php

declare(strict_types=1);

namespace Station\Drivers\RabbitMQ;

use AMQPChannel;
use AMQPConnection;
use AMQPExchange;
use AMQPQueue;
use Station\Exceptions\ConnectionException;
use Throwable;

final class RabbitMQConnection
{
    private ?AMQPConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var array<string, AMQPExchange> */
    private array $exchanges = [];

    /** @var array<string, AMQPQueue> */
    private array $queues = [];

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Get the AMQP connection.
     */
    public function getConnection(): AMQPConnection
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connect();
        }

        if ($this->connection === null) {
            throw new ConnectionException('Failed to establish RabbitMQ connection');
        }

        return $this->connection;
    }

    /**
     * Get the AMQP channel.
     */
    public function getChannel(): AMQPChannel
    {
        if ($this->channel === null || !$this->channel->isConnected()) {
            $this->channel = new AMQPChannel($this->getConnection());
        }

        return $this->channel;
    }

    /**
     * Get or create an exchange.
     */
    public function getExchange(string $name, string $type = 'direct'): AMQPExchange
    {
        if (!isset($this->exchanges[$name])) {
            $exchange = new AMQPExchange($this->getChannel());
            $exchange->setName($name);
            $exchange->setType($type);
            $exchange->setFlags(AMQP_DURABLE);
            $exchange->declareExchange();

            $this->exchanges[$name] = $exchange;
        }

        return $this->exchanges[$name];
    }

    /**
     * Get or create a queue.
     */
    public function getQueue(string $name): AMQPQueue
    {
        if (!isset($this->queues[$name])) {
            $queue = new AMQPQueue($this->getChannel());
            $queue->setName($name);
            $queue->setFlags(AMQP_DURABLE);

            // Set up dead letter exchange if configured
            if ($this->config['dead_letter']['enabled'] ?? true) {
                $dlxExchange = $this->config['dead_letter']['exchange'] ?? 'station.dlx';
                $queue->setArgument('x-dead-letter-exchange', $dlxExchange);
                $queue->setArgument('x-dead-letter-routing-key', $name . '.dlq');
            }

            $queue->declareQueue();

            // Bind to exchange
            $exchangeName = $this->config['exchange']['name'] ?? 'station.direct';
            $queue->bind($exchangeName, $name);

            $this->queues[$name] = $queue;
        }

        return $this->queues[$name];
    }

    /**
     * Check if connected.
     */
    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    /**
     * Disconnect from RabbitMQ.
     */
    public function disconnect(): void
    {
        if ($this->channel !== null && $this->channel->isConnected()) {
            // Note: AMQPChannel doesn't have a close method, it's closed when connection closes
        }

        if ($this->connection !== null && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }

        $this->connection = null;
        $this->channel = null;
        $this->exchanges = [];
        $this->queues = [];
    }

    /**
     * Reconnect to RabbitMQ.
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * Establish a connection to RabbitMQ.
     */
    private function connect(): void
    {
        $hosts = $this->config['hosts'] ?? [];

        if ($hosts === []) {
            throw new ConnectionException('No RabbitMQ hosts configured');
        }

        // Try each host until one succeeds
        $lastException = null;

        foreach ($hosts as $hostConfig) {
            try {
                $this->connection = new AMQPConnection([
                    'host' => $hostConfig['host'] ?? 'localhost',
                    'port' => $hostConfig['port'] ?? 5672,
                    'login' => $hostConfig['username'] ?? 'guest',
                    'password' => $hostConfig['password'] ?? 'guest',
                    'vhost' => $hostConfig['vhost'] ?? '/',
                    'connect_timeout' => $this->config['options']['connection_timeout'] ?? 10.0,
                    'read_timeout' => $this->config['options']['read_write_timeout'] ?? 30.0,
                    'write_timeout' => $this->config['options']['read_write_timeout'] ?? 30.0,
                    'heartbeat' => $this->config['options']['heartbeat'] ?? 60,
                ]);

                $this->connection->connect();

                return;
            } catch (Throwable $e) {
                $lastException = $e;

                continue;
            }
        }

        throw new ConnectionException(
            'Failed to connect to RabbitMQ: ' . ($lastException?->getMessage() ?? 'Unknown error'),
            0,
            $lastException,
        );
    }
}
