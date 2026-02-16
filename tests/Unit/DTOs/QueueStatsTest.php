<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\QueueStats;

class QueueStatsTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $stats = QueueStats::fromArray([]);

        $this->assertSame(0, $stats->size);
        $this->assertFalse($stats->paused);
        $this->assertSame(0, $stats->workers);
        $this->assertSame(0.0, $stats->throughput);
    }

    public function testFromArrayCastsTypes(): void
    {
        $stats = QueueStats::fromArray([
            'size' => '100',
            'paused' => 1,
            'workers' => '5',
            'throughput' => '12.5',
        ]);

        $this->assertSame(100, $stats->size);
        $this->assertTrue($stats->paused);
        $this->assertSame(5, $stats->workers);
        $this->assertSame(12.5, $stats->throughput);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $stats = new QueueStats(size: 50, paused: false, workers: 3, throughput: 8.2);

        $this->assertSame([
            'size' => 50,
            'paused' => false,
            'workers' => 3,
            'throughput' => 8.2,
        ], $stats->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $stats = new QueueStats(size: 0, paused: false, workers: 0, throughput: 0.0);

        $this->assertSame($stats->toArray(), $stats->jsonSerialize());
    }
}
