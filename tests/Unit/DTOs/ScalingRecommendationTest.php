<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\ScalingRecommendation;

class ScalingRecommendationTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $rec = ScalingRecommendation::fromArray([]);

        $this->assertSame(0, $rec->current);
        $this->assertSame(0, $rec->recommended);
        $this->assertSame('', $rec->reason);
    }

    public function testFromArrayWithAllFields(): void
    {
        $rec = ScalingRecommendation::fromArray([
            'current' => 3,
            'recommended' => 5,
            'reason' => 'Queue backlog detected',
        ]);

        $this->assertSame(3, $rec->current);
        $this->assertSame(5, $rec->recommended);
        $this->assertSame('Queue backlog detected', $rec->reason);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $rec = new ScalingRecommendation(current: 2, recommended: 4, reason: 'High load');

        $this->assertSame([
            'current' => 2,
            'recommended' => 4,
            'reason' => 'High load',
        ], $rec->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $rec = new ScalingRecommendation(current: 1, recommended: 1, reason: 'Optimal');

        $this->assertSame($rec->toArray(), $rec->jsonSerialize());
    }
}
