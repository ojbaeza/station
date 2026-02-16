<?php

declare(strict_types=1);

namespace Station\Drivers\Kafka;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

final class KafkaConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue
    {
        $connection = new KafkaConnection($config);

        return new KafkaQueue(
            $connection,
            $config['queue'] ?? 'default',
        );
    }
}
