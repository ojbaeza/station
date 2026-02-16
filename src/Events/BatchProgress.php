<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Batch;

final class BatchProgress
{
    use Dispatchable;

    public function __construct(
        public readonly Batch $batch,
        public readonly int $processed,
        public readonly int $failed,
        public readonly float $percentage,
    ) {}
}
