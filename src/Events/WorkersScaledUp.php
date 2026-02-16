<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WorkersScaledUp
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $queue,
        public readonly int $previousCount,
        public readonly int $newCount,
    ) {}

    /**
     * Get the number of workers added.
     */
    public function getAddedCount(): int
    {
        return $this->newCount - $this->previousCount;
    }
}
