<?php

declare(strict_types=1);

namespace Station\Telemetry;

/**
 * Interface for metrics collection.
 */
interface MeterInterface
{
    /**
     * Increment a counter.
     *
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, array $labels = [], int $value = 1): void;

    /**
     * Record a value (gauge).
     *
     * @param array<string, string> $labels
     */
    public function recordValue(string $name, float $value, array $labels = []): void;

    /**
     * Record a histogram value.
     *
     * @param array<string, string> $labels
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void;
}
