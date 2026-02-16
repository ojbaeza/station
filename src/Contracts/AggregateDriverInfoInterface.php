<?php

declare(strict_types=1);

namespace Station\Contracts;

interface AggregateDriverInfoInterface
{
    /**
     * Get driver info aggregated across all discoverable queues.
     *
     * Must return top-level aggregate keys (driver, size, etc.) compatible
     * with getDriverInfo(), plus a 'queues' key with per-queue breakdowns.
     *
     * @return array<string, mixed>
     */
    public function getAllDriverInfo(): array;
}
