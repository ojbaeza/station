<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class WorkerLoopIteration
{
    use Dispatchable;

    public function __construct(
        public readonly string $workerId,
    ) {}
}
