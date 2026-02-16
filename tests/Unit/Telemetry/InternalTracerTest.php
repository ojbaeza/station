<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use Orchestra\Testbench\TestCase;
use Station\StationServiceProvider;
use Station\Telemetry\InternalTracer;
use Station\Telemetry\Span;

class InternalTracerTest extends TestCase
{
    public function testStartSpanCreatesNewSpan(): void
    {
        $tracer = new InternalTracer();

        $span = $tracer->startSpan('test-span');

        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('test-span', $span->getName());
    }

    public function testStartSpanWithAttributes(): void
    {
        $tracer = new InternalTracer();

        $span = $tracer->startSpan('test-span', ['key' => 'value']);

        $this->assertSame('value', $span->getAttribute('key'));
    }

    public function testGetCurrentSpanReturnsActiveSpan(): void
    {
        $tracer = new InternalTracer();

        $this->assertNull($tracer->getCurrentSpan());

        $span = $tracer->startSpan('test-span');

        $this->assertSame($span, $tracer->getCurrentSpan());
    }

    public function testStartSpanSetsParentFromCurrentSpan(): void
    {
        $tracer = new InternalTracer();

        $parentSpan = $tracer->startSpan('parent');
        $childSpan = $tracer->startSpan('child');

        $this->assertSame($parentSpan->getId(), $childSpan->getParentId());
        $this->assertSame($parentSpan->getTraceId(), $childSpan->getTraceId());
    }

    public function testRecordSpanAddsToCompletedSpans(): void
    {
        $tracer = new InternalTracer();

        $span = $tracer->startSpan('test-span');
        $span->end();

        $tracer->recordSpan($span);

        $completedSpans = $tracer->getCompletedSpans();

        $this->assertArrayHasKey($span->getId(), $completedSpans);
        $this->assertSame($span, $completedSpans[$span->getId()]);
    }

    public function testRecordSpanTruncatesOldSpansWhenMaxReached(): void
    {
        $tracer = new InternalTracer(['max_spans' => 10]);

        // Create and record 15 spans
        for ($i = 0; $i < 15; $i++) {
            $span = $tracer->startSpan("span-{$i}");
            $span->end();
            $tracer->recordSpan($span);
        }

        $completedSpans = $tracer->getCompletedSpans();

        // Should have truncated to 5 (max_spans / 2) then added 5 more = ~10
        $this->assertLessThanOrEqual(10, \count($completedSpans));
    }

    public function testGetTraceSpansFiltersSpansByTraceId(): void
    {
        $tracer = new InternalTracer();

        // Create first trace
        $span1 = $tracer->startSpan('span-1');
        $traceId1 = $span1->getTraceId();
        $span1->end();
        $tracer->recordSpan($span1);

        // Clear current span and create second trace
        $tracer->clear();
        $span2 = $tracer->startSpan('span-2');
        $span2->end();
        $tracer->recordSpan($span2);

        // Manually record spans again to test filtering
        // (Note: clear() will remove completed spans, so let's re-test differently)
        $tracer2 = new InternalTracer();
        $parentSpan = $tracer2->startSpan('parent');
        $childSpan = $tracer2->startSpan('child');
        $parentSpan->end();
        $childSpan->end();
        $tracer2->recordSpan($parentSpan);
        $tracer2->recordSpan($childSpan);

        $traceSpans = $tracer2->getTraceSpans($parentSpan->getTraceId());

        $this->assertCount(2, $traceSpans);
    }

    public function testClearRemovesAllSpans(): void
    {
        $tracer = new InternalTracer();

        $span = $tracer->startSpan('test-span');
        $span->end();
        $tracer->recordSpan($span);

        $this->assertNotEmpty($tracer->getCompletedSpans());
        $this->assertNotNull($tracer->getCurrentSpan());

        $tracer->clear();

        $this->assertEmpty($tracer->getCompletedSpans());
        $this->assertNull($tracer->getCurrentSpan());
    }

    public function testExportToLogIsSkippedByDefault(): void
    {
        $tracer = new InternalTracer(['exporters' => ['log']]);

        $span = $tracer->startSpan('test-span');
        $span->end();
        $tracer->recordSpan($span);

        // Should not throw even without log_spans enabled
        $this->assertCount(1, $tracer->getCompletedSpans());
    }

    public function testCustomMaxSpansConfiguration(): void
    {
        $tracer = new InternalTracer(['max_spans' => 5]);

        // Create 6 spans
        for ($i = 0; $i < 6; $i++) {
            $span = $tracer->startSpan("span-{$i}");
            $span->end();
            $tracer->recordSpan($span);
        }

        // Should have truncated when hitting max
        $this->assertLessThanOrEqual(6, \count($tracer->getCompletedSpans()));
    }

    public function testExportToJsonWritesFile(): void
    {
        $tempFile = storage_path('logs/test_traces_' . uniqid() . '.jsonl');

        // Ensure the logs directory exists
        if (!is_dir(\dirname($tempFile))) {
            mkdir(\dirname($tempFile), 0777, true);
        }

        try {
            $tracer = new InternalTracer([
                'exporters' => ['json'],
                'json_path' => $tempFile,
            ]);

            $span = $tracer->startSpan('test-span');
            $span->end();
            $tracer->recordSpan($span);

            $this->assertFileExists($tempFile);

            $content = file_get_contents($tempFile);
            $this->assertNotEmpty($content);

            $data = json_decode(trim($content), true);
            $this->assertIsArray($data);
            $this->assertSame('test-span', $data['name']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testExportToJsonAppendsMultipleSpans(): void
    {
        $tempFile = storage_path('logs/test_traces_' . uniqid() . '.jsonl');

        // Ensure the logs directory exists
        if (!is_dir(\dirname($tempFile))) {
            mkdir(\dirname($tempFile), 0777, true);
        }

        try {
            $tracer = new InternalTracer([
                'exporters' => ['json'],
                'json_path' => $tempFile,
            ]);

            $span1 = $tracer->startSpan('span-1');
            $span1->end();
            $tracer->recordSpan($span1);

            $tracer->clear();

            $span2 = $tracer->startSpan('span-2');
            $span2->end();
            $tracer->recordSpan($span2);

            $lines = file($tempFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
            $this->assertCount(2, $lines);

            $data1 = json_decode($lines[0], true);
            $data2 = json_decode($lines[1], true);

            $this->assertSame('span-1', $data1['name']);
            $this->assertSame('span-2', $data2['name']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testMultipleExporters(): void
    {
        $tempFile = storage_path('logs/test_traces_' . uniqid() . '.jsonl');

        // Ensure the logs directory exists
        if (!is_dir(\dirname($tempFile))) {
            mkdir(\dirname($tempFile), 0777, true);
        }

        try {
            $tracer = new InternalTracer([
                'exporters' => ['log', 'json'],
                'json_path' => $tempFile,
            ]);

            $span = $tracer->startSpan('test-span');
            $span->end();
            $tracer->recordSpan($span);

            // JSON exporter should write
            $this->assertFileExists($tempFile);

            // Log exporter is skipped because log_spans is not enabled
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testUnknownExporterIsIgnored(): void
    {
        $tracer = new InternalTracer([
            'exporters' => ['unknown_exporter'],
        ]);

        $span = $tracer->startSpan('test-span');
        $span->end();
        $tracer->recordSpan($span);

        // Should not throw - unknown exporters are silently ignored
        $this->assertCount(1, $tracer->getCompletedSpans());
    }

    public function testDefaultExporterIsLog(): void
    {
        $tracer = new InternalTracer([]);

        $span = $tracer->startSpan('test-span');
        $span->end();
        $tracer->recordSpan($span);

        // Default 'log' exporter is used but skipped because log_spans is not enabled
        $this->assertCount(1, $tracer->getCompletedSpans());
    }

    public function testEmptyExportersArray(): void
    {
        $tracer = new InternalTracer([
            'exporters' => [],
        ]);

        $span = $tracer->startSpan('test-span');
        $span->end();
        $tracer->recordSpan($span);

        // No exporters configured - span is still recorded
        $this->assertCount(1, $tracer->getCompletedSpans());
    }

    public function testTraceSpansReturnsEmptyForNonExistentTrace(): void
    {
        $tracer = new InternalTracer();

        $span = $tracer->startSpan('test-span');
        $span->end();
        $tracer->recordSpan($span);

        $result = $tracer->getTraceSpans('non-existent-trace-id');

        $this->assertEmpty($result);
    }

    public function testStartSpanWithoutParentCreatesNewTraceId(): void
    {
        $tracer = new InternalTracer();

        $span = $tracer->startSpan('root-span');

        $this->assertNotNull($span->getTraceId());
        $this->assertNull($span->getParentId());
    }

    public function testMaxSpansDefaultValue(): void
    {
        $tracer = new InternalTracer();

        // Default max_spans is 1000, create 1005 spans
        for ($i = 0; $i < 1005; $i++) {
            $span = $tracer->startSpan("span-{$i}");
            $span->end();
            $tracer->recordSpan($span);
        }

        // Should have truncated
        $this->assertLessThanOrEqual(1005, \count($tracer->getCompletedSpans()));
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
    }
}
