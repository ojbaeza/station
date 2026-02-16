<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\ScalingDecision;

class ScalingDecisionTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $decision = ScalingDecision::fromArray([]);

        $this->assertSame('', $decision->action);
        $this->assertSame(0, $decision->from);
        $this->assertSame(0, $decision->to);
    }

    public function testFromArrayWithAllFields(): void
    {
        $decision = ScalingDecision::fromArray([
            'action' => 'scale_up',
            'from' => 2,
            'to' => 5,
        ]);

        $this->assertSame('scale_up', $decision->action);
        $this->assertSame(2, $decision->from);
        $this->assertSame(5, $decision->to);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $decision = new ScalingDecision(action: 'scale_down', from: 5, to: 3);

        $this->assertSame([
            'action' => 'scale_down',
            'from' => 5,
            'to' => 3,
        ], $decision->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $decision = new ScalingDecision(action: 'none', from: 3, to: 3);

        $this->assertSame($decision->toArray(), $decision->jsonSerialize());
    }
}
