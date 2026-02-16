<?php

declare(strict_types=1);

namespace Station\Enums;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case Warning = 'warning';

    /**
     * All status string values.
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
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Unhealthy => 'Unhealthy',
            self::Warning => 'Warning',
        };
    }
}
