<?php

declare(strict_types=1);

namespace Station\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Station\Events\JobCompleted;
use Station\Events\JobFailed;
use Station\Events\JobStarted;
use Station\Events\WorkflowCompleted;
use Station\Events\WorkflowFailed;
use Station\Events\WorkflowStarted;
use Throwable;

/**
 * Manages OpenTelemetry integration for Station.
 *
 * This class provides tracing and metrics collection compatible with OpenTelemetry.
 * When OpenTelemetry PHP SDK is installed, it integrates natively.
 * Otherwise, it provides a compatible interface that can export to various backends.
 */
final class TelemetryManager
{
    private const MAX_ACTIVE_SPANS = 1000;

    private const PRUNE_EVERY = 100;

    /** Spans older than 10 minutes that are ended are safe to remove */
    private const ENDED_SPAN_TTL_SECONDS = 600;

    /** Spans older than 1 hour that are still in-flight are assumed leaked */
    private const INFLIGHT_SPAN_TTL_SECONDS = 3600;

    private bool $enabled;

    /** @var array<string, Span> Active spans by ID */
    private array $activeSpans = [];

    private ?TracerInterface $tracer = null;

    private ?MeterInterface $meter = null;

    /** Counter for pruning frequency control */
    private int $spanStartCount = 0;

    public function __construct(
        private readonly Dispatcher $events,
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {
        $this->enabled = $config['enabled'] ?? false;

        if ($this->enabled) {
            $this->initialize();
        }
    }

    /**
     * Create a custom span.
     *
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, array $attributes = []): ?Span
    {
        if (!$this->enabled || $this->tracer === null) {
            return null;
        }

        return $this->tracer->startSpan($name, $attributes);
    }

    /**
     * Record a metric.
     *
     * @param array<string, string> $labels
     */
    public function recordMetric(string $name, float $value, array $labels = []): void
    {
        if (!$this->enabled || $this->meter === null) {
            return;
        }

        $this->meter->recordValue($name, $value, $labels);
    }

    /**
     * Increment a counter.
     *
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, array $labels = [], int $value = 1): void
    {
        if (!$this->enabled || $this->meter === null) {
            return;
        }

        $this->meter->incrementCounter($name, $labels, $value);
    }

    /**
     * Record a histogram value.
     *
     * @param array<string, string> $labels
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void
    {
        if (!$this->enabled || $this->meter === null) {
            return;
        }

        $this->meter->recordHistogram($name, $value, $labels);
    }

    /**
     * Check if telemetry is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the tracer.
     */
    public function getTracer(): ?TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Get the meter.
     */
    public function getMeter(): ?MeterInterface
    {
        return $this->meter;
    }

    /**
     * Get the count of active spans (for testing/monitoring).
     */
    public function getActiveSpanCount(): int
    {
        return \count($this->activeSpans);
    }

    /**
     * Initialize telemetry components.
     */
    private function initialize(): void
    {
        $this->tracer = $this->createTracer();
        $this->meter = $this->createMeter();

        $this->registerEventListeners();
    }

    /**
     * Create the tracer instance.
     */
    private function createTracer(): TracerInterface
    {
        // Check if OpenTelemetry is available
        if (class_exists('\OpenTelemetry\API\Trace\TracerProvider')) {
            return new OpenTelemetryTracer($this->config);
        }

        // Fall back to our internal tracer
        return new InternalTracer($this->config);
    }

    /**
     * Create the meter instance.
     */
    private function createMeter(): MeterInterface
    {
        // Check if OpenTelemetry is available
        if (class_exists('\OpenTelemetry\API\Metrics\MeterProvider')) {
            return new OpenTelemetryMeter($this->config);
        }

        // Fall back to our internal meter
        return new InternalMeter($this->config);
    }

    /**
     * Register event listeners for automatic instrumentation.
     */
    private function registerEventListeners(): void
    {
        $this->events->listen(JobStarted::class, function (JobStarted $event): void {
            $this->startJobSpan($event);
        });

        $this->events->listen(JobCompleted::class, function (JobCompleted $event): void {
            $this->endJobSpan($event->jobId, 'completed');
        });

        $this->events->listen(JobFailed::class, function (JobFailed $event): void {
            $this->endJobSpan($event->jobId, 'failed', $event->exception);
        });

        $this->events->listen(WorkflowStarted::class, function (WorkflowStarted $event): void {
            $this->startWorkflowSpan($event);
        });

        $this->events->listen(WorkflowCompleted::class, function (WorkflowCompleted $event): void {
            $this->endWorkflowSpan($event->instance->getId(), 'completed');
        });

        $this->events->listen(WorkflowFailed::class, function (WorkflowFailed $event): void {
            $this->endWorkflowSpan($event->instance->getId(), 'failed');
        });
    }

    /**
     * Start a span for a job.
     */
    private function startJobSpan(JobStarted $event): void
    {
        $tracer = $this->tracer;

        if (!$this->enabled || $tracer === null) {
            return;
        }

        $this->spanStartCount++;
        $this->pruneStaleSpans();

        $span = $tracer->startSpan('job.process', [
            'job.id' => $event->jobId,
            'job.class' => $event->jobClass,
            'job.queue' => $event->queue,
            'job.connection' => $event->connection,
        ]);

        $this->activeSpans[$event->jobId] = $span;

        // Record metrics
        $this->meter?->incrementCounter('station.jobs.started', [
            'queue' => $event->queue,
            'job_class' => $event->jobClass,
        ]);
    }

    /**
     * End a job span.
     */
    private function endJobSpan(string $jobId, string $status, ?Throwable $exception = null): void
    {
        if (!$this->enabled || !isset($this->activeSpans[$jobId])) {
            return;
        }

        $span = $this->activeSpans[$jobId];

        $span->setAttribute('job.status', $status);

        if ($exception !== null) {
            $span->recordException($exception);
            $span->setStatus('error', $exception->getMessage());
        } else {
            $span->setStatus('ok');
        }

        $span->end();

        unset($this->activeSpans[$jobId]);

        // Record metrics
        $metricName = $status === 'completed'
            ? 'station.jobs.completed'
            : 'station.jobs.failed';

        $this->meter?->incrementCounter($metricName, [
            'queue' => $span->getAttribute('job.queue'),
            'job_class' => $span->getAttribute('job.class'),
        ]);
    }

    /**
     * Start a span for a workflow.
     */
    private function startWorkflowSpan(WorkflowStarted $event): void
    {
        if (!$this->enabled || $this->tracer === null) {
            return;
        }

        $instance = $event->instance;

        $span = $this->tracer->startSpan('workflow.execute', [
            'workflow.id' => $instance->getId(),
            'workflow.name' => $instance->getDefinitionName(),
        ]);

        $this->activeSpans['workflow:' . $instance->getId()] = $span;

        $this->meter?->incrementCounter('station.workflows.started', [
            'workflow' => $instance->getDefinitionName(),
        ]);
    }

    /**
     * End a workflow span.
     */
    private function endWorkflowSpan(string $instanceId, string $status): void
    {
        $key = 'workflow:' . $instanceId;

        if (!$this->enabled || !isset($this->activeSpans[$key])) {
            return;
        }

        $span = $this->activeSpans[$key];

        $span->setAttribute('workflow.status', $status);
        $span->setStatus($status === 'completed' ? 'ok' : 'error');
        $span->end();

        unset($this->activeSpans[$key]);

        $metricName = $status === 'completed'
            ? 'station.workflows.completed'
            : 'station.workflows.failed';

        $this->meter?->incrementCounter($metricName, [
            'workflow' => $span->getAttribute('workflow.name'),
        ]);
    }

    /**
     * Prune stale spans to prevent memory leaks.
     *
     * Runs every PRUNE_EVERY startJobSpan calls to amortize cost.
     */
    private function pruneStaleSpans(): void
    {
        if ($this->spanStartCount % self::PRUNE_EVERY !== 0) {
            return;
        }

        $now = time();

        foreach ($this->activeSpans as $key => $span) {
            $startTimestamp = $span->getStartTime()->getTimestamp();

            // Remove ended spans older than 10 minutes (shouldn't be here, but defensive)
            if ($span->hasEnded() && ($now - $startTimestamp) > self::ENDED_SPAN_TTL_SECONDS) {
                unset($this->activeSpans[$key]);

                continue;
            }

            // Remove in-flight spans older than 1 hour (assumed leaked)
            if (($now - $startTimestamp) > self::INFLIGHT_SPAN_TTL_SECONDS) {
                unset($this->activeSpans[$key]);
            }
        }

        // Hard cap: if still over limit, evict oldest
        if (\count($this->activeSpans) > self::MAX_ACTIVE_SPANS) {
            // Sort by start time and keep only the newest MAX_ACTIVE_SPANS
            uasort($this->activeSpans, static fn(Span $a, Span $b) => $b->getStartTime()->getTimestamp() <=> $a->getStartTime()->getTimestamp());
            $this->activeSpans = \array_slice($this->activeSpans, 0, self::MAX_ACTIVE_SPANS, true);
        }
    }
}
