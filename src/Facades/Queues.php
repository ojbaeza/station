<?php

declare(strict_types=1);

namespace Station\Facades;

use Illuminate\Support\Facades\Facade;
use Station\Core\QueueManager;

/**
 * @method static void pause(string $queue, ?string $connection = null)
 * @method static void resume(string $queue, ?string $connection = null)
 * @method static bool isPaused(string $queue, ?string $connection = null)
 * @method static int size(string $queue, ?string $connection = null)
 * @method static int clear(string $queue, ?string $connection = null)
 * @method static array<string, mixed> status(?string $connection = null)
 * @method static array<int, string> getAll(?string $connection = null)
 *
 * @see QueueManager
 */
final class Queues extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return QueueManager::class;
    }
}
