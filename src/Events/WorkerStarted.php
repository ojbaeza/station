<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class WorkerStarted
{
    use Dispatchable;

    /**
     * @param array<int, string> $queues
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $worker,
        public readonly array $queues,
        public readonly array $options,
    ) {}
}
