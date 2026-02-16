<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\ConnectionStatus;

class ConnectionStatusTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // toArray — conditional null field omission
    // ──────────────────────────────────────────────────────────────

    public function testToArrayOmitsNullOptionalFields(): void
    {
        $status = new ConnectionStatus(
            connected: true,
            latency_ms: 5,
        );

        $array = $status->toArray();

        $this->assertSame(['connected' => true, 'latency_ms' => 5], $array);
        $this->assertArrayNotHasKey('driver', $array);
        $this->assertArrayNotHasKey('dashboard_url', $array);
        $this->assertArrayNotHasKey('message', $array);
    }

    public function testToArrayIncludesDriverWhenPresent(): void
    {
        $status = new ConnectionStatus(
            connected: true,
            latency_ms: 5,
            driver: 'redis',
        );

        $array = $status->toArray();

        $this->assertSame('redis', $array['driver']);
        $this->assertArrayNotHasKey('dashboard_url', $array);
        $this->assertArrayNotHasKey('message', $array);
    }

    public function testToArrayIncludesDashboardUrlWhenPresent(): void
    {
        $status = new ConnectionStatus(
            connected: true,
            latency_ms: 3,
            dashboard_url: 'http://localhost:15672',
        );

        $array = $status->toArray();

        $this->assertSame('http://localhost:15672', $array['dashboard_url']);
    }

    public function testToArrayIncludesMessageWhenPresent(): void
    {
        $status = new ConnectionStatus(
            connected: false,
            latency_ms: 0,
            message: 'Connection refused',
        );

        $array = $status->toArray();

        $this->assertSame('Connection refused', $array['message']);
    }

    public function testToArrayIncludesAllFieldsWhenAllPresent(): void
    {
        $status = new ConnectionStatus(
            connected: true,
            latency_ms: 10,
            driver: 'rabbitmq',
            dashboard_url: 'http://localhost:15672',
            message: 'OK',
        );

        $array = $status->toArray();

        $this->assertCount(5, $array);
        $this->assertSame('rabbitmq', $array['driver']);
        $this->assertSame('http://localhost:15672', $array['dashboard_url']);
        $this->assertSame('OK', $array['message']);
    }

    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayDefaults(): void
    {
        $status = ConnectionStatus::fromArray([]);

        $this->assertFalse($status->connected);
        $this->assertSame(0, $status->latency_ms);
        $this->assertNull($status->driver);
        $this->assertNull($status->dashboard_url);
        $this->assertNull($status->message);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $status = new ConnectionStatus(connected: true, latency_ms: 7, driver: 'sqs');

        $this->assertSame($status->toArray(), $status->jsonSerialize());
    }
}
