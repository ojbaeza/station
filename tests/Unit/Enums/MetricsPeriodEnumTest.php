<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Station\Enums\MetricsPeriod;

class MetricsPeriodEnumTest extends TestCase
{
    public static function toMinutesProvider(): array
    {
        return [
            '5m' => [MetricsPeriod::FiveMinutes, 5],
            '15m' => [MetricsPeriod::FifteenMinutes, 15],
            '30m' => [MetricsPeriod::ThirtyMinutes, 30],
            '1h' => [MetricsPeriod::OneHour, 60],
            '6h' => [MetricsPeriod::SixHours, 360],
            '12h' => [MetricsPeriod::TwelveHours, 720],
            '24h' => [MetricsPeriod::TwentyFourHours, 1440],
            '7d' => [MetricsPeriod::SevenDays, 10080],
        ];
    }

    public static function labelProvider(): array
    {
        return [
            '5m' => [MetricsPeriod::FiveMinutes, '5 Minutes'],
            '15m' => [MetricsPeriod::FifteenMinutes, '15 Minutes'],
            '30m' => [MetricsPeriod::ThirtyMinutes, '30 Minutes'],
            '1h' => [MetricsPeriod::OneHour, '1 Hour'],
            '6h' => [MetricsPeriod::SixHours, '6 Hours'],
            '12h' => [MetricsPeriod::TwelveHours, '12 Hours'],
            '24h' => [MetricsPeriod::TwentyFourHours, '24 Hours'],
            '7d' => [MetricsPeriod::SevenDays, '7 Days'],
        ];
    }

    #[DataProvider('toMinutesProvider')]
    public function testToMinutes(MetricsPeriod $period, int $expected): void
    {
        $this->assertSame($expected, $period->toMinutes());
    }

    public function testValuesReturnsAllPeriodStrings(): void
    {
        $values = MetricsPeriod::values();

        $this->assertCount(8, $values);
        $this->assertContains('5m', $values);
        $this->assertContains('15m', $values);
        $this->assertContains('30m', $values);
        $this->assertContains('1h', $values);
        $this->assertContains('6h', $values);
        $this->assertContains('12h', $values);
        $this->assertContains('24h', $values);
        $this->assertContains('7d', $values);
    }

    public function testLabelsReturnsKeyedArray(): void
    {
        $labels = MetricsPeriod::labels();

        $this->assertCount(8, $labels);
        $this->assertSame('5 Minutes', $labels['5m']);
        $this->assertSame('15 Minutes', $labels['15m']);
        $this->assertSame('30 Minutes', $labels['30m']);
        $this->assertSame('1 Hour', $labels['1h']);
        $this->assertSame('6 Hours', $labels['6h']);
        $this->assertSame('12 Hours', $labels['12h']);
        $this->assertSame('24 Hours', $labels['24h']);
        $this->assertSame('7 Days', $labels['7d']);
    }

    #[DataProvider('labelProvider')]
    public function testLabel(MetricsPeriod $period, string $expected): void
    {
        $this->assertSame($expected, $period->label());
    }
}
