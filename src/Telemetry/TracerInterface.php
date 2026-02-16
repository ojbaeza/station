<?php

declare(strict_types=1);

namespace Station\Telemetry;

/**
 * Interface for span tracing.
 */
interface TracerInterface
{
    /**
     * Start a new span.
     *
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, array $attributes = []): Span;

    /**
     * Get the current active span.
     */
    public function getCurrentSpan(): ?Span;
}
