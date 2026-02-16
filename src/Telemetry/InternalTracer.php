<?php

declare(strict_types=1);

namespace Station\Telemetry;

use Illuminate\Support\Facades\Log;

/**
 * Internal tracer implementation when OpenTelemetry is not available.
 *
 * Stores spans in memory and can optionally export to logs or other backends.
 */
final class InternalTracer implements TracerInterface
{
    private ?Span $currentSpan = null;

    /** @var array<string, Span> Completed spans by ID */
    private array $completedSpans = [];

    private int $maxSpans = 1000;

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {
        $this->maxSpans = $config['max_spans'] ?? 1000;
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

        $span = new Span($name, $traceId, $parentId);
        $span->setAttributes($attributes);

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
     * Record a completed span.
     */
    public function recordSpan(Span $span): void
    {
        if (\count($this->completedSpans) >= $this->maxSpans) {
            // Remove oldest spans
            $this->completedSpans = \array_slice($this->completedSpans, -(int) ($this->maxSpans / 2), null, true);
        }

        $this->completedSpans[$span->getId()] = $span;

        // Export if configured
        $this->exportSpan($span);
    }

    /**
     * Get completed spans.
     *
     * @return array<string, Span>
     */
    public function getCompletedSpans(): array
    {
        return $this->completedSpans;
    }

    /**
     * Get spans for a trace.
     *
     * @return array<Span>
     */
    public function getTraceSpans(string $traceId): array
    {
        return array_filter(
            $this->completedSpans,
            static fn(Span $span) => $span->getTraceId() === $traceId,
        );
    }

    /**
     * Clear completed spans.
     */
    public function clear(): void
    {
        $this->completedSpans = [];
        $this->currentSpan = null;
    }

    /**
     * Export a span to configured backend.
     */
    private function exportSpan(Span $span): void
    {
        $exporters = $this->config['exporters'] ?? ['log'];

        foreach ($exporters as $exporter) {
            match ($exporter) {
                'log' => $this->exportToLog($span),
                'json' => $this->exportToJson($span),
                default => null,
            };
        }
    }

    /**
     * Export span to log.
     */
    private function exportToLog(Span $span): void
    {
        if (!($this->config['log_spans'] ?? false)) {
            return;
        }

        Log::channel($this->config['log_channel'] ?? 'stack')->debug('Span completed', [
            'span' => $span->toArray(),
        ]);
    }

    /**
     * Export span to JSON file.
     */
    private function exportToJson(Span $span): void
    {
        $path = $this->config['json_path'] ?? storage_path('logs/traces.jsonl');

        // Validate path is within storage directory to prevent path traversal
        $resolvedDir = realpath(\dirname($path));
        $storagePath = realpath(storage_path());

        if ($resolvedDir === false || $storagePath === false || !str_starts_with($resolvedDir, $storagePath)) {
            Log::warning('Station: Refusing to write traces to path outside storage directory', ['path' => $path]);

            return;
        }

        file_put_contents(
            $path,
            json_encode($span->toArray()) . "\n",
            FILE_APPEND|LOCK_EX,
        );
    }
}
