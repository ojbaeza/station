<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Batch;
use Throwable;

final class BatchFailed
{
    use Dispatchable;

    /**
     * @param array<int, string> $failedJobs
     */
    public function __construct(
        public readonly Batch $batch,
        public readonly array $failedJobs,
        public readonly ?Throwable $exception,
    ) {}
}
