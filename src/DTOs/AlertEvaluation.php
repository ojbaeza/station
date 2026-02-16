<?php

declare(strict_types=1);

namespace Station\DTOs;

use Station\Enums\AlertSeverity;

final readonly class AlertEvaluation
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $message,
        public AlertSeverity $severity,
        public array $context = [],
    ) {}
}
