<?php

declare(strict_types=1);

namespace Station\Drivers\RabbitMQ;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

final class RabbitMQConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue
    {
        $connection = new RabbitMQConnection($config);

        return new RabbitMQQueue(
            $connection,
            $config['exchange']['name'] ?? 'station.direct',
            $config['queue'] ?? 'default',
            $config,
        );
    }
}
