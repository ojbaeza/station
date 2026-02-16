<?php

declare(strict_types=1);

namespace Station\Enums;

enum WorkflowStepStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

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
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
