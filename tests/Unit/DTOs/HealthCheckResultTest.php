<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\HealthCheckResult;

class HealthCheckResultTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // toArray — conditional connections field
    // ──────────────────────────────────────────────────────────────

    public function testToArrayOmitsConnectionsWhenNull(): void
    {
        $result = new HealthCheckResult(
            status: 'healthy',
            timestamp: '2025-01-01T00:00:00Z',
            checks: ['queue' => ['status' => 'ok']],
        );

        $array = $result->toArray();

        $this->assertArrayNotHasKey('connections', $array);
        $this->assertSame('healthy', $array['status']);
        $this->assertSame(['queue' => ['status' => 'ok']], $array['checks']);
    }

    public function testToArrayIncludesConnectionsWhenPresent(): void
    {
        $connections = ['rabbitmq' => ['connected' => true, 'latency' => 5]];

        $result = new HealthCheckResult(
            status: 'healthy',
            timestamp: '2025-01-01T00:00:00Z',
            checks: [],
            connections: $connections,
        );

        $array = $result->toArray();

        $this->assertSame($connections, $array['connections']);
    }

    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayDefaults(): void
    {
        $result = HealthCheckResult::fromArray([]);

        $this->assertSame('unknown', $result->status);
        $this->assertSame('', $result->timestamp);
        $this->assertSame([], $result->checks);
        $this->assertNull($result->connections);
    }

    public function testFromArrayWithConnections(): void
    {
        $data = [
            'status' => 'degraded',
            'timestamp' => '2025-06-01T12:00:00Z',
            'checks' => ['workers' => ['count' => 3]],
            'connections' => ['redis' => ['connected' => true]],
        ];

        $result = HealthCheckResult::fromArray($data);

        $this->assertSame('degraded', $result->status);
        $this->assertSame(['redis' => ['connected' => true]], $result->connections);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new HealthCheckResult(
            status: 'healthy',
            timestamp: '2025-01-01T00:00:00Z',
            checks: [],
        );

        $this->assertSame($result->toArray(), $result->jsonSerialize());
    }
}
