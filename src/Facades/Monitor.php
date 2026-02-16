<?php

declare(strict_types=1);

namespace Station\Facades;

use Illuminate\Support\Facades\Facade;
use Station\Core\MetricsCollector;

/**
 * @method static float getThroughput(?string $queue = null)
 * @method static float getAverageWaitTime(?string $queue = null)
 * @method static float getAverageProcessingTime(?string $queue = null)
 * @method static float getFailureRate(?string $queue = null)
 * @method static array<string, mixed> getMetrics(string $period = '1h')
 * @method static array<string, mixed> getAggregatedForPeriod(string $period = '1h')
 * @method static array<string, mixed> paginateHistoricalMetrics(string $period, int $page, int $perPage)
 * @method static void recordJobCompletion(string $queue, int $processingTimeMs = 0, int $waitTimeMs = 0, int $memoryUsed = 0)
 * @method static void recordJobFailure(string $queue)
 * @method static array<string, mixed> getQueueStats()
 *
 * @see MetricsCollector
 */
final class Monitor extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return MetricsCollector::class;
    }
}
