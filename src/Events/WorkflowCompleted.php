<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Station\Workflows\WorkflowInstance;

final class WorkflowCompleted
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly WorkflowInstance $instance,
    ) {}
}
