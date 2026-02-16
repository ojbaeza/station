<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Station\Telemetry\OpenTelemetryTracer;
use Station\Telemetry\Span;
use Station\Telemetry\TracerInterface;
use stdClass;

class OpenTelemetryTracerTest extends TestCase
{
    public function testImplementsTracerInterface(): void
    {
        $tracer = new OpenTelemetryTracer();

        $this->assertInstanceOf(TracerInterface::class, $tracer);
    }

    public function testStartSpanReturnsSpanInstance(): void
    {
        $tracer = new OpenTelemetryTracer();

        $span = $tracer->startSpan('test-operation');

        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('test-operation', $span->getName());
    }

    public function testStartSpanWithAttributes(): void
    {
        $tracer = new OpenTelemetryTracer();
        $attributes = [
            'job.name' => 'ProcessOrder',
            'job.queue' => 'orders',
            'job.attempts' => 1,
        ];

        $span = $tracer->startSpan('process-job', $attributes);

        $this->assertInstanceOf(Span::class, $span);
    }

    public function testGetCurrentSpanReturnsNullInitially(): void
    {
        $tracer = new OpenTelemetryTracer();

        $this->assertNull($tracer->getCurrentSpan());
    }

    public function testGetCurrentSpanReturnsLastStartedSpan(): void
    {
        $tracer = new OpenTelemetryTracer();

        $span = $tracer->startSpan('test-span');

        $this->assertSame($span, $tracer->getCurrentSpan());
    }

    public function testStartMultipleSpansUpdatesCurrentSpan(): void
    {
        $tracer = new OpenTelemetryTracer();

        $span1 = $tracer->startSpan('first-span');
        $this->assertSame($span1, $tracer->getCurrentSpan());

        $span2 = $tracer->startSpan('second-span');
        $this->assertSame($span2, $tracer->getCurrentSpan());

        $this->assertNotSame($span1, $span2);
    }

    public function testChildSpanInheritsTraceId(): void
    {
        $tracer = new OpenTelemetryTracer();

        $parentSpan = $tracer->startSpan('parent-operation');
        $childSpan = $tracer->startSpan('child-operation');

        // Child span should reference parent's trace ID
        $this->assertSame($parentSpan->getTraceId(), $childSpan->getTraceId());
    }

    public function testChildSpanHasParentId(): void
    {
        $tracer = new OpenTelemetryTracer();

        $parentSpan = $tracer->startSpan('parent-operation');
        $childSpan = $tracer->startSpan('child-operation');

        $this->assertSame($parentSpan->getId(), $childSpan->getParentId());
    }

    public function testConstructorWithConfig(): void
    {
        $config = [
            'service_name' => 'test-service',
            'service_version' => '2.0.0',
        ];

        $tracer = new OpenTelemetryTracer($config);

        $this->assertInstanceOf(OpenTelemetryTracer::class, $tracer);
    }

    public function testConstructorWithEmptyConfig(): void
    {
        $tracer = new OpenTelemetryTracer([]);

        $this->assertInstanceOf(OpenTelemetryTracer::class, $tracer);
    }

    public function testStartSpanWithEmptyAttributes(): void
    {
        $tracer = new OpenTelemetryTracer();

        $span = $tracer->startSpan('empty-attributes', []);

        $this->assertInstanceOf(Span::class, $span);
    }

    public function testStartSpanWithSpecialCharacters(): void
    {
        $tracer = new OpenTelemetryTracer();

        $span = $tracer->startSpan('special.operation/name:v2');

        $this->assertSame('special.operation/name:v2', $span->getName());
    }

    public function testDeepNestedSpans(): void
    {
        $tracer = new OpenTelemetryTracer();

        $span1 = $tracer->startSpan('level-1');
        $span2 = $tracer->startSpan('level-2');
        $span3 = $tracer->startSpan('level-3');
        $span4 = $tracer->startSpan('level-4');

        // Each child should have the previous span as parent
        $this->assertSame($span3->getId(), $span4->getParentId());
        $this->assertSame($span2->getId(), $span3->getParentId());
        $this->assertSame($span1->getId(), $span2->getParentId());

        // All should share the same trace ID
        $this->assertSame($span1->getTraceId(), $span4->getTraceId());
    }

    public function testStartSpanWithMockedTracer(): void
    {
        $tracer = new OpenTelemetryTracer();

        // Use stdClass to track calls (anonymous classes can't use references)
        $tracker = new stdClass();
        $tracker->spanBuilderCalls = 0;
        $tracker->setAttributeCalls = 0;
        $tracker->startSpanCalls = 0;

        // Create a mock OTel span
        $mockOtelSpan = new class {
            public function end(): void {}
        };

        // Create a mock span builder
        $mockSpanBuilder = new class($tracker, $mockOtelSpan) {
            private stdClass $tracker;

            private object $span;

            public function __construct(stdClass $tracker, object $span)
            {
                $this->tracker = $tracker;
                $this->span = $span;
            }

            public function setAttribute(string $key, mixed $value): self
            {
                $this->tracker->setAttributeCalls++;

                return $this;
            }

            public function startSpan(): object
            {
                $this->tracker->startSpanCalls++;

                return $this->span;
            }
        };

        // Create a mock tracer
        $mockTracerObj = new class($tracker, $mockSpanBuilder) {
            private stdClass $tracker;

            private object $builder;

            public function __construct(stdClass $tracker, object $builder)
            {
                $this->tracker = $tracker;
                $this->builder = $builder;
            }

            public function spanBuilder(string $name): object
            {
                $this->tracker->spanBuilderCalls++;

                return $this->builder;
            }
        };

        // Inject the mock tracer via reflection
        $reflection = new ReflectionClass($tracer);
        $property = $reflection->getProperty('tracer');
        $property->setValue($tracer, $mockTracerObj);

        // Now test startSpan with attributes
        $span = $tracer->startSpan('test-operation', [
            'job.name' => 'ProcessOrder',
            'job.queue' => 'orders',
        ]);

        $this->assertSame(1, $tracker->spanBuilderCalls);
        $this->assertSame(2, $tracker->setAttributeCalls); // Two attributes
        $this->assertSame(1, $tracker->startSpanCalls);
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('test-operation', $span->getName());
    }

    public function testStartSpanStoresOtelSpanInAttributes(): void
    {
        $tracer = new OpenTelemetryTracer();

        // Create a mock OTel span with a marker
        $mockOtelSpan = new class {
            public string $marker = 'otel-span-instance';

            public function end(): void {}
        };

        // Create a mock span builder
        $mockSpanBuilder = new class($mockOtelSpan) {
            private object $span;

            public function __construct(object $span)
            {
                $this->span = $span;
            }

            public function setAttribute(string $key, mixed $value): self
            {
                return $this;
            }

            public function startSpan(): object
            {
                return $this->span;
            }
        };

        // Create a mock tracer
        $mockTracerObj = new class($mockSpanBuilder) {
            private object $builder;

            public function __construct(object $builder)
            {
                $this->builder = $builder;
            }

            public function spanBuilder(string $name): object
            {
                return $this->builder;
            }
        };

        // Inject the mock tracer via reflection
        $reflection = new ReflectionClass($tracer);
        $property = $reflection->getProperty('tracer');
        $property->setValue($tracer, $mockTracerObj);

        // Start a span
        $span = $tracer->startSpan('test-operation');

        // The OTel span should be stored in attributes
        $otelSpan = $span->getAttribute('_otel_span');
        $this->assertNotNull($otelSpan);
        $this->assertSame('otel-span-instance', $otelSpan->marker);
    }

    public function testStartSpanWithNoAttributesAndMockedTracer(): void
    {
        $tracer = new OpenTelemetryTracer();

        $tracker = new stdClass();
        $tracker->setAttributeCalls = 0;

        // Create a mock span builder that tracks setAttribute calls
        $mockSpanBuilder = new class($tracker) {
            private stdClass $tracker;

            public function __construct(stdClass $tracker)
            {
                $this->tracker = $tracker;
            }

            public function setAttribute(string $key, mixed $value): self
            {
                $this->tracker->setAttributeCalls++;

                return $this;
            }

            public function startSpan(): object
            {
                return new class {
                    public function end(): void {}
                };
            }
        };

        // Create a mock tracer
        $mockTracerObj = new class($mockSpanBuilder) {
            private object $builder;

            public function __construct(object $builder)
            {
                $this->builder = $builder;
            }

            public function spanBuilder(string $name): object
            {
                return $this->builder;
            }
        };

        // Inject the mock tracer via reflection
        $reflection = new ReflectionClass($tracer);
        $property = $reflection->getProperty('tracer');
        $property->setValue($tracer, $mockTracerObj);

        // Start a span with empty attributes
        $span = $tracer->startSpan('no-attributes', []);

        // No setAttribute calls should happen (empty array doesn't iterate)
        $this->assertSame(0, $tracker->setAttributeCalls);
        $this->assertInstanceOf(Span::class, $span);
    }
}
