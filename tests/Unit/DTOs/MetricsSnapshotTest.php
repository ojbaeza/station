<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\MetricsSnapshot;

class MetricsSnapshotTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $snapshot = MetricsSnapshot::fromArray([]);

        $this->assertSame(0.0, $snapshot->jobs_per_minute);
        $this->assertSame(0, $snapshot->jobs_processed_last_hour);
        $this->assertSame(0, $snapshot->failed_jobs);
        $this->assertSame(0.0, $snapshot->failed_rate_percent);
        $this->assertSame(0, $snapshot->average_processing_time_ms);
        $this->assertSame(0, $snapshot->active_workers);
        $this->assertSame(0, $snapshot->pending_jobs);
    }

    public function testFromArrayCastsTypes(): void
    {
        $snapshot = MetricsSnapshot::fromArray([
            'jobs_per_minute' => '12.5',
            'jobs_processed_last_hour' => '500',
            'failed_jobs' => '10',
            'failed_rate_percent' => '2.0',
            'average_processing_time_ms' => '150',
            'active_workers' => '3',
            'pending_jobs' => '42',
        ]);

        $this->assertSame(12.5, $snapshot->jobs_per_minute);
        $this->assertSame(500, $snapshot->jobs_processed_last_hour);
        $this->assertSame(10, $snapshot->failed_jobs);
        $this->assertSame(2.0, $snapshot->failed_rate_percent);
        $this->assertSame(150, $snapshot->average_processing_time_ms);
        $this->assertSame(3, $snapshot->active_workers);
        $this->assertSame(42, $snapshot->pending_jobs);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 10.5,
            jobs_processed_last_hour: 630,
            failed_jobs: 5,
            failed_rate_percent: 0.79,
            average_processing_time_ms: 200,
            active_workers: 4,
            pending_jobs: 15,
        );

        $array = $snapshot->toArray();

        $this->assertSame(10.5, $array['jobs_per_minute']);
        $this->assertSame(630, $array['jobs_processed_last_hour']);
        $this->assertSame(4, $array['active_workers']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $snapshot = new MetricsSnapshot(
            jobs_per_minute: 1.0,
            jobs_processed_last_hour: 60,
            failed_jobs: 0,
            failed_rate_percent: 0.0,
            average_processing_time_ms: 100,
            active_workers: 1,
            pending_jobs: 0,
        );

        $this->assertSame($snapshot->toArray(), $snapshot->jsonSerialize());
    }
}
