<?php

declare(strict_types=1);

namespace Station\Facades;

use Illuminate\Support\Facades\Facade;
use Station\Core\JobManager;
use Station\Core\PendingDispatch;

/**
 * @method static PendingDispatch job(object $job)
 * @method static string dispatch(object $job, ?string $queue = null, ?\Carbon\CarbonImmutable $delay = null, ?string $batchId = null, array<int, string> $tags = [], ?string $connection = null)
 * @method static void dispatchSync(object $job)
 * @method static \Station\Core\Job|null find(string $id)
 * @method static void delete(string $id)
 * @method static bool retry(string $id)
 * @method static int retryAll(?string $queue = null)
 * @method static int retryAllFailed(?string $queue = null)
 * @method static bool cancel(string $id)
 * @method static void complete(string $id, int $processingTime, int $memoryUsed)
 * @method static void fail(string $id, \Throwable $exception, array<string, mixed> $context = [])
 * @method static \Illuminate\Support\Collection<int, \Station\Core\Job> getByStatus(string $status, ?string $queue = null, int $limit = 100)
 * @method static \Illuminate\Support\Collection<int, \Station\Core\Job> getRecent(int $limit = 10, ?string $queue = null)
 * @method static array<string, mixed> getStats(?string $queue = null)
 * @method static \Illuminate\Support\Collection<int, \Station\Core\Job> search(array<string, mixed> $filters, int $limit = 50, int $offset = 0)
 * @method static int count(array<string, mixed> $filters = [])
 * @method static int pruneCompleted(int $hours)
 *
 * @see JobManager
 */
final class Station extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return JobManager::class;
    }
}
