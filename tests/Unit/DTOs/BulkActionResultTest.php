<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\BulkActionResult;

class BulkActionResultTest extends TestCase
{
    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'success' => true,
            'processed' => 10,
            'failed' => 2,
            'errors' => [
                ['id' => 'job-1', 'message' => 'Timeout'],
                ['id' => 'job-2', 'message' => 'Not found'],
            ],
        ];

        $result = BulkActionResult::fromArray($data);

        $this->assertTrue($result->success);
        $this->assertSame(10, $result->processed);
        $this->assertSame(2, $result->failed);
        $this->assertCount(2, $result->errors);
        $this->assertSame('job-1', $result->errors[0]['id']);
    }

    public function testFromArrayDefaults(): void
    {
        $result = BulkActionResult::fromArray([]);

        $this->assertFalse($result->success);
        $this->assertSame(0, $result->processed);
        $this->assertSame(0, $result->failed);
        $this->assertSame([], $result->errors);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $result = new BulkActionResult(
            success: true,
            processed: 5,
            failed: 1,
            errors: [['id' => 'j-1', 'message' => 'err']],
        );

        $array = $result->toArray();

        $this->assertSame([
            'success' => true,
            'processed' => 5,
            'failed' => 1,
            'errors' => [['id' => 'j-1', 'message' => 'err']],
        ], $array);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new BulkActionResult(success: false, processed: 0, failed: 0);

        $this->assertSame($result->toArray(), $result->jsonSerialize());
    }
}
