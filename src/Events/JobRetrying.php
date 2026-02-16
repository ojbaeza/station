<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Job;
use Throwable;

final class JobRetrying
{
    use Dispatchable;

    public function __construct(
        public readonly Job $job,
        public readonly int $attempt,
        public readonly ?int $delay,
        public readonly ?Throwable $exception,
    ) {}
}
