<?php

declare(strict_types=1);

namespace Station\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Station\DTOs\AlertRecord;

final class AlertTriggered
{
    use Dispatchable;

    public function __construct(
        public readonly AlertRecord $alert,
    ) {}
}
