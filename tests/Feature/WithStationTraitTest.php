<?php

declare(strict_types=1);

namespace Station\Tests\Feature;

use Station\Testing\WithStation;
use Station\Tests\TestCase;

final class WithStationTraitTest extends TestCase
{
    use WithStation;

    public function testCanFakeStation(): void
    {
        $fake = $this->fakeStation();

        $this->assertNotNull($fake);
        $this->assertNotNull($this->stationFake);
    }

    public function testCanAssertJobDispatched(): void
    {
        $this->fakeStation();

        $job = new class {
            public function handle(): void {}
        };

        $this->stationFake->dispatch($job);

        $this->assertStationDispatched($job::class);
    }

    public function testCanAssertJobNotDispatched(): void
    {
        $this->fakeStation();

        $job = new class {
            public function handle(): void {}
        };

        $this->assertStationNotDispatched($job::class);
    }

    public function testCanAssertNothingDispatched(): void
    {
        $this->fakeStation();

        $this->assertNothingDispatched();
    }

    public function testCanGetDispatchedJobs(): void
    {
        $this->fakeStation();

        $job1 = new class {
            public function handle(): void {}
        };

        $job2 = new class {
            public function handle(): void {}
        };

        $this->stationFake->dispatch($job1);
        $this->stationFake->dispatch($job2);

        $dispatched = $this->getDispatchedJobs();

        $this->assertCount(2, $dispatched);
    }

    public function testCanProcessJobsSynchronously(): void
    {
        $this->fakeStation();

        $job = new class {
            public static bool $handled = false;

            public function handle(): void
            {
                self::$handled = true;
            }
        };

        $job::$handled = false;
        $this->stationFake->dispatch($job);

        $this->assertStationDispatched($job::class);

        $this->processStationJobs();

        $this->assertTrue($job::$handled, 'Job handle() method should have been called');
    }

    public function testCanAssertDispatchedTimes(): void
    {
        $this->fakeStation();

        $job = new class {
            public function handle(): void {}
        };

        $this->stationFake->dispatch($job);
        $this->stationFake->dispatch($job);
        $this->stationFake->dispatch($job);

        $this->assertStationDispatchedTimes($job::class, 3);
    }

    public function testCanAssertBatchDispatched(): void
    {
        $this->fakeStation();

        $job1 = new class {
            public function handle(): void {}
        };
        $job2 = new class {
            public function handle(): void {}
        };

        $this->stationFake->recordBatch([$job1, $job2], ['name' => 'test-batch']);

        $this->assertBatchDispatched();
    }

    public function testCanAssertChainDispatched(): void
    {
        $this->fakeStation();

        $job1 = new class {
            public function handle(): void {}
        };
        $job2 = new class {
            public function handle(): void {}
        };

        $this->stationFake->recordChain([$job1, $job2]);

        $this->assertChainDispatched([$job1::class, $job2::class]);
    }
}
