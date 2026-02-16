<?php

declare(strict_types=1);

namespace Station\Telemetry;

use OpenTelemetry\API\Globals;

/**
 * OpenTelemetry tracer wrapper.
 *
 * Uses the OpenTelemetry PHP SDK when available.
 */
final class OpenTelemetryTracer implements TracerInterface
{
    private mixed $tracer = null;

    private ?Span $currentSpan = null;

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {
        $this->initialize();
    }

    /**
     * Start a new span.
     *
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, array $attributes = []): Span
    {
        $parentId = $this->currentSpan?->getId();
        $traceId = $this->currentSpan?->getTraceId();

        // Create our Span wrapper
        $span = new Span($name, $traceId, $parentId);
        $span->setAttributes($attributes);

        // Also create the OpenTelemetry span if available
        if ($this->tracer !== null) {
            $spanBuilder = $this->tracer->spanBuilder($name);

            foreach ($attributes as $key => $value) {
                $spanBuilder->setAttribute($key, $value);
            }

            $otelSpan = $spanBuilder->startSpan();

            // Store the OTel span for later use
            $span->setAttribute('_otel_span', $otelSpan);
        }

        $this->currentSpan = $span;

        return $span;
    }

    /**
     * Get the current active span.
     */
    public function getCurrentSpan(): ?Span
    {
        return $this->currentSpan;
    }

    /**
     * Initialize the OpenTelemetry tracer.
     */
    private function initialize(): void
    {
        // Check if OpenTelemetry is available
        if (!class_exists('\OpenTelemetry\SDK\Trace\TracerProvider')) {
            return;
        }

        // Get or create the tracer
        $tracerProvider = Globals::tracerProvider();
        $this->tracer = $tracerProvider->getTracer(
            $this->config['service_name'] ?? 'station',
            $this->config['service_version'] ?? '0.1.0',
        );
    }
}
