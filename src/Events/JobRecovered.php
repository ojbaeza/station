<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Job;

final class JobRecovered
{
    use Dispatchable;

    public function __construct(
        public readonly Job $job,
        public readonly string $strategy,
        public readonly bool $fromCheckpoint,
    ) {}
}
