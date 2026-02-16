<?php

declare(strict_types=1);

namespace Station\Drivers\Redis;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\ConnectorInterface;

final class RedisConnector implements ConnectorInterface
{
    public function __construct(
        private readonly RedisFactory $redis,
    ) {}

    /**
     * Establish a queue connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue
    {
        $connection = new RedisConnection($this->redis, $config);

        return new RedisQueue(
            $connection,
            $config['queue'] ?? 'default',
            $config,
        );
    }
}
