<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Job;

final class JobDispatched
{
    use Dispatchable;

    public function __construct(
        public readonly Job $job,
    ) {}
}
