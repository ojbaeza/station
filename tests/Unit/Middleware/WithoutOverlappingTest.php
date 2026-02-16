<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Middleware;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Station\Middleware\WithoutOverlapping;
use Station\Tests\TestCase;

class WithoutOverlappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('station:overlapping:test-key');
        Cache::forget('station:overlapping:custom-key');
    }

    public function testAllowsFirstJobExecution(): void
    {
        $middleware = new WithoutOverlapping('test-key');

        $job = new class {
            public bool $handled = false;
        };

        $middleware->handle($job, static function ($job) {
            $job->handled = true;

            return 'result';
        });

        $this->assertTrue($job->handled);
    }

    public function testBlocksOverlappingExecution(): void
    {
        // Simulate a running job by setting the lock
        Cache::put('station:overlapping:test-key', true, 300);

        $middleware = new WithoutOverlapping('test-key', releaseOnOverlap: false);

        $job = new class {
            public bool $handled = false;
        };

        $result = $middleware->handle($job, static function ($job) {
            $job->handled = true;

            return 'result';
        });

        $this->assertFalse($job->handled);
        $this->assertNull($result);
    }

    public function testReleasesJobOnOverlapWhenEnabled(): void
    {
        // Simulate a running job
        Cache::put('station:overlapping:test-key', true, 300);

        $middleware = new WithoutOverlapping('test-key', releaseOnOverlap: true, releaseDelay: 10);

        $job = new class {
            public int $releaseDelay = 0;

            public function release(int $delay): void
            {
                $this->releaseDelay = $delay;
            }
        };

        $middleware->handle($job, static fn() => null);

        $this->assertSame(10, $job->releaseDelay);
    }

    public function testReleasesLockAfterExecution(): void
    {
        $middleware = new WithoutOverlapping('test-key');

        $job = new class {};

        $middleware->handle($job, static fn() => 'result');

        // Lock should be released
        $this->assertFalse(Cache::has('station:overlapping:test-key'));
    }

    public function testReleasesLockEvenOnException(): void
    {
        $middleware = new WithoutOverlapping('test-key');

        $job = new class {};

        try {
            $middleware->handle($job, static function (): void {
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        // Lock should still be released
        $this->assertFalse(Cache::has('station:overlapping:test-key'));
    }

    public function testUsesCustomUniqueIdFromJob(): void
    {
        $middleware = new WithoutOverlapping('default-key');

        $job = new class {
            public bool $handled = false;

            public function uniqueId(): string
            {
                return 'custom-key';
            }
        };

        // Lock custom key
        Cache::put('station:overlapping:custom-key', true, 300);

        $middleware->handle($job, static function ($job) {
            $job->handled = true;

            return 'result';
        });

        // Should be blocked because custom key is locked
        $this->assertFalse($job->handled);
    }

    public function testReturnsResultFromNextClosure(): void
    {
        $middleware = new WithoutOverlapping('test-key');

        $job = new class {};

        $result = $middleware->handle($job, static fn() => 'expected-result');

        $this->assertSame('expected-result', $result);
    }

    public function testCustomExpiryTime(): void
    {
        $middleware = new WithoutOverlapping('test-key', expiresAfter: 60);

        $job = new class {
            public bool $handled = false;
        };

        // Start job (acquires lock with 60 second expiry)
        $middleware->handle($job, static function ($job) {
            $job->handled = true;

            return 'result';
        });

        $this->assertTrue($job->handled);
    }

    public function testDoesNotReleaseJobWithoutReleaseMethod(): void
    {
        // Lock the key
        Cache::put('station:overlapping:test-key', true, 300);

        $middleware = new WithoutOverlapping('test-key', releaseOnOverlap: true);

        $job = new class {
            public bool $handled = false;
        };

        $result = $middleware->handle($job, static function ($job) {
            $job->handled = true;

            return 'result';
        });

        $this->assertFalse($job->handled);
        $this->assertNull($result);
    }
}
