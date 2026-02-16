<?php

declare(strict_types=1);

namespace Station\Drivers\Sqs;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

final class SqsConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue
    {
        $connection = new SqsConnection($config);

        return new SqsQueue(
            $connection,
            $config['queue'] ?? 'default',
        );
    }
}
