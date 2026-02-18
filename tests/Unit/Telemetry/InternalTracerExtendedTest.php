<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use Station\StationServiceProvider;
use Station\Telemetry\InternalTracer;

/**
 * Extended tests for InternalTracer covering:
 * - exportToLog when log_spans is enabled (exercises Log facade call)
 * - exportToJson with path outside storage directory (path traversal prevention)
 * - exportToLog with custom log channel
 */
class InternalTracerExtendedTest extends TestCase
{
    public function testExportToLogWhenLogSpansEnabled(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('stack')
            ->andReturnSelf();

        Log::shouldReceive('debug')
            ->once()
            ->with('Span completed', Mockery::on(static fn($context) => isset($context['span'])
                    && \is_array($context['span'])
                    && $context['span']['name'] === 'logged-span'));

        $tracer = new InternalTracer([
            'exporters' => ['log'],
            'log_spans' => true,
        ]);

        $span = $tracer->startSpan('logged-span');
        $span->end();
        $tracer->recordSpan($span);

        $this->assertCount(1, $tracer->getCompletedSpans());
    }

    public function testExportToLogWithCustomChannel(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('station')
            ->andReturnSelf();

        Log::shouldReceive('debug')
            ->once()
            ->with('Span completed', Mockery::type('array'));

        $tracer = new InternalTracer([
            'exporters' => ['log'],
            'log_spans' => true,
            'log_channel' => 'station',
        ]);

        $span = $tracer->startSpan('custom-channel-span');
        $span->end();
        $tracer->recordSpan($span);

        $this->assertCount(1, $tracer->getCompletedSpans());
    }

    public function testExportToJsonRefusesPathOutsideStorageDirectory(): void
    {
        // Path traversal attempt - path outside storage directory
        Log::shouldReceive('warning')
            ->once()
            ->with('Station: Refusing to write traces to path outside storage directory', Mockery::type('array'));

        $tracer = new InternalTracer([
            'exporters' => ['json'],
            'json_path' => '/tmp/evil_traces.jsonl',
        ]);

        $span = $tracer->startSpan('traversal-span');
        $span->end();
        $tracer->recordSpan($span);

        // Span should still be recorded internally
        $this->assertCount(1, $tracer->getCompletedSpans());

        // File should NOT be created outside storage
        $this->assertFileDoesNotExist('/tmp/evil_traces.jsonl');
    }

    public function testExportToLogSkipsWhenLogSpansDisabled(): void
    {
        // Log::channel should NOT be called when log_spans is false
        Log::shouldReceive('channel')->never();

        $tracer = new InternalTracer([
            'exporters' => ['log'],
            'log_spans' => false,
        ]);

        $span = $tracer->startSpan('not-logged-span');
        $span->end();
        $tracer->recordSpan($span);

        $this->assertCount(1, $tracer->getCompletedSpans());
    }

    public function testExportToLogAndJsonCombined(): void
    {
        $tempFile = storage_path('logs/test_combined_traces_' . uniqid() . '.jsonl');

        // Ensure the logs directory exists
        if (!is_dir(\dirname($tempFile))) {
            mkdir(\dirname($tempFile), 0777, true);
        }

        try {
            Log::shouldReceive('channel')
                ->once()
                ->with('stack')
                ->andReturnSelf();

            Log::shouldReceive('debug')
                ->once()
                ->with('Span completed', Mockery::type('array'));

            $tracer = new InternalTracer([
                'exporters' => ['log', 'json'],
                'log_spans' => true,
                'json_path' => $tempFile,
            ]);

            $span = $tracer->startSpan('dual-export-span');
            $span->end();
            $tracer->recordSpan($span);

            // JSON file should be created
            $this->assertFileExists($tempFile);
            $content = file_get_contents($tempFile);
            $data = json_decode(trim($content), true);
            $this->assertSame('dual-export-span', $data['name']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
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
