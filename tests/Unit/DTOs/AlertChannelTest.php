<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\AlertChannel;
use Station\Enums\AlertChannelType;

class AlertChannelTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'id' => 'ch-1',
            'name' => 'Slack Alerts',
            'type' => 'slack',
            'enabled' => true,
            'config' => ['webhook_url' => 'https://hooks.slack.com/test'],
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];

        $channel = AlertChannel::fromArray($data);

        $this->assertSame('ch-1', $channel->id);
        $this->assertSame('Slack Alerts', $channel->name);
        $this->assertSame(AlertChannelType::Slack, $channel->type);
        $this->assertTrue($channel->enabled);
        $this->assertSame(['webhook_url' => 'https://hooks.slack.com/test'], $channel->config);
        $this->assertSame('2025-01-01 00:00:00', $channel->created_at);
    }

    public function testFromArrayWithEnumInstance(): void
    {
        $data = [
            'id' => 'ch-2',
            'name' => 'Email',
            'type' => AlertChannelType::Email,
        ];

        $channel = AlertChannel::fromArray($data);

        $this->assertSame(AlertChannelType::Email, $channel->type);
    }

    public function testFromArrayWithJsonStringConfig(): void
    {
        $data = [
            'id' => 'ch-3',
            'name' => 'Webhook',
            'type' => 'webhook',
            'config' => '{"url":"https://example.com/hook"}',
        ];

        $channel = AlertChannel::fromArray($data);

        $this->assertSame(['url' => 'https://example.com/hook'], $channel->config);
    }

    public function testFromArrayDefaults(): void
    {
        $data = [
            'type' => 'log',
        ];

        $channel = AlertChannel::fromArray($data);

        $this->assertSame('', $channel->id);
        $this->assertSame('', $channel->name);
        $this->assertTrue($channel->enabled);
        $this->assertSame([], $channel->config);
        $this->assertNull($channel->created_at);
        $this->assertNull($channel->updated_at);
    }

    // ──────────────────────────────────────────────────────────────
    // toArray / jsonSerialize
    // ──────────────────────────────────────────────────────────────

    public function testToArraySerializesTypeAsString(): void
    {
        $channel = new AlertChannel(
            id: 'ch-1',
            name: 'Discord',
            type: AlertChannelType::Discord,
            enabled: false,
            config: ['webhook' => 'url'],
        );

        $array = $channel->toArray();

        $this->assertSame('discord', $array['type']);
        $this->assertFalse($array['enabled']);
        $this->assertSame(['webhook' => 'url'], $array['config']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $channel = new AlertChannel(
            id: 'ch-1',
            name: 'Test',
            type: AlertChannelType::Log,
            enabled: true,
        );

        $this->assertSame($channel->toArray(), $channel->jsonSerialize());
    }
}
