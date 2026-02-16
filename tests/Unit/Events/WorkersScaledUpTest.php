<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Station\Events\WorkersScaledUp;

class WorkersScaledUpTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $event = new WorkersScaledUp('default', 2, 5);

        $this->assertSame('default', $event->queue);
        $this->assertSame(2, $event->previousCount);
        $this->assertSame(5, $event->newCount);
    }

    public function testGetAddedCountReturnsCorrectValue(): void
    {
        $event = new WorkersScaledUp('default', 2, 5);

        $this->assertSame(3, $event->getAddedCount());
    }
}
