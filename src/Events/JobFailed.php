<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\Core\Job;
use Throwable;

final class JobFailed
{
    use Dispatchable;

    public readonly string $jobId;

    public function __construct(
        public readonly Job $job,
        public readonly Throwable $exception,
        public readonly int $attempts,
        public readonly bool $willRetry,
    ) {
        $this->jobId = $job->getId();
    }
}
