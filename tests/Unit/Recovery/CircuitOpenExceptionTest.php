<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Station\Recovery\CircuitOpenException;

class CircuitOpenExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $exception = new CircuitOpenException('Circuit breaker is open');

        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testCanBeCreatedWithMessageCodeAndPrevious(): void
    {
        $previous = new Exception('Previous error');
        $exception = new CircuitOpenException('Test', 503, $previous);

        $this->assertSame('Test', $exception->getMessage());
        $this->assertSame(503, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCanBeThrownAndCaught(): void
    {
        $this->expectException(CircuitOpenException::class);
        $this->expectExceptionMessage('Service unavailable');

        throw new CircuitOpenException('Service unavailable');
    }
}
