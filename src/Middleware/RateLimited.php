<?php

declare(strict_types=1);

namespace Station\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;

/**
 * Rate limiting middleware for jobs.
 *
 * Usage:
 * class MyJob implements ShouldQueue
 * {
 *     public function middleware(): array
 *     {
 *         return [new RateLimited('my-job', 10, 60)]; // 10 per minute
 *     }
 * }
 */
final class RateLimited
{
    private ?RateLimiter $limiter = null;

    public function __construct(
        private readonly string $key,
        private readonly int $maxAttempts = 10,
        private readonly int $decaySeconds = 60,
        private readonly bool $releaseOnLimit = true,
        private readonly int $releaseDelay = 60,
    ) {}

    /**
     * Handle the job.
     *
     * @param Closure(object): mixed $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $limiter = $this->getLimiter();
        $key = $this->resolveKey($job);

        if ($limiter->tooManyAttempts($key, $this->maxAttempts)) {
            if ($this->releaseOnLimit && method_exists($job, 'release')) {
                $job->release($this->releaseDelay);

                return null;
            }

            return null; // Skip the job
        }

        $limiter->hit($key, $this->decaySeconds);

        return $next($job);
    }

    /**
     * Resolve the rate limiter key.
     */
    private function resolveKey(object $job): string
    {
        // Allow job to customize the key
        if (method_exists($job, 'rateLimiterKey')) {
            return 'station:rate_limit:' . $job->rateLimiterKey();
        }

        return 'station:rate_limit:' . $this->key;
    }

    /**
     * Get the rate limiter instance.
     */
    private function getLimiter(): RateLimiter
    {
        if ($this->limiter === null) {
            $this->limiter = app(RateLimiter::class);
        }

        return $this->limiter;
    }
}
