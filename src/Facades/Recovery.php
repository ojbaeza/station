<?php

declare(strict_types=1);

namespace Station\Facades;

use Illuminate\Support\Facades\Facade;
use Station\Recovery\JobResumer;

/**
 * @method static bool isEnabled()
 * @method static bool resume(string $jobId, string $strategy = 'graceful')
 * @method static bool resumeJob(\Station\Core\Job $job, string $strategy = 'graceful')
 * @method static int recoverAll(string $strategy = 'graceful')
 * @method static \Station\Contracts\HealthCheckerInterface health()
 *
 * @see JobResumer
 */
final class Recovery extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return JobResumer::class;
    }
}
