<?php

declare(strict_types=1);

namespace Station\Testing\Fakes;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;
use Station\Core\PendingDispatch;

/**
 * Fake Station implementation for testing.
 */
final class StationFake
{
    /** @var Collection<int, array{job: object, queue: string|null, delay: int}> */
    private Collection $dispatched;

    /** @var Collection<int, array{jobs: array<object>, options: array<string, mixed>}> */
    private Collection $batches;

    /** @var Collection<int, array<object>> */
    private Collection $chains;

    public function __construct()
    {
        $this->dispatched = collect();
        $this->batches = collect();
        $this->chains = collect();
    }

    /**
     * Dispatch a job.
     *
     * For testing convenience, the job is recorded immediately.
     * The returned PendingDispatch can still be used for fluent configuration.
     */
    public function dispatch(object $job): PendingDispatch
    {
        // Record immediately for simple testing
        $this->dispatched->push([
            'job' => $job,
            'queue' => null,
            'delay' => 0,
        ]);

        // Return a PendingDispatch that will update the record if needed
        $index = $this->dispatched->count() - 1;

        return new PendingDispatch($job, function (object $dispatchedJob, ?string $queue, int $delay) use ($index): string {
            // Update the record with any fluent configuration
            $this->dispatched->put($index, [
                'job' => $dispatchedJob,
                'queue' => $queue,
                'delay' => $delay,
            ]);

            return 'fake-job-id-' . ($index + 1);
        });
    }

    /**
     * Dispatch a job synchronously (for testing).
     */
    public function dispatchNow(object $job): string
    {
        $this->dispatched->push([
            'job' => $job,
            'queue' => null,
            'delay' => 0,
        ]);

        return 'fake-job-id-' . $this->dispatched->count();
    }

    /**
     * Create a pending dispatch.
     */
    public function job(object $job): PendingDispatch
    {
        return $this->dispatch($job);
    }

    /**
     * Record a batch dispatch.
     *
     * @param array<object> $jobs
     * @param array<string, mixed> $options
     */
    public function recordBatch(array $jobs, array $options = []): void
    {
        $this->batches->push([
            'jobs' => $jobs,
            'options' => $options,
        ]);
    }

    /**
     * Record a chain dispatch.
     *
     * @param array<object> $jobs
     */
    public function recordChain(array $jobs): void
    {
        $this->chains->push($jobs);
    }

    /**
     * Assert that a job was dispatched.
     */
    public function assertDispatched(string|callable $job, ?callable $callback = null): void
    {
        if (\is_callable($job)) {
            PHPUnit::assertTrue(
                $this->dispatched->filter($job)->isNotEmpty(),
                'The expected job was not dispatched.',
            );

            return;
        }

        $matching = $this->dispatched->filter(static function (array $item) use ($job, $callback): bool {
            if (!$item['job'] instanceof $job) {
                return false;
            }

            return $callback === null || $callback($item['job']);
        });

        PHPUnit::assertTrue(
            $matching->isNotEmpty(),
            "The expected [{$job}] job was not dispatched.",
        );
    }

    /**
     * Assert that a job was not dispatched.
     */
    public function assertNotDispatched(string|callable $job, ?callable $callback = null): void
    {
        if (\is_callable($job)) {
            PHPUnit::assertTrue(
                $this->dispatched->filter($job)->isEmpty(),
                'The unexpected job was dispatched.',
            );

            return;
        }

        $matching = $this->dispatched->filter(static function (array $item) use ($job, $callback): bool {
            if (!$item['job'] instanceof $job) {
                return false;
            }

            return $callback === null || $callback($item['job']);
        });

        PHPUnit::assertTrue(
            $matching->isEmpty(),
            "The unexpected [{$job}] job was dispatched.",
        );
    }

    /**
     * Assert that no jobs were dispatched.
     */
    public function assertNothingDispatched(): void
    {
        PHPUnit::assertTrue(
            $this->dispatched->isEmpty(),
            'Jobs were dispatched unexpectedly.',
        );
    }

    /**
     * Assert that a job was dispatched a specific number of times.
     */
    public function assertDispatchedTimes(string $job, int $times = 1): void
    {
        $count = $this->dispatched->filter(static fn(array $item): bool => $item['job'] instanceof $job)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$job}] job was dispatched {$count} times instead of {$times} times.",
        );
    }

    /**
     * Assert that a batch was dispatched.
     */
    public function assertBatchDispatched(?callable $callback = null): void
    {
        if ($callback !== null) {
            PHPUnit::assertTrue(
                $this->batches->filter($callback)->isNotEmpty(),
                'The expected batch was not dispatched.',
            );

            return;
        }

        PHPUnit::assertTrue(
            $this->batches->isNotEmpty(),
            'No batches were dispatched.',
        );
    }

    /**
     * Assert that a chain was dispatched.
     *
     * @param array<string> $expectedChain
     */
    public function assertChainDispatched(array $expectedChain): void
    {
        $found = $this->chains->contains(static function (array $chain) use ($expectedChain): bool {
            if (\count($chain) !== \count($expectedChain)) {
                return false;
            }

            foreach ($chain as $index => $job) {
                if (!$job instanceof $expectedChain[$index]) {
                    return false;
                }
            }

            return true;
        });

        PHPUnit::assertTrue($found, 'The expected job chain was not dispatched.');
    }

    /**
     * Get all dispatched jobs.
     *
     * @return array<int, object>
     */
    public function getDispatched(?string $type = null): array
    {
        $jobs = $this->dispatched->map(static fn(array $item): object => $item['job']);

        if ($type !== null) {
            $jobs = $jobs->filter(static fn(object $job): bool => $job instanceof $type);
        }

        return $jobs->values()->all();
    }

    /**
     * Process all pending jobs synchronously.
     */
    public function processAll(): void
    {
        foreach ($this->dispatched as $item) {
            $job = $item['job'];

            if (method_exists($job, 'handle')) {
                app()->call([$job, 'handle']);
            }
        }
    }

    /**
     * Clear all recorded dispatches.
     */
    public function clear(): void
    {
        $this->dispatched = collect();
        $this->batches = collect();
        $this->chains = collect();
    }

    /**
     * Stub methods for compatibility.
     */
    public function retry(string $jobId): void
    {
        // No-op in fake
    }

    public function cancel(string $jobId): void
    {
        // No-op in fake
    }

    public function find(string $jobId): null
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'dispatched' => $this->dispatched->count(),
            'batches' => $this->batches->count(),
            'chains' => $this->chains->count(),
        ];
    }
}
