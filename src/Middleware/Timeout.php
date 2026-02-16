<?php

declare(strict_types=1);

namespace Station\Middleware;

use Closure;

/**
 * Sets a timeout for job execution.
 *
 * Usage:
 * class MyJob implements ShouldQueue
 * {
 *     public function middleware(): array
 *     {
 *         return [new Timeout(30)]; // 30 second timeout
 *     }
 * }
 */
final class Timeout
{
    public function __construct(
        private readonly int $seconds = 60,
        private readonly bool $releaseOnTimeout = false,
        private readonly int $releaseDelay = 0,
    ) {}

    /**
     * Handle the job.
     *
     * @param Closure(object): mixed $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            // pcntl not available, run without timeout
            return $next($job);
        }

        $timedOut = false;

        // Set up the timeout signal handler
        pcntl_signal(SIGALRM, function () use (&$timedOut, $job): void {
            $timedOut = true;

            if ($this->releaseOnTimeout && method_exists($job, 'release')) {
                $job->release($this->releaseDelay);
            }

            throw new JobTimeoutException(
                \sprintf('Job [%s] exceeded timeout of %d seconds', $job::class, $this->seconds),
            );
        });

        // Set the alarm
        pcntl_alarm($this->seconds);

        try {
            $result = $next($job);

            // Cancel the alarm
            pcntl_alarm(0);

            return $result;
        } catch (JobTimeoutException $e) {
            throw $e;
        } finally {
            // Always cancel the alarm
            pcntl_alarm(0);

            // Restore default signal handler
            pcntl_signal(SIGALRM, SIG_DFL);
        }
    }
}
