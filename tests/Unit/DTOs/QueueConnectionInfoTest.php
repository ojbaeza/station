<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\QueueConnectionInfo;

class QueueConnectionInfoTest extends TestCase
{
    public function testFromArrayDefaults(): void
    {
        $info = QueueConnectionInfo::fromArray([]);

        $this->assertSame('', $info->name);
        $this->assertSame('', $info->driver);
        $this->assertFalse($info->is_default);
        $this->assertFalse($info->connected);
        $this->assertSame(0, $info->latency_ms);
        $this->assertNull($info->dashboard_url);
        $this->assertSame(0, $info->workers);
        $this->assertFalse($info->paused);
        $this->assertSame([], $info->config);
    }

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'name' => 'rabbitmq',
            'driver' => 'rabbitmq',
            'is_default' => true,
            'connected' => true,
            'latency_ms' => 5,
            'dashboard_url' => 'http://localhost:15672',
            'workers' => 3,
            'paused' => false,
            'config' => ['host' => 'localhost'],
        ];

        $info = QueueConnectionInfo::fromArray($data);

        $this->assertSame('rabbitmq', $info->name);
        $this->assertTrue($info->is_default);
        $this->assertTrue($info->connected);
        $this->assertSame(5, $info->latency_ms);
        $this->assertSame('http://localhost:15672', $info->dashboard_url);
        $this->assertSame(3, $info->workers);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $info = new QueueConnectionInfo(
            name: 'redis',
            driver: 'redis',
            is_default: false,
            connected: true,
            latency_ms: 2,
            dashboard_url: null,
            workers: 1,
            paused: true,
            config: ['host' => '127.0.0.1'],
        );

        $array = $info->toArray();

        $this->assertSame('redis', $array['name']);
        $this->assertNull($array['dashboard_url']);
        $this->assertTrue($array['paused']);
        $this->assertSame(['host' => '127.0.0.1'], $array['config']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $info = new QueueConnectionInfo(
            name: 'test',
            driver: 'test',
            is_default: false,
            connected: false,
            latency_ms: 0,
            dashboard_url: null,
            workers: 0,
            paused: false,
        );

        $this->assertSame($info->toArray(), $info->jsonSerialize());
    }
}
