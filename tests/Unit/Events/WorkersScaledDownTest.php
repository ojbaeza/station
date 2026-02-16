<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Station\Events\WorkersScaledDown;

class WorkersScaledDownTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $event = new WorkersScaledDown('default', 5, 2);

        $this->assertSame('default', $event->queue);
        $this->assertSame(5, $event->previousCount);
        $this->assertSame(2, $event->newCount);
    }

    public function testGetRemovedCountReturnsCorrectValue(): void
    {
        $event = new WorkersScaledDown('default', 5, 2);

        $this->assertSame(3, $event->getRemovedCount());
    }
}
