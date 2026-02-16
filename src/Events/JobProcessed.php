<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Job;

final class JobProcessed
{
    use Dispatchable;

    public function __construct(
        public readonly Job $job,
        public readonly string $worker,
        public readonly int $duration,
        public readonly int $memoryUsed,
    ) {}
}
