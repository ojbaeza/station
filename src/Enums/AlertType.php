<?php

declare(strict_types=1);

namespace Station\Enums;

enum AlertType: string
{
    case HighFailureRate = 'high_failure_rate';
    case QueueBackup = 'queue_backup';
    case StuckJobs = 'stuck_jobs';
    case WorkerDown = 'worker_down';

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
            self::HighFailureRate => 'High Failure Rate',
            self::QueueBackup => 'Queue Backup',
            self::StuckJobs => 'Stuck Jobs',
            self::WorkerDown => 'Worker Down',
        };
    }
}
