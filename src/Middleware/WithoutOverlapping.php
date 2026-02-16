<?php

declare(strict_types=1);

namespace Station\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Prevents overlapping job executions.
 *
 * Usage:
 * class MyJob implements ShouldQueue
 * {
 *     public function middleware(): array
 *     {
 *         return [new WithoutOverlapping('my-unique-key')];
 *     }
 * }
 */
final class WithoutOverlapping
{
    private ?CacheRepository $cache = null;

    public function __construct(
        private readonly string $key,
        private readonly int $expiresAfter = 300,
        private readonly bool $releaseOnOverlap = true,
        private readonly int $releaseDelay = 5,
    ) {}

    /**
     * Handle the job.
     *
     * @param Closure(object): mixed $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $lockKey = $this->getLockKey($job);

        $acquired = $this->getCache()->add($lockKey, true, $this->expiresAfter);

        if (!$acquired) {
            if ($this->releaseOnOverlap && method_exists($job, 'release')) {
                $job->release($this->releaseDelay);

                return null;
            }

            return null; // Skip the job
        }

        try {
            return $next($job);
        } finally {
            $this->getCache()->forget($lockKey);
        }
    }

    /**
     * Get the lock key for the job.
     */
    private function getLockKey(object $job): string
    {
        // Allow job to customize the key
        if (method_exists($job, 'uniqueId')) {
            return 'station:overlapping:' . $job->uniqueId();
        }

        return 'station:overlapping:' . $this->key;
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
