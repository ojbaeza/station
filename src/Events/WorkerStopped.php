<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class WorkerStopped
{
    use Dispatchable;

    public function __construct(
        public readonly string $worker,
        public readonly string $reason,
        public readonly int $jobsProcessed,
    ) {}
}
