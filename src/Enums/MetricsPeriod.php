<?php

declare(strict_types=1);

namespace Station\Enums;

enum MetricsPeriod: string
{
    case FiveMinutes = '5m';
    case FifteenMinutes = '15m';
    case ThirtyMinutes = '30m';
    case OneHour = '1h';
    case SixHours = '6h';
    case TwelveHours = '12h';
    case TwentyFourHours = '24h';
    case SevenDays = '7d';

    /**
     * All period string values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Human-readable labels keyed by value.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(static fn(self $case): string => $case->label(), self::cases()),
        );
    }

    /**
     * Convert period to minutes.
     */
    public function toMinutes(): int
    {
        return match ($this) {
            self::FiveMinutes => 5,
            self::FifteenMinutes => 15,
            self::ThirtyMinutes => 30,
            self::OneHour => 60,
            self::SixHours => 360,
            self::TwelveHours => 720,
            self::TwentyFourHours => 1440,
            self::SevenDays => 10080,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::FiveMinutes => '5 Minutes',
            self::FifteenMinutes => '15 Minutes',
            self::ThirtyMinutes => '30 Minutes',
            self::OneHour => '1 Hour',
            self::SixHours => '6 Hours',
            self::TwelveHours => '12 Hours',
            self::TwentyFourHours => '24 Hours',
            self::SevenDays => '7 Days',
        };
    }
}
