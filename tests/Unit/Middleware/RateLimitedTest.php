<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Middleware;

use Illuminate\Cache\RateLimiter;
use Station\Middleware\RateLimited;
use Station\Tests\TestCase;

class RateLimitedTest extends TestCase
{
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = $this->app->make(RateLimiter::class);
        $this->limiter->clear('station:rate_limit:test-key');
    }

    public function testAllowsJobWhenUnderRateLimit(): void
    {
        $middleware = new RateLimited('test-key', 10, 60);

        $job = new class {
            public bool $handled = false;
        };

        $middleware->handle($job, static function ($job) {
            $job->handled = true;

            return 'result';
        });

        $this->assertTrue($job->handled);
    }

    public function testBlocksJobWhenOverRateLimit(): void
    {
        $middleware = new RateLimited('test-key', 2, 60, releaseOnLimit: false);

        $job = new class {
            public int $handleCount = 0;
        };

        // First two should work
        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        // Third should be blocked
        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        $this->assertSame(2, $job->handleCount);
    }

    public function testReleasesJobWhenOverLimitAndReleaseEnabled(): void
    {
        $middleware = new RateLimited('test-key', 1, 60, releaseOnLimit: true, releaseDelay: 30);

        $job = new class {
            public int $handleCount = 0;

            public int $releaseDelay = 0;

            public function release(int $delay): void
            {
                $this->releaseDelay = $delay;
            }
        };

        // First should work
        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        // Second should release
        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        $this->assertSame(1, $job->handleCount);
        $this->assertSame(30, $job->releaseDelay);
    }

    public function testUsesCustomRateLimiterKeyFromJob(): void
    {
        $middleware = new RateLimited('default-key', 1, 60, releaseOnLimit: false);

        $job = new class {
            public int $handleCount = 0;

            public function rateLimiterKey(): string
            {
                return 'custom-key';
            }
        };

        // Clear custom key
        $this->limiter->clear('station:rate_limit:custom-key');

        // First should work
        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        // Second should be blocked
        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        $this->assertSame(1, $job->handleCount);
    }

    public function testReturnsResultFromNextClosure(): void
    {
        $middleware = new RateLimited('test-key', 10, 60);

        $job = new class {};

        $result = $middleware->handle($job, static fn() => 'expected-result');

        $this->assertSame('expected-result', $result);
    }

    public function testDoesNotReleaseJobWithoutReleaseMethod(): void
    {
        $middleware = new RateLimited('test-key', 1, 60, releaseOnLimit: true);

        $job = new class {
            public int $handleCount = 0;
        };

        // First should work
        $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        // Second should be skipped (no release method)
        $result = $middleware->handle($job, static function ($job) {
            $job->handleCount++;

            return 'result';
        });

        $this->assertSame(1, $job->handleCount);
        $this->assertNull($result);
    }
}
