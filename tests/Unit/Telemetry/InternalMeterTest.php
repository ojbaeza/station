<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Station\Telemetry\InternalMeter;

class InternalMeterTest extends TestCase
{
    private InternalMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = new InternalMeter();
    }

    public function testIncrementCounterCreatesAndIncrementsCounter(): void
    {
        $this->meter->incrementCounter('test.counter');
        $this->assertSame(1, $this->meter->getCounter('test.counter'));

        $this->meter->incrementCounter('test.counter');
        $this->assertSame(2, $this->meter->getCounter('test.counter'));
    }

    public function testIncrementCounterWithLabels(): void
    {
        $this->meter->incrementCounter('test.counter', ['queue' => 'default']);
        $this->meter->incrementCounter('test.counter', ['queue' => 'high']);
        $this->meter->incrementCounter('test.counter', ['queue' => 'default']);

        $this->assertSame(2, $this->meter->getCounter('test.counter', ['queue' => 'default']));
        $this->assertSame(1, $this->meter->getCounter('test.counter', ['queue' => 'high']));
    }

    public function testIncrementCounterWithCustomValue(): void
    {
        $this->meter->incrementCounter('test.counter', [], 5);
        $this->assertSame(5, $this->meter->getCounter('test.counter'));
    }

    public function testRecordValueSetsGauge(): void
    {
        $this->meter->recordValue('test.gauge', 42.5);
        $this->assertSame(42.5, $this->meter->getGauge('test.gauge'));

        $this->meter->recordValue('test.gauge', 100.0);
        $this->assertSame(100.0, $this->meter->getGauge('test.gauge'));
    }

    public function testRecordValueWithLabels(): void
    {
        $this->meter->recordValue('test.gauge', 10.0, ['host' => 'server1']);
        $this->meter->recordValue('test.gauge', 20.0, ['host' => 'server2']);

        $this->assertSame(10.0, $this->meter->getGauge('test.gauge', ['host' => 'server1']));
        $this->assertSame(20.0, $this->meter->getGauge('test.gauge', ['host' => 'server2']));
    }

    public function testRecordHistogramTracksValues(): void
    {
        $this->meter->recordHistogram('test.duration', 10.0);
        $this->meter->recordHistogram('test.duration', 20.0);
        $this->meter->recordHistogram('test.duration', 30.0);

        $stats = $this->meter->getHistogramStats('test.duration');

        $this->assertSame(3, $stats['count']);
        $this->assertSame(60.0, $stats['sum']);
        $this->assertSame(10.0, $stats['min']);
        $this->assertSame(30.0, $stats['max']);
        $this->assertSame(20.0, $stats['avg']);
    }

    public function testHistogramCalculatesPercentiles(): void
    {
        // Add 100 values from 1 to 100
        for ($i = 1; $i <= 100; $i++) {
            $this->meter->recordHistogram('test.latency', (float) $i);
        }

        $stats = $this->meter->getHistogramStats('test.latency');

        $this->assertEqualsWithDelta(50.0, $stats['p50'], 1.0);
        $this->assertEqualsWithDelta(95.0, $stats['p95'], 1.0);
        $this->assertEqualsWithDelta(99.0, $stats['p99'], 1.0);
    }

    public function testGetHistogramStatsReturnsNullForEmptyHistogram(): void
    {
        $stats = $this->meter->getHistogramStats('nonexistent');
        $this->assertNull($stats);
    }

    public function testGetAllReturnsAllMetrics(): void
    {
        $this->meter->incrementCounter('counter1');
        $this->meter->recordValue('gauge1', 10.0);
        $this->meter->recordHistogram('histogram1', 5.0);

        $all = $this->meter->getAll();

        $this->assertArrayHasKey('counters', $all);
        $this->assertArrayHasKey('gauges', $all);
        $this->assertArrayHasKey('histograms', $all);
    }

    public function testExportPrometheusFormatsCorrectly(): void
    {
        $this->meter->incrementCounter('jobs_processed', ['queue' => 'default'], 100);
        $this->meter->recordValue('queue_size', 42.0, ['queue' => 'default']);

        $output = $this->meter->exportPrometheus();

        $this->assertStringContainsString('jobs_processed{queue="default"} 100', $output);
        $this->assertStringContainsString('queue_size{queue="default"} 42', $output);
    }

    public function testClearRemovesAllMetrics(): void
    {
        $this->meter->incrementCounter('counter');
        $this->meter->recordValue('gauge', 10.0);
        $this->meter->recordHistogram('histogram', 5.0);

        $this->meter->clear();

        $this->assertSame(0, $this->meter->getCounter('counter'));
        $this->assertNull($this->meter->getGauge('gauge'));
        $this->assertNull($this->meter->getHistogramStats('histogram'));
    }

    public function testHistogramTruncatesWhenMaxReached(): void
    {
        $meter = new InternalMeter(['histogram_max_values' => 10]);

        // Add 15 values
        for ($i = 1; $i <= 15; $i++) {
            $meter->recordHistogram('test', (float) $i);
        }

        $stats = $meter->getHistogramStats('test');

        // Should truncate to last 10 values (6-15)
        $this->assertSame(10, $stats['count']);
        $this->assertSame(6.0, $stats['min']);
        $this->assertSame(15.0, $stats['max']);
    }

    public function testGetCounterReturnsZeroForNonExistent(): void
    {
        $this->assertSame(0, $this->meter->getCounter('nonexistent'));
    }

    public function testGetGaugeReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->meter->getGauge('nonexistent'));
    }

    public function testLabelOrderDoesNotAffectKey(): void
    {
        $this->meter->incrementCounter('test', ['b' => '2', 'a' => '1']);

        // Same labels in different order should give same result
        $this->assertSame(1, $this->meter->getCounter('test', ['a' => '1', 'b' => '2']));
    }

    public function testExportPrometheusWithoutLabels(): void
    {
        $this->meter->incrementCounter('simple_counter');
        $this->meter->recordValue('simple_gauge', 42.0);

        $output = $this->meter->exportPrometheus();

        $this->assertStringContainsString('simple_counter 1', $output);
        $this->assertStringContainsString('simple_gauge 42', $output);
    }

    public function testExportPrometheusWithHistograms(): void
    {
        $this->meter->recordHistogram('request_duration', 0.5);
        $this->meter->recordHistogram('request_duration', 1.0);

        $output = $this->meter->exportPrometheus();

        $this->assertStringContainsString('request_duration_count 2', $output);
        $this->assertStringContainsString('request_duration_sum 1.5', $output);
    }

    public function testRecordHistogramWithLabels(): void
    {
        $this->meter->recordHistogram('duration', 1.0, ['queue' => 'default']);
        $this->meter->recordHistogram('duration', 2.0, ['queue' => 'default']);
        $this->meter->recordHistogram('duration', 5.0, ['queue' => 'high']);

        $defaultStats = $this->meter->getHistogramStats('duration', ['queue' => 'default']);
        $highStats = $this->meter->getHistogramStats('duration', ['queue' => 'high']);

        $this->assertSame(2, $defaultStats['count']);
        $this->assertSame(1, $highStats['count']);
    }
}
