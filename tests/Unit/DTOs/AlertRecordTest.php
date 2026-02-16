<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\AlertRecord;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;

class AlertRecordTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'id' => 42,
            'rule_id' => 'rule-1',
            'rule_name' => 'High Failure',
            'type' => 'high_failure_rate',
            'severity' => 'critical',
            'message' => 'Failure rate exceeded',
            'context' => ['queue' => 'default'],
            'channels_notified' => ['slack', 'email'],
            'resolved' => true,
            'resolved_at' => '2025-06-01 12:00:00',
            'created_at' => '2025-06-01 10:00:00',
        ];

        $record = AlertRecord::fromArray($data);

        $this->assertSame(42, $record->id);
        $this->assertSame('rule-1', $record->rule_id);
        $this->assertSame('High Failure', $record->rule_name);
        $this->assertSame(AlertType::HighFailureRate, $record->type);
        $this->assertSame(AlertSeverity::Critical, $record->severity);
        $this->assertSame('Failure rate exceeded', $record->message);
        $this->assertSame(['queue' => 'default'], $record->context);
        $this->assertSame(['slack', 'email'], $record->channels_notified);
        $this->assertTrue($record->resolved);
        $this->assertSame('2025-06-01 12:00:00', $record->resolved_at);
    }

    public function testFromArrayWithEnumInstances(): void
    {
        $data = [
            'rule_id' => 'rule-1',
            'rule_name' => 'Test',
            'type' => AlertType::WorkerDown,
            'severity' => AlertSeverity::Warning,
            'message' => 'Worker down',
        ];

        $record = AlertRecord::fromArray($data);

        $this->assertSame(AlertType::WorkerDown, $record->type);
        $this->assertSame(AlertSeverity::Warning, $record->severity);
    }

    public function testFromArrayWithJsonStringContext(): void
    {
        $data = [
            'rule_id' => 'rule-1',
            'rule_name' => 'Test',
            'type' => 'high_failure_rate',
            'severity' => 'info',
            'message' => 'test',
            'context' => '{"key":"value"}',
        ];

        $record = AlertRecord::fromArray($data);

        $this->assertSame(['key' => 'value'], $record->context);
    }

    public function testFromArrayWithJsonStringChannelsNotified(): void
    {
        $data = [
            'rule_id' => 'rule-1',
            'rule_name' => 'Test',
            'type' => 'high_failure_rate',
            'severity' => 'info',
            'message' => 'test',
            'channels_notified' => '["slack","email"]',
        ];

        $record = AlertRecord::fromArray($data);

        $this->assertSame(['slack', 'email'], $record->channels_notified);
    }

    public function testFromArrayWithNullId(): void
    {
        $data = [
            'rule_id' => 'rule-1',
            'rule_name' => 'Test',
            'type' => 'high_failure_rate',
            'severity' => 'info',
            'message' => 'test',
        ];

        $record = AlertRecord::fromArray($data);

        $this->assertNull($record->id);
    }

    public function testFromArrayDefaults(): void
    {
        $data = [
            'type' => 'high_failure_rate',
            'severity' => 'info',
        ];

        $record = AlertRecord::fromArray($data);

        $this->assertSame('', $record->rule_id);
        $this->assertSame('', $record->rule_name);
        $this->assertSame('', $record->message);
        $this->assertSame([], $record->context);
        $this->assertSame([], $record->channels_notified);
        $this->assertFalse($record->resolved);
        $this->assertNull($record->resolved_at);
        $this->assertNull($record->created_at);
    }

    // ──────────────────────────────────────────────────────────────
    // toArray / jsonSerialize
    // ──────────────────────────────────────────────────────────────

    public function testToArraySerializesEnumsAsStrings(): void
    {
        $record = new AlertRecord(
            id: 1,
            rule_id: 'rule-1',
            rule_name: 'Test',
            type: AlertType::QueueBackup,
            severity: AlertSeverity::Critical,
            message: 'Queue is backed up',
        );

        $array = $record->toArray();

        $this->assertSame('queue_backup', $array['type']);
        $this->assertSame('critical', $array['severity']);
        $this->assertSame(1, $array['id']);
        $this->assertSame('rule-1', $array['rule_id']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $record = new AlertRecord(
            id: 1,
            rule_id: 'rule-1',
            rule_name: 'Test',
            type: AlertType::HighFailureRate,
            severity: AlertSeverity::Info,
            message: 'test',
        );

        $this->assertSame($record->toArray(), $record->jsonSerialize());
    }
}
