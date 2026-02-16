<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\MetricsAggregation;

class MetricsAggregationTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // toArray — conditional throughput
    // ──────────────────────────────────────────────────────────────

    public function testToArrayOmitsThroughputWhenNull(): void
    {
        $agg = new MetricsAggregation(
            jobs_processed: 100,
            jobs_failed: 5,
            avg_processing_time: 1.5,
            avg_wait_time: 0.3,
            failure_rate: 5.0,
        );

        $array = $agg->toArray();

        $this->assertArrayNotHasKey('throughput', $array);
        $this->assertSame(100, $array['jobs_processed']);
        $this->assertSame(5, $array['jobs_failed']);
        $this->assertSame(1.5, $array['avg_processing_time']);
    }

    public function testToArrayIncludesThroughputWhenPresent(): void
    {
        $agg = new MetricsAggregation(
            jobs_processed: 100,
            jobs_failed: 5,
            avg_processing_time: 1.5,
            avg_wait_time: 0.3,
            failure_rate: 5.0,
            throughput: 42.5,
        );

        $array = $agg->toArray();

        $this->assertSame(42.5, $array['throughput']);
    }

    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayDefaults(): void
    {
        $agg = MetricsAggregation::fromArray([]);

        $this->assertSame(0, $agg->jobs_processed);
        $this->assertSame(0, $agg->jobs_failed);
        $this->assertSame(0.0, $agg->avg_processing_time);
        $this->assertSame(0.0, $agg->avg_wait_time);
        $this->assertSame(0.0, $agg->failure_rate);
        $this->assertNull($agg->throughput);
    }

    public function testFromArrayWithThroughput(): void
    {
        $agg = MetricsAggregation::fromArray(['throughput' => 10.5]);

        $this->assertSame(10.5, $agg->throughput);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $agg = new MetricsAggregation(
            jobs_processed: 50,
            jobs_failed: 2,
            avg_processing_time: 1.0,
            avg_wait_time: 0.5,
            failure_rate: 4.0,
            throughput: 25.0,
        );

        $this->assertSame($agg->toArray(), $agg->jsonSerialize());
    }
}
