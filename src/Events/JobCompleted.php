<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class JobCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $jobId,
        public readonly string $jobClass,
        public readonly string $queue,
        public readonly string $connection,
        public readonly float $durationMs,
    ) {}
}
