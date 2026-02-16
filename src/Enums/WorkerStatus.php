<?php

declare(strict_types=1);

namespace Station\Enums;

enum WorkerStatus: string
{
    case Running = 'running';
    case Idle = 'idle';
    case Processing = 'processing';
    case Stopped = 'stopped';

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
            self::Idle => 'Idle',
            self::Processing => 'Processing',
            self::Stopped => 'Stopped',
        };
    }
}
