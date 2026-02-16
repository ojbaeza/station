<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\JobStats;

class JobStatsTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $stats = JobStats::fromArray([]);

        $this->assertSame(0, $stats->pending);
        $this->assertSame(0, $stats->processing);
        $this->assertSame(0, $stats->completed);
        $this->assertSame(0, $stats->failed);
    }

    public function testFromArrayCastsToIntegers(): void
    {
        $stats = JobStats::fromArray([
            'pending' => '10',
            'processing' => '5',
            'completed' => '100',
            'failed' => '3',
        ]);

        $this->assertSame(10, $stats->pending);
        $this->assertSame(5, $stats->processing);
        $this->assertSame(100, $stats->completed);
        $this->assertSame(3, $stats->failed);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $stats = new JobStats(pending: 5, processing: 2, completed: 100, failed: 3);

        $this->assertSame([
            'pending' => 5,
            'processing' => 2,
            'completed' => 100,
            'failed' => 3,
        ], $stats->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $stats = new JobStats(pending: 1, processing: 2, completed: 3, failed: 4);

        $this->assertSame($stats->toArray(), $stats->jsonSerialize());
    }
}
