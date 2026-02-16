<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\ProcessResult;

class ProcessResultTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // toArray — conditional null field omission
    // ──────────────────────────────────────────────────────────────

    public function testToArrayOmitsNullOptionalFields(): void
    {
        $result = new ProcessResult(success: true, message: 'Worker started');

        $array = $result->toArray();

        $this->assertSame(['success' => true, 'message' => 'Worker started'], $array);
        $this->assertArrayNotHasKey('pid', $array);
        $this->assertArrayNotHasKey('command', $array);
        $this->assertArrayNotHasKey('stopped', $array);
    }

    public function testToArrayIncludesPidWhenPresent(): void
    {
        $result = new ProcessResult(success: true, message: 'Started', pid: 12345);

        $array = $result->toArray();

        $this->assertSame(12345, $array['pid']);
        $this->assertArrayNotHasKey('command', $array);
        $this->assertArrayNotHasKey('stopped', $array);
    }

    public function testToArrayIncludesCommandWhenPresent(): void
    {
        $result = new ProcessResult(
            success: true,
            message: 'Started',
            command: 'php artisan station:work',
        );

        $array = $result->toArray();

        $this->assertSame('php artisan station:work', $array['command']);
    }

    public function testToArrayIncludesStoppedWhenPresent(): void
    {
        $result = new ProcessResult(
            success: true,
            message: 'Stopped',
            stopped: 3,
        );

        $array = $result->toArray();

        $this->assertSame(3, $array['stopped']);
    }

    public function testToArrayIncludesAllFieldsWhenAllPresent(): void
    {
        $result = new ProcessResult(
            success: true,
            message: 'Started',
            pid: 999,
            command: 'artisan work',
            stopped: 0,
        );

        $array = $result->toArray();

        $this->assertCount(5, $array);
    }

    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayDefaults(): void
    {
        $result = ProcessResult::fromArray([]);

        $this->assertFalse($result->success);
        $this->assertSame('', $result->message);
        $this->assertNull($result->pid);
        $this->assertNull($result->command);
        $this->assertNull($result->stopped);
    }

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'success' => true,
            'message' => 'OK',
            'pid' => 42,
            'command' => 'test',
            'stopped' => 1,
        ];

        $result = ProcessResult::fromArray($data);

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->pid);
        $this->assertSame('test', $result->command);
        $this->assertSame(1, $result->stopped);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ProcessResult(success: false, message: 'Failed');

        $this->assertSame($result->toArray(), $result->jsonSerialize());
    }
}
