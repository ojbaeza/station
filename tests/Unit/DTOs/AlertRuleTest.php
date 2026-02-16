<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Station\DTOs\AlertRule;
use Station\Enums\AlertType;

class AlertRuleTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'id' => 'rule-1',
            'name' => 'High Failure',
            'type' => 'high_failure_rate',
            'enabled' => true,
            'condition' => ['threshold' => 0.5],
            'window' => 600,
            'channel_ids' => ['ch-1', 'ch-2'],
            'cooldown' => 120,
            'metadata' => ['key' => 'val'],
            'source' => 'database',
            'last_triggered_at' => '2025-01-01 00:00:00',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];

        $rule = AlertRule::fromArray($data);

        $this->assertSame('rule-1', $rule->id);
        $this->assertSame('High Failure', $rule->name);
        $this->assertSame(AlertType::HighFailureRate, $rule->type);
        $this->assertTrue($rule->enabled);
        $this->assertSame(['threshold' => 0.5], $rule->condition);
        $this->assertSame(600, $rule->window);
        $this->assertSame(['ch-1', 'ch-2'], $rule->channel_ids);
        $this->assertSame(120, $rule->cooldown);
        $this->assertSame(['key' => 'val'], $rule->metadata);
        $this->assertSame('database', $rule->source);
    }

    public function testFromArrayWithAlertTypeEnum(): void
    {
        $data = [
            'id' => 'rule-2',
            'name' => 'Test',
            'type' => AlertType::QueueBackup,
            'condition' => [],
        ];

        $rule = AlertRule::fromArray($data);

        $this->assertSame(AlertType::QueueBackup, $rule->type);
    }

    public function testFromArrayWithJsonStringCondition(): void
    {
        $data = [
            'id' => 'rule-3',
            'name' => 'Test',
            'type' => 'stuck_jobs',
            'condition' => '{"threshold":10}',
        ];

        $rule = AlertRule::fromArray($data);

        $this->assertSame(['threshold' => 10], $rule->condition);
    }

    public function testFromArrayWithJsonStringChannelIds(): void
    {
        $data = [
            'id' => 'rule-4',
            'name' => 'Test',
            'type' => 'worker_down',
            'channel_ids' => '["ch-a","ch-b"]',
        ];

        $rule = AlertRule::fromArray($data);

        $this->assertSame(['ch-a', 'ch-b'], $rule->channel_ids);
    }

    public function testFromArrayWithChannelsAlias(): void
    {
        $data = [
            'id' => 'rule-5',
            'name' => 'Test',
            'type' => 'stuck_jobs',
            'channels' => ['ch-x'],
        ];

        $rule = AlertRule::fromArray($data);

        $this->assertSame(['ch-x'], $rule->channel_ids);
    }

    public function testFromArrayWithJsonStringMetadata(): void
    {
        $data = [
            'id' => 'rule-6',
            'name' => 'Test',
            'type' => 'stuck_jobs',
            'metadata' => '{"extra":"info"}',
        ];

        $rule = AlertRule::fromArray($data);

        $this->assertSame(['extra' => 'info'], $rule->metadata);
    }

    public function testFromArrayDefaults(): void
    {
        $data = [
            'type' => 'stuck_jobs',
        ];

        $rule = AlertRule::fromArray($data);

        $this->assertSame('', $rule->id);
        $this->assertSame('', $rule->name);
        $this->assertTrue($rule->enabled);
        $this->assertSame([], $rule->condition);
        $this->assertSame(300, $rule->window);
        $this->assertSame([], $rule->channel_ids);
        $this->assertSame(300, $rule->cooldown);
        $this->assertSame([], $rule->metadata);
        $this->assertSame('config', $rule->source);
        $this->assertNull($rule->last_triggered_at);
    }

    // ──────────────────────────────────────────────────────────────
    // isInCooldown
    // ──────────────────────────────────────────────────────────────

    public function testIsInCooldownReturnsFalseWhenNeverTriggered(): void
    {
        $rule = new AlertRule(
            id: 'r-1',
            name: 'Test',
            type: AlertType::StuckJobs,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 60,
            last_triggered_at: null,
        );

        $this->assertFalse($rule->isInCooldown());
    }

    public function testIsInCooldownReturnsTrueWhenWithinCooldownPeriod(): void
    {
        $recentTime = CarbonImmutable::now()->subSeconds(10)->toDateTimeString();

        $rule = new AlertRule(
            id: 'r-2',
            name: 'Test',
            type: AlertType::StuckJobs,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 60,
            last_triggered_at: $recentTime,
        );

        $this->assertTrue($rule->isInCooldown());
    }

    public function testIsInCooldownReturnsFalseWhenCooldownExpired(): void
    {
        $oldTime = CarbonImmutable::now()->subSeconds(120)->toDateTimeString();

        $rule = new AlertRule(
            id: 'r-3',
            name: 'Test',
            type: AlertType::StuckJobs,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 60,
            last_triggered_at: $oldTime,
        );

        $this->assertFalse($rule->isInCooldown());
    }

    // ──────────────────────────────────────────────────────────────
    // toArray / jsonSerialize
    // ──────────────────────────────────────────────────────────────

    public function testToArrayReturnsCorrectStructure(): void
    {
        $rule = new AlertRule(
            id: 'r-1',
            name: 'Test Rule',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 0.5],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 60,
            metadata: ['key' => 'val'],
            source: 'config',
            last_triggered_at: '2025-01-01 00:00:00',
            created_at: '2025-01-01 00:00:00',
            updated_at: '2025-01-01 00:00:00',
        );

        $array = $rule->toArray();

        $this->assertSame('r-1', $array['id']);
        $this->assertSame('high_failure_rate', $array['type']);
        $this->assertTrue($array['enabled']);
        $this->assertSame(['threshold' => 0.5], $array['condition']);
        $this->assertSame(['ch-1'], $array['channel_ids']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $rule = new AlertRule(
            id: 'r-1',
            name: 'Test Rule',
            type: AlertType::StuckJobs,
            enabled: false,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 60,
        );

        $this->assertSame($rule->toArray(), $rule->jsonSerialize());
    }

    public function testToArraySerializesTypeAsString(): void
    {
        $rule = new AlertRule(
            id: 'r-1',
            name: 'Test',
            type: AlertType::StuckJobs,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 60,
        );

        $this->assertSame('stuck_jobs', $rule->toArray()['type']);
    }
}
