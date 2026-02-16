<?php

declare(strict_types=1);

namespace Station\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Ensures only one instance of a job exists in the queue.
 *
 * Usage:
 * class MyJob implements ShouldQueue, ShouldBeUnique
 * {
 *     public function middleware(): array
 *     {
 *         return [new Unique()];
 *     }
 *
 *     public function uniqueId(): string
 *     {
 *         return $this->userId;
 *     }
 * }
 */
final class Unique
{
    private ?CacheRepository $cache = null;

    public function __construct(
        private readonly int $lockFor = 3600,
    ) {}

    /**
     * Lock the job (called before dispatch).
     */
    public static function lock(object $job, ?int $lockFor = null): void
    {
        $instance = new self($lockFor ?? 3600);
        $uniqueId = $instance->getUniqueId($job);

        if ($uniqueId === null) {
            return;
        }

        $lockKey = $instance->getLockKey($job, $uniqueId);
        $instance->getCache()->put($lockKey, true, $instance->lockFor);
    }

    /**
     * Unlock the job (called after job completes).
     */
    public static function unlock(object $job): void
    {
        $instance = new self();
        $uniqueId = $instance->getUniqueId($job);

        if ($uniqueId === null) {
            return;
        }

        $lockKey = $instance->getLockKey($job, $uniqueId);
        $instance->getCache()->forget($lockKey);
    }

    /**
     * Handle the job.
     *
     * @param Closure(object): mixed $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $uniqueId = $this->getUniqueId($job);

        if ($uniqueId === null) {
            return $next($job);
        }

        $lockKey = $this->getLockKey($job, $uniqueId);

        // Check if already locked (job is already processing or waiting)
        if ($this->getCache()->has($lockKey)) {
            // Job already exists, skip this one
            return null;
        }

        return $next($job);
    }

    /**
     * Get the unique ID for the job.
     */
    private function getUniqueId(object $job): ?string
    {
        if (method_exists($job, 'uniqueId')) {
            return (string) $job->uniqueId();
        }

        return null;
    }

    /**
     * Get the lock key for the job.
     */
    private function getLockKey(object $job, string $uniqueId): string
    {
        $class = $job::class;

        return "station:unique:{$class}:{$uniqueId}";
    }

    /**
     * Get the cache repository.
     */
    private function getCache(): CacheRepository
    {
        if ($this->cache === null) {
            $this->cache = app(CacheRepository::class);
        }

        return $this->cache;
    }
}
