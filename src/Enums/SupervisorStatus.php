<?php

declare(strict_types=1);

namespace Station\Enums;

enum SupervisorStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Terminated = 'terminated';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(static fn(self $case): string => $case->label(), self::cases()),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Paused => 'Paused',
            self::Terminated => 'Terminated',
        };
    }
}
