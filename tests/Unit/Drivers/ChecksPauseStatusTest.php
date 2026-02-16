<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Drivers\Traits\ChecksPauseStatus;

class ChecksPauseStatusTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // isPaused — cache behavior
    // ──────────────────────────────────────────────────────────────

    public function testIsPausedQueriesDatabaseOnFirstCall(): void
    {
        $sut = new class {
            use ChecksPauseStatus;

            public ?string $connectionName = 'default';
        };

        DB::shouldReceive('table')
            ->once()
            ->with('station_queue_status')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('queue', 'test-queue')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('connection', 'default')
            ->andReturnSelf();

        DB::shouldReceive('value')
            ->once()
            ->with('paused')
            ->andReturn(true);

        $result = $sut->isPaused('test-queue');

        $this->assertTrue($result);
    }

    public function testIsPausedReturnsCachedValueWithinTtl(): void
    {
        $sut = new class {
            use ChecksPauseStatus;

            public ?string $connectionName = 'default';
        };

        // First call queries DB
        DB::shouldReceive('table')
            ->once()
            ->with('station_queue_status')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('queue', 'my-queue')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('connection', 'default')
            ->andReturnSelf();

        DB::shouldReceive('value')
            ->once()
            ->with('paused')
            ->andReturn(false);

        // First call
        $first = $sut->isPaused('my-queue');
        // Second call should use cache (DB is only called once)
        $second = $sut->isPaused('my-queue');

        $this->assertFalse($first);
        $this->assertFalse($second);
    }

    public function testIsPausedCachesPerQueue(): void
    {
        $sut = new class {
            use ChecksPauseStatus;

            public ?string $connectionName = 'default';
        };

        // Queue A query
        DB::shouldReceive('table')
            ->twice()
            ->with('station_queue_status')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('queue', 'queue-a')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('queue', 'queue-b')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->twice()
            ->with('connection', 'default')
            ->andReturnSelf();

        DB::shouldReceive('value')
            ->twice()
            ->with('paused')
            ->andReturn(true, false);

        $resultA = $sut->isPaused('queue-a');
        $resultB = $sut->isPaused('queue-b');

        $this->assertTrue($resultA);
        $this->assertFalse($resultB);
    }

    public function testIsPausedReturnsFalseOnDatabaseException(): void
    {
        $sut = new class {
            use ChecksPauseStatus;

            public ?string $connectionName = 'default';
        };

        DB::shouldReceive('table')
            ->once()
            ->with('station_queue_status')
            ->andThrow(new RuntimeException('DB connection lost'));

        $result = $sut->isPaused('broken-queue');

        $this->assertFalse($result);
    }

    public function testIsPausedUsesConnectionNameProperty(): void
    {
        $sut = new class {
            use ChecksPauseStatus;

            public ?string $connectionName = 'rabbitmq';
        };

        DB::shouldReceive('table')
            ->once()
            ->with('station_queue_status')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('queue', 'emails')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('connection', 'rabbitmq')
            ->andReturnSelf();

        DB::shouldReceive('value')
            ->once()
            ->with('paused')
            ->andReturn(false);

        $sut->isPaused('emails');
    }

    public function testIsPausedDefaultsConnectionToDefaultWhenNull(): void
    {
        $sut = new class {
            use ChecksPauseStatus;

            // Simulates when connectionName is empty (not set by connector)
            public string $connectionName = '';
        };

        DB::shouldReceive('table')
            ->once()
            ->with('station_queue_status')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('queue', 'test')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->once()
            ->with('connection', 'default')
            ->andReturnSelf();

        DB::shouldReceive('value')
            ->once()
            ->with('paused')
            ->andReturn(false);

        $result = $sut->isPaused('test');

        $this->assertFalse($result);
    }
}
