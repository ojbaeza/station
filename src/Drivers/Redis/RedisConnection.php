<?php

declare(strict_types=1);

namespace Station\Drivers\Redis;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Station\Exceptions\ConnectionException;
use Throwable;

final class RedisConnection
{
    private ?Connection $connection = null;

    public function __construct(
        private readonly RedisFactory $redis,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Get the Redis connection.
     */
    public function getConnection(): Connection
    {
        if ($this->connection === null) {
            $this->connect();
        }

        if ($this->connection === null) {
            throw new ConnectionException('Failed to initialize Redis connection');
        }

        return $this->connection;
    }

    /**
     * Get the prefix for keys.
     */
    public function getPrefix(): string
    {
        return $this->config['prefix'] ?? 'station:';
    }

    /**
     * Build a key with prefix.
     */
    public function key(string $key): string
    {
        return $this->getPrefix() . $key;
    }

    /**
     * Check if connected.
     */
    public function isConnected(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            $this->connection->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Disconnect from Redis.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * Reconnect to Redis.
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * Establish the connection.
     */
    private function connect(): void
    {
        $connectionName = $this->config['connection'] ?? 'default';

        try {
            $this->connection = $this->redis->connection($connectionName);
        } catch (Throwable $e) {
            throw new ConnectionException(
                'Failed to connect to Redis: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
