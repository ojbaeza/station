<?php

declare(strict_types=1);

namespace Station\Enums;

enum RecoveryStrategy: string
{
    case Graceful = 'graceful';
    case Restart = 'restart';
    case Checkpoint = 'checkpoint';

    /**
     * All strategy string values.
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
            self::Graceful => 'Graceful',
            self::Restart => 'Restart',
            self::Checkpoint => 'Checkpoint',
        };
    }
}
