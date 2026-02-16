<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\TimeSeriesPoint;

class TimeSeriesPointTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $point = TimeSeriesPoint::fromArray([]);

        $this->assertSame('', $point->timestamp);
        $this->assertSame(0, $point->jobs_processed);
        $this->assertSame(0, $point->jobs_failed);
        $this->assertSame(0.0, $point->avg_wait_time);
        $this->assertSame(0.0, $point->avg_processing_time);
    }

    public function testFromArrayCastsTypes(): void
    {
        $point = TimeSeriesPoint::fromArray([
            'timestamp' => '2025-06-01T12:00:00Z',
            'jobs_processed' => '50',
            'jobs_failed' => '3',
            'avg_wait_time' => '1.5',
            'avg_processing_time' => '2.3',
        ]);

        $this->assertSame('2025-06-01T12:00:00Z', $point->timestamp);
        $this->assertSame(50, $point->jobs_processed);
        $this->assertSame(3, $point->jobs_failed);
        $this->assertSame(1.5, $point->avg_wait_time);
        $this->assertSame(2.3, $point->avg_processing_time);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $point = new TimeSeriesPoint(
            timestamp: '2025-06-01T12:00:00Z',
            jobs_processed: 100,
            jobs_failed: 5,
            avg_wait_time: 0.8,
            avg_processing_time: 1.2,
        );

        $this->assertSame([
            'timestamp' => '2025-06-01T12:00:00Z',
            'jobs_processed' => 100,
            'jobs_failed' => 5,
            'avg_wait_time' => 0.8,
            'avg_processing_time' => 1.2,
        ], $point->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $point = new TimeSeriesPoint(
            timestamp: 'now',
            jobs_processed: 0,
            jobs_failed: 0,
            avg_wait_time: 0.0,
            avg_processing_time: 0.0,
        );

        $this->assertSame($point->toArray(), $point->jsonSerialize());
    }
}
