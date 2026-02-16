<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class SupervisorStopped
{
    use Dispatchable;

    public function __construct(
        public readonly string $supervisorId,
        public readonly string $name,
        public readonly string $reason,
        public readonly int $jobsProcessed,
    ) {}
}
