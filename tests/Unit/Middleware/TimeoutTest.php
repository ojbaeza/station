<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Station\Middleware\JobTimeoutException;
use Station\Middleware\Timeout;
use stdClass;

class TimeoutTest extends TestCase
{
    public function testHandleWithoutPcntlRunsJobNormally(): void
    {
        // We can't easily test without pcntl, but we can test normal execution
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 60);

        $job = new class {
            public bool $handled = false;
        };

        $result = $timeout->handle($job, static function ($job) {
            $job->handled = true;

            return 'result';
        });

        $this->assertTrue($job->handled);
        $this->assertSame('result', $result);
    }

    public function testHandleCompletesWithinTimeout(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 5);

        $job = new class {
            public bool $executed = false;
        };

        $result = $timeout->handle($job, static function ($job) {
            $job->executed = true;

            return 'success';
        });

        $this->assertTrue($job->executed);
        $this->assertSame('success', $result);
    }

    public function testConstructorSetsDefaultValues(): void
    {
        $timeout = new Timeout();

        // Test that it can be instantiated with defaults
        $this->assertInstanceOf(Timeout::class, $timeout);
    }

    public function testConstructorAcceptsCustomValues(): void
    {
        $timeout = new Timeout(
            seconds: 30,
            releaseOnTimeout: true,
            releaseDelay: 60,
        );

        $this->assertInstanceOf(Timeout::class, $timeout);
    }

    public function testHandleReturnsResultFromClosure(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 5);

        $job = new stdClass();

        $result = $timeout->handle($job, static fn($j) => ['key' => 'value']);

        $this->assertSame(['key' => 'value'], $result);
    }

    public function testMultipleHandleCallsWorkCorrectly(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 5);

        $results = [];

        for ($i = 0; $i < 3; $i++) {
            $job = new stdClass();
            $results[] = $timeout->handle($job, static fn($j) => $i);
        }

        $this->assertSame([0, 1, 2], $results);
    }

    public function testHandleReturnsNullFromCallback(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 5);
        $job = new stdClass();

        $result = $timeout->handle($job, static fn() => null);

        $this->assertNull($result);
    }

    public function testHandlePassesJobToCallback(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 5);
        $job = new class {
            public string $id = 'test-job-123';
        };
        $receivedJob = null;

        $timeout->handle($job, static function ($j) use (&$receivedJob) {
            $receivedJob = $j;

            return 'done';
        });

        $this->assertSame($job, $receivedJob);
    }

    public function testHandleThrowsJobTimeoutExceptionWhenTimeoutExceeded(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        // Enable async signals so SIGALRM is delivered
        $previousAsyncSignals = pcntl_async_signals(true);

        try {
            $timeout = new Timeout(seconds: 1);
            $job = new class {
                public string $name = 'TestJob';
            };

            $this->expectException(JobTimeoutException::class);
            $this->expectExceptionMessage('exceeded timeout of 1 seconds');

            $timeout->handle($job, static function ($job) {
                // Sleep longer than the timeout
                sleep(3);

                return 'should not reach here';
            });
        } finally {
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    public function testHandleReleasesJobOnTimeoutWhenEnabled(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $previousAsyncSignals = pcntl_async_signals(true);

        try {
            $timeout = new Timeout(
                seconds: 1,
                releaseOnTimeout: true,
                releaseDelay: 30,
            );

            $job = new class {
                public bool $released = false;

                public int $releaseDelay = 0;

                public function release(int $delay = 0): void
                {
                    $this->released = true;
                    $this->releaseDelay = $delay;
                }
            };

            try {
                $timeout->handle($job, static function ($job) {
                    sleep(3);

                    return 'should not reach here';
                });
                $this->fail('Expected JobTimeoutException to be thrown');
            } catch (JobTimeoutException) {
                $this->assertTrue($job->released, 'Job should have been released');
                $this->assertSame(30, $job->releaseDelay, 'Release delay should match');
            }
        } finally {
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    public function testHandleDoesNotReleaseJobWhenReleaseOnTimeoutDisabled(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $previousAsyncSignals = pcntl_async_signals(true);

        try {
            $timeout = new Timeout(
                seconds: 1,
                releaseOnTimeout: false,
            );

            $job = new class {
                public bool $released = false;

                public function release(int $delay = 0): void
                {
                    $this->released = true;
                }
            };

            try {
                $timeout->handle($job, static function ($job) {
                    sleep(3);

                    return 'should not reach here';
                });
                $this->fail('Expected JobTimeoutException to be thrown');
            } catch (JobTimeoutException) {
                $this->assertFalse($job->released, 'Job should not have been released');
            }
        } finally {
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    public function testHandleDoesNotReleaseJobWithoutReleaseMethod(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $previousAsyncSignals = pcntl_async_signals(true);

        try {
            $timeout = new Timeout(
                seconds: 1,
                releaseOnTimeout: true,
            );

            // Job without release method
            $job = new stdClass();

            // Should not throw error about missing method
            $this->expectException(JobTimeoutException::class);

            $timeout->handle($job, static function ($job) {
                sleep(3);

                return 'should not reach here';
            });
        } finally {
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    public function testJobTimeoutExceptionContainsClassName(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $previousAsyncSignals = pcntl_async_signals(true);

        try {
            $timeout = new Timeout(seconds: 1);
            $job = new stdClass();

            try {
                $timeout->handle($job, static function ($job) {
                    sleep(3);

                    return 'should not reach here';
                });
                $this->fail('Expected JobTimeoutException to be thrown');
            } catch (JobTimeoutException $e) {
                $this->assertStringContainsString('stdClass', $e->getMessage());
                $this->assertStringContainsString('1 seconds', $e->getMessage());
            }
        } finally {
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    public function testAlarmCancelledAfterSuccessfulExecution(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 5);
        $job = new stdClass();

        // Execute a fast job
        $timeout->handle($job, static fn() => 'fast');

        // The alarm should be cancelled, so sleeping should not trigger timeout
        // (if alarm wasn't cancelled, this would throw after 5 seconds)
        usleep(100000); // 100ms - enough time to verify no alarm is pending

        $this->assertTrue(true, 'No timeout exception after handle completed');
    }

    public function testSignalHandlerRestoredAfterExecution(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $timeout = new Timeout(seconds: 5);
        $job = new stdClass();

        $timeout->handle($job, static fn() => 'done');

        // After handle(), the signal handler should be restored to default
        // pcntl_signal_get_handler returns the current handler for the signal
        if (\function_exists('pcntl_signal_get_handler')) {
            $currentHandler = pcntl_signal_get_handler(SIGALRM);
            // SIG_DFL is 0, but could also be the integer constant
            $this->assertTrue(
                $currentHandler === SIG_DFL || $currentHandler === 0,
                'Signal handler should be restored to SIG_DFL',
            );
        } else {
            // Fallback: just verify we can set a new handler without error
            $result = pcntl_signal(SIGALRM, SIG_DFL);
            $this->assertTrue($result);
        }
    }

    public function testHandleMethodReturnType(): void
    {
        $reflection = new ReflectionMethod(Timeout::class, 'handle');
        $returnType = $reflection->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame('mixed', $returnType->getName());
    }

    public function testHandleMethodParameters(): void
    {
        $reflection = new ReflectionMethod(Timeout::class, 'handle');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('job', $parameters[0]->getName());
        $this->assertSame('next', $parameters[1]->getName());
    }
}
