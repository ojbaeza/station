<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Batch;

final class BatchCreated
{
    use Dispatchable;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly Batch $batch,
        public readonly int $totalJobs,
        public readonly array $options,
    ) {}
}
