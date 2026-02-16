<?php

declare(strict_types=1);

namespace Station\Drivers\Traits;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Provides time-based cached pause status checks.
 *
 * Checks the DB every few seconds instead of every pop() cycle,
 * reducing query load while keeping pause responsiveness acceptable.
 */
trait ChecksPauseStatus
{
    /** @var array<string, bool> */
    private array $pauseCache = [];

    /** @var array<string, float> */
    private array $pauseCacheTime = [];

    private float $pauseCacheTtl = 5.0;

    public function isPaused(string $queue): bool
    {
        $now = microtime(true);

        if (
            isset($this->pauseCache[$queue])
            && ($now - $this->pauseCacheTime[$queue]) < $this->pauseCacheTtl
        ) {
            return $this->pauseCache[$queue];
        }

        $paused = $this->queryPauseStatus($queue);
        $this->pauseCache[$queue] = $paused;
        $this->pauseCacheTime[$queue] = $now;

        return $paused;
    }

    private function queryPauseStatus(string $queue): bool
    {
        try {
            return (bool) DB::table('station_queue_status')
                ->where('queue', $queue)
                ->where('connection', $this->connectionName ?: 'default')
                ->value('paused');
        } catch (Throwable) {
            return false;
        }
    }
}
