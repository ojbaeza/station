<?php

declare(strict_types=1);

namespace Station\Telemetry;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Represents a trace span.
 */
final class Span
{
    private string $id;

    private ?string $parentId;

    private string $traceId;

    private string $status = 'unset';

    private ?string $statusMessage = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<array{timestamp: string, name: string, attributes: array<string, mixed>}> */
    private array $events = [];

    private DateTimeImmutable $startTime;

    private ?DateTimeImmutable $endTime = null;

    public function __construct(
        private readonly string $name,
        ?string $traceId = null,
        ?string $parentId = null,
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->traceId = $traceId ?? Uuid::uuid4()->toString();
        $this->parentId = $parentId;
        $this->startTime = new DateTimeImmutable();
    }

    /**
     * Get the span ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the trace ID.
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Get the parent span ID.
     */
    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    /**
     * Get the span name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set an attribute.
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Set multiple attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * Get an attribute.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get all attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set the span status.
     */
    public function setStatus(string $status, ?string $message = null): self
    {
        $this->status = $status;
        $this->statusMessage = $message;

        return $this;
    }

    /**
     * Get the span status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get the status message.
     */
    public function getStatusMessage(): ?string
    {
        return $this->statusMessage;
    }

    /**
     * Add an event to the span.
     *
     * @param array<string, mixed> $attributes
     */
    public function addEvent(string $name, array $attributes = []): self
    {
        $this->events[] = [
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
            'name' => $name,
            'attributes' => $attributes,
        ];

        return $this;
    }

    /**
     * Record an exception.
     */
    public function recordException(Throwable $exception): self
    {
        return $this->addEvent('exception', [
            'exception.type' => $exception::class,
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * End the span.
     */
    public function end(): void
    {
        if ($this->endTime === null) {
            $this->endTime = new DateTimeImmutable();
        }
    }

    /**
     * Check if the span has ended.
     */
    public function hasEnded(): bool
    {
        return $this->endTime !== null;
    }

    /**
     * Get the start time.
     */
    public function getStartTime(): DateTimeImmutable
    {
        return $this->startTime;
    }

    /**
     * Get the end time.
     */
    public function getEndTime(): ?DateTimeImmutable
    {
        return $this->endTime;
    }

    /**
     * Get the duration in milliseconds.
     */
    public function getDurationMs(): ?float
    {
        if ($this->endTime === null) {
            return null;
        }

        $start = (float) $this->startTime->format('U.u');
        $end = (float) $this->endTime->format('U.u');

        return ($end - $start) * 1000;
    }

    /**
     * Convert to array (for export).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->id,
            'parent_span_id' => $this->parentId,
            'name' => $this->name,
            'status' => $this->status,
            'status_message' => $this->statusMessage,
            'attributes' => $this->attributes,
            'events' => $this->events,
            'start_time' => $this->startTime->format('Y-m-d\TH:i:s.uP'),
            'end_time' => $this->endTime?->format('Y-m-d\TH:i:s.uP'),
            'duration_ms' => $this->getDurationMs(),
        ];
    }
}
