<?php

declare(strict_types=1);

namespace Station\Facades;

use Illuminate\Support\Facades\Facade;
use Station\Core\Batch as BatchData;
use Station\Core\BatchManager;

/**
 * @method static BatchData create(array<int, object> $jobs, ?string $name = null, string $queue = 'default', int $allowedFailures = 0, array<string, mixed> $options = [], ?string $connection = null)
 * @method static BatchData|null find(string $id)
 * @method static \Illuminate\Support\Collection<int, BatchData> getActive()
 * @method static \Illuminate\Support\Collection<int, BatchData> getRecent(int $limit = 10)
 * @method static \Illuminate\Support\Collection<int, BatchData> getByStatus(string $status, int $limit = 100)
 * @method static void recordJobCompletion(string $batchId)
 * @method static void recordJobFailure(string $batchId, string $jobId)
 * @method static bool cancel(string $id)
 * @method static int retryFailed(string $id)
 * @method static int prune()
 *
 * @see BatchManager
 */
final class Batch extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return BatchManager::class;
    }
}
