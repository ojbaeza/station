<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Middleware;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Orchestra\Testbench\TestCase;
use Station\Middleware\Unique;
use Station\StationServiceProvider;
use stdClass;

class UniqueTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        // Use array cache for testing
        $this->cache = new Repository(new ArrayStore());
        $this->app->instance(CacheRepository::class, $this->cache);
    }

    public function testHandleExecutesJobWithoutUniqueId(): void
    {
        $middleware = new Unique();

        $job = new class {
            public bool $executed = false;
        };

        $result = $middleware->handle($job, static function ($j) {
            $j->executed = true;

            return 'success';
        });

        $this->assertTrue($job->executed);
        $this->assertSame('success', $result);
    }

    public function testHandleExecutesJobWithUniqueIdNotLocked(): void
    {
        $middleware = new Unique();

        $job = $this->createUniqueJob();

        $executed = false;
        $result = $middleware->handle($job, static function ($j) use (&$executed) {
            $executed = true;

            return 'executed';
        });

        $this->assertTrue($executed);
        $this->assertSame('executed', $result);
    }

    public function testHandleSkipsJobWhenLocked(): void
    {
        $middleware = new Unique();

        $job = $this->createUniqueJob('locked-job');

        // Lock the job first
        Unique::lock($job);

        $executed = false;
        $result = $middleware->handle($job, static function ($j) use (&$executed) {
            $executed = true;

            return 'executed';
        });

        $this->assertFalse($executed);
        $this->assertNull($result);
    }

    public function testLockCreatesLockInCache(): void
    {
        $job = $this->createUniqueJob('lock-test');

        Unique::lock($job);

        $key = 'station:unique:' . $job::class . ':lock-test';
        $this->assertTrue($this->cache->has($key));
    }

    public function testUnlockRemovesLockFromCache(): void
    {
        $job = $this->createUniqueJob('unlock-test');

        Unique::lock($job);
        $key = 'station:unique:' . $job::class . ':unlock-test';
        $this->assertTrue($this->cache->has($key));

        Unique::unlock($job);
        $this->assertFalse($this->cache->has($key));
    }

    public function testLockWithCustomDuration(): void
    {
        $job = $this->createUniqueJob('duration-test');

        // Lock for 1 hour
        Unique::lock($job, 3600);

        $key = 'station:unique:' . $job::class . ':duration-test';
        $this->assertTrue($this->cache->has($key));
    }

    public function testLockDoesNothingForJobWithoutUniqueId(): void
    {
        $job = new class {
            public bool $property = true;
        };

        // Should not throw
        Unique::lock($job);
        Unique::unlock($job);

        $this->assertTrue($job->property);
    }

    public function testHandleAllowsDifferentUniqueIds(): void
    {
        $middleware = new Unique();

        $job1 = $this->createUniqueJob('id-1');
        $job2 = $this->createUniqueJob('id-2');

        // Lock job 1
        Unique::lock($job1);

        // Job 1 should be skipped
        $result1 = $middleware->handle($job1, static fn($j) => 'job1');
        $this->assertNull($result1);

        // Job 2 should still execute
        $executed = false;
        $result2 = $middleware->handle($job2, static function ($j) use (&$executed) {
            $executed = true;

            return 'job2';
        });
        $this->assertTrue($executed);
        $this->assertSame('job2', $result2);
    }

    public function testConstructorSetsLockDuration(): void
    {
        $middleware = new Unique(lockFor: 1800);

        $this->assertInstanceOf(Unique::class, $middleware);
    }

    public function testConstructorUsesDefaultLockDuration(): void
    {
        $middleware = new Unique();

        $this->assertInstanceOf(Unique::class, $middleware);
    }

    public function testHandleReturnValueFromClosure(): void
    {
        $middleware = new Unique();

        $job = new stdClass();

        $result = $middleware->handle($job, static fn($j) => ['data' => 'test']);

        $this->assertSame(['data' => 'test'], $result);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    private function createUniqueJob(string $uniqueId = 'test-123'): object
    {
        return new class($uniqueId) {
            public function __construct(private readonly string $id) {}

            public function uniqueId(): string
            {
                return $this->id;
            }
        };
    }
}
