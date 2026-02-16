<?php

declare(strict_types=1);

namespace Station\Drivers\Beanstalkd;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

final class BeanstalkdConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue
    {
        $connection = new BeanstalkdConnection($config);

        return new BeanstalkdQueue(
            $connection,
            $config['queue'] ?? 'default',
        );
    }
}
