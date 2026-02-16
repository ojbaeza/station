<?php

declare(strict_types=1);

namespace Station\Drivers\Beanstalkd;

use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Timeout;
use Station\Exceptions\ConnectionException;
use Throwable;

final class BeanstalkdConnection
{
    private ?Pheanstalk $client = null;

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Get the Pheanstalk client.
     *
     * @throws ConnectionException
     */
    public function getClient(): Pheanstalk
    {
        if ($this->client === null) {
            $this->connect();
        }

        if ($this->client === null) {
            throw new ConnectionException('Failed to initialize Beanstalkd client');
        }

        return $this->client;
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
            // Use listTubes as a simple connection test instead of stats()
            // stats() can fail with older Beanstalkd versions missing the 'draining' key
            $this->client->listTubes();

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
    }

    /**
     * Get the default tube name.
     */
    public function getDefaultTube(): string
    {
        return $this->config['queue'] ?? 'default';
    }

    /**
     * Get the time-to-run (TTR) for jobs.
     */
    public function getTtr(): int
    {
        return $this->config['ttr'] ?? 60;
    }

    /**
     * Get the reserve timeout.
     */
    public function getReserveTimeout(): int
    {
        return $this->config['reserve_timeout'] ?? 5;
    }

    /**
     * Get the default priority (lower is higher priority).
     */
    public function getDefaultPriority(): int
    {
        return $this->config['priority'] ?? PheanstalkPublisherInterface::DEFAULT_PRIORITY;
    }

    /**
     * Get retry delay for buried jobs.
     */
    public function getRetryDelay(): int
    {
        return $this->config['retry_delay'] ?? 60;
    }

    /**
     * Establish the connection.
     */
    private function connect(): void
    {
        try {
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = $this->config['port'] ?? 11300;
            $timeoutSeconds = $this->config['timeout'] ?? 10;

            $this->client = Pheanstalk::create(
                $host,
                $port,
                new Timeout($timeoutSeconds),
            );
        } catch (Throwable $e) {
            throw new ConnectionException(
                'Failed to connect to Beanstalkd: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
