<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Station\Telemetry\Span;

class SpanTest extends TestCase
{
    public function testSpanCreatesWithUniqueIds(): void
    {
        $span = new Span('test-span');

        $this->assertNotEmpty($span->getId());
        $this->assertNotEmpty($span->getTraceId());
        $this->assertSame('test-span', $span->getName());
    }

    public function testSpanInheritsTraceIdFromParent(): void
    {
        $parentSpan = new Span('parent');
        $childSpan = new Span('child', $parentSpan->getTraceId(), $parentSpan->getId());

        $this->assertSame($parentSpan->getTraceId(), $childSpan->getTraceId());
        $this->assertSame($parentSpan->getId(), $childSpan->getParentId());
    }

    public function testSetAttributeStoresValue(): void
    {
        $span = new Span('test');

        $span->setAttribute('key1', 'value1');
        $span->setAttribute('key2', 42);

        $this->assertSame('value1', $span->getAttribute('key1'));
        $this->assertSame(42, $span->getAttribute('key2'));
        $this->assertNull($span->getAttribute('nonexistent'));
    }

    public function testSetAttributesSetsMultiple(): void
    {
        $span = new Span('test');

        $span->setAttributes([
            'key1' => 'value1',
            'key2' => 'value2',
        ]);

        $attributes = $span->getAttributes();

        $this->assertSame('value1', $attributes['key1']);
        $this->assertSame('value2', $attributes['key2']);
    }

    public function testSetStatusUpdatesStatus(): void
    {
        $span = new Span('test');

        $span->setStatus('error', 'Something went wrong');

        $this->assertSame('error', $span->getStatus());
        $this->assertSame('Something went wrong', $span->getStatusMessage());
    }

    public function testAddEventRecordsEvent(): void
    {
        $span = new Span('test');

        $span->addEvent('custom.event', ['key' => 'value']);

        $array = $span->toArray();

        $this->assertCount(1, $array['events']);
        $this->assertSame('custom.event', $array['events'][0]['name']);
        $this->assertSame(['key' => 'value'], $array['events'][0]['attributes']);
    }

    public function testRecordExceptionAddsExceptionEvent(): void
    {
        $span = new Span('test');

        $exception = new RuntimeException('Test error');
        $span->recordException($exception);

        $array = $span->toArray();

        $this->assertCount(1, $array['events']);
        $this->assertSame('exception', $array['events'][0]['name']);
        $this->assertSame('RuntimeException', $array['events'][0]['attributes']['exception.type']);
        $this->assertSame('Test error', $array['events'][0]['attributes']['exception.message']);
    }

    public function testEndSetsEndTime(): void
    {
        $span = new Span('test');

        $this->assertFalse($span->hasEnded());
        $this->assertNull($span->getEndTime());

        $span->end();

        $this->assertTrue($span->hasEnded());
        $this->assertNotNull($span->getEndTime());
    }

    public function testGetDurationMsReturnsMilliseconds(): void
    {
        $span = new Span('test');

        // Simulate some work
        usleep(10000); // 10ms

        $span->end();

        $duration = $span->getDurationMs();

        $this->assertNotNull($duration);
        $this->assertGreaterThan(0, $duration);
    }

    public function testGetDurationMsReturnsNullBeforeEnd(): void
    {
        $span = new Span('test');

        $this->assertNull($span->getDurationMs());
    }

    public function testToArrayConvertsSpan(): void
    {
        $span = new Span('test-span');
        $span->setAttribute('key', 'value');
        $span->setStatus('ok');
        $span->end();

        $array = $span->toArray();

        $this->assertSame('test-span', $array['name']);
        $this->assertSame('ok', $array['status']);
        $this->assertSame(['key' => 'value'], $array['attributes']);
        $this->assertNotNull($array['start_time']);
        $this->assertNotNull($array['end_time']);
        $this->assertNotNull($array['duration_ms']);
    }

    public function testEndCalledTwiceDoesNotChangeEndTime(): void
    {
        $span = new Span('test');

        $span->end();
        $firstEndTime = $span->getEndTime();

        usleep(1000); // Wait a bit

        $span->end(); // Call again
        $secondEndTime = $span->getEndTime();

        // End time should remain the same
        $this->assertSame($firstEndTime, $secondEndTime);
    }

    public function testDefaultStatusIsUnset(): void
    {
        $span = new Span('test');

        $this->assertSame('unset', $span->getStatus());
        $this->assertNull($span->getStatusMessage());
    }

    public function testSetAttributeReturnsSelf(): void
    {
        $span = new Span('test');

        $result = $span->setAttribute('key', 'value');

        $this->assertSame($span, $result);
    }

    public function testSetAttributesReturnsSelf(): void
    {
        $span = new Span('test');

        $result = $span->setAttributes(['key' => 'value']);

        $this->assertSame($span, $result);
    }

    public function testSetStatusReturnsSelf(): void
    {
        $span = new Span('test');

        $result = $span->setStatus('ok');

        $this->assertSame($span, $result);
    }

    public function testAddEventReturnsSelf(): void
    {
        $span = new Span('test');

        $result = $span->addEvent('test.event');

        $this->assertSame($span, $result);
    }

    public function testRecordExceptionReturnsSelf(): void
    {
        $span = new Span('test');

        $result = $span->recordException(new Exception('test'));

        $this->assertSame($span, $result);
    }

    public function testGetStartTimeReturnsDateTimeImmutable(): void
    {
        $span = new Span('test');

        $startTime = $span->getStartTime();

        $this->assertInstanceOf(DateTimeImmutable::class, $startTime);
    }

    public function testParentIdIsNullByDefault(): void
    {
        $span = new Span('test');

        $this->assertNull($span->getParentId());
    }

    public function testToArrayWithoutEndTime(): void
    {
        $span = new Span('test');

        $array = $span->toArray();

        $this->assertNull($array['end_time']);
        $this->assertNull($array['duration_ms']);
    }

    public function testMultipleEventsCanBeAdded(): void
    {
        $span = new Span('test');

        $span->addEvent('event1', ['a' => 1]);
        $span->addEvent('event2', ['b' => 2]);
        $span->addEvent('event3');

        $array = $span->toArray();

        $this->assertCount(3, $array['events']);
    }
}
