<?php

declare(strict_types=1);

namespace Station\Testing;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Station\Core\JobManager;
use Station\Testing\Fakes\StationFake;

/**
 * Testing trait for Station.
 *
 * Usage:
 * class MyJobTest extends TestCase
 * {
 *     use WithStation;
 *
 *     public function testJobIsDispatched(): void
 *     {
 *         $this->fakeStation();
 *
 *         MyJob::dispatch();
 *
 *         $this->assertStationDispatched(MyJob::class);
 *     }
 * }
 */
trait WithStation
{
    protected ?StationFake $stationFake = null;

    /**
     * Set up Station fakes for testing.
     */
    protected function fakeStation(): StationFake
    {
        $this->stationFake = new StationFake();

        // Bind the fake to the 'station' alias only
        // We don't bind to JobManager::class to avoid breaking command resolution
        $this->app->instance('station', $this->stationFake);
        $this->app->instance('station.fake', $this->stationFake);

        // Also fake Laravel's queue
        Queue::fake();
        Bus::fake();

        return $this->stationFake;
    }

    /**
     * Assert that a job was dispatched.
     */
    protected function assertStationDispatched(string|callable $job, ?callable $callback = null): void
    {
        $this->ensureFaked();

        $this->stationFake->assertDispatched($job, $callback);
    }

    /**
     * Assert that a job was not dispatched.
     */
    protected function assertStationNotDispatched(string|callable $job, ?callable $callback = null): void
    {
        $this->ensureFaked();

        $this->stationFake->assertNotDispatched($job, $callback);
    }

    /**
     * Assert that no jobs were dispatched.
     */
    protected function assertNothingDispatched(): void
    {
        $this->ensureFaked();

        $this->stationFake->assertNothingDispatched();
    }

    /**
     * Assert that a job was dispatched a specific number of times.
     */
    protected function assertStationDispatchedTimes(string $job, int $times = 1): void
    {
        $this->ensureFaked();

        $this->stationFake->assertDispatchedTimes($job, $times);
    }

    /**
     * Assert that a batch was dispatched.
     */
    protected function assertBatchDispatched(?callable $callback = null): void
    {
        $this->ensureFaked();

        $this->stationFake->assertBatchDispatched($callback);
    }

    /**
     * Assert that a chain was dispatched.
     *
     * @param array<string> $expectedChain
     */
    protected function assertChainDispatched(array $expectedChain): void
    {
        $this->ensureFaked();

        $this->stationFake->assertChainDispatched($expectedChain);
    }

    /**
     * Get all dispatched jobs.
     *
     * @return array<int, object>
     */
    protected function getDispatchedJobs(?string $type = null): array
    {
        $this->ensureFaked();

        return $this->stationFake->getDispatched($type);
    }

    /**
     * Process pending jobs synchronously for testing.
     */
    protected function processStationJobs(): void
    {
        $this->ensureFaked();

        $this->stationFake->processAll();
    }

    /**
     * Ensure Station has been faked.
     */
    private function ensureFaked(): void
    {
        if ($this->stationFake === null) {
            $this->fail('Station has not been faked. Call fakeStation() before making assertions.');
        }
    }
}
