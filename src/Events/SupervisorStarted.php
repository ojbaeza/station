<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SupervisorStarted
{
    use Dispatchable;

    /**
     * @param array<int, string> $queues
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $supervisorId,
        public readonly string $name,
        public readonly array $queues,
        public readonly array $options,
    ) {}
}
