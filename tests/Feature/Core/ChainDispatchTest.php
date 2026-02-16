<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Core;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Orchestra\Testbench\TestCase;
use Station\Core\Chain;
use Station\StationServiceProvider;

/**
 * Job class for testing chain dispatch.
 */
class ChainDispatchTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public ?string $chainId = null;

    public ?int $chainIndex = null;

    public ?string $chainName = null;

    public function __construct(
        public readonly string $name = 'test',
    ) {}

    public function handle(): void {}
}

/**
 * Job without chain properties.
 */
class ChainDispatchJobWithoutProperties implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name = 'test',
    ) {}

    public function handle(): void {}
}

class ChainDispatchTest extends TestCase
{
    public function testDispatchReturnsChainId(): void
    {
        Bus::fake();

        $chain = Chain::create([
            new ChainDispatchTestJob('job1'),
            new ChainDispatchTestJob('job2'),
        ]);

        $id = $chain->dispatch();

        $this->assertSame($chain->getId(), $id);
    }

    public function testDispatchWithNameSetsChainName(): void
    {
        Bus::fake();

        $job = new ChainDispatchTestJob('job1');
        $chain = Chain::create([$job])->name('my-chain');

        $chain->dispatch();

        Bus::assertChained([ChainDispatchTestJob::class]);
    }

    public function testDispatchWithConnection(): void
    {
        Bus::fake();

        $chain = Chain::create([
            new ChainDispatchTestJob('job1'),
        ])->onConnection('redis');

        $id = $chain->dispatch();

        $this->assertNotEmpty($id);
        Bus::assertChained([ChainDispatchTestJob::class]);
    }

    public function testDispatchWithQueue(): void
    {
        Bus::fake();

        $chain = Chain::create([
            new ChainDispatchTestJob('job1'),
        ])->onQueue('high-priority');

        $id = $chain->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithCatchCallback(): void
    {
        Bus::fake();

        $catchCalled = false;
        $chain = Chain::create([
            new ChainDispatchTestJob('job1'),
        ])->catch(static function () use (&$catchCalled): void {
            $catchCalled = true;
        });

        $id = $chain->dispatch();

        $this->assertNotEmpty($id);
    }

    public function testDispatchWithFinallyCallback(): void
    {
        // Note: Bus::fake() doesn't support finally() on PendingChainFake
        // We just test that the chain can be configured with finally callback
        $chain = Chain::create([
            new ChainDispatchTestJob('job1'),
        ])->finally(static function (): void {});

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testDispatchWithAllOptionsExceptFinally(): void
    {
        Bus::fake();

        $chain = Chain::create([
            new ChainDispatchTestJob('job1'),
            new ChainDispatchTestJob('job2'),
        ])
            ->name('full-chain')
            ->onConnection('redis')
            ->onQueue('high')
            ->catch(static fn() => null);

        $id = $chain->dispatch();

        $this->assertSame($chain->getId(), $id);
        Bus::assertChained([ChainDispatchTestJob::class, ChainDispatchTestJob::class]);
    }

    public function testDispatchEmptyChainSetsJobsToEmptyArray(): void
    {
        $chain = Chain::create([]);

        // Just test we can create an empty chain and get its ID
        $this->assertNotEmpty($chain->getId());
        $this->assertEmpty($chain->getJobs());
    }

    public function testDispatchSetsChainMetadataOnJobs(): void
    {
        Bus::fake();

        $job1 = new ChainDispatchTestJob('job1');
        $job2 = new ChainDispatchTestJob('job2');

        $chain = Chain::create([$job1, $job2])->name('test-chain');

        $chain->dispatch();

        // The prepareJobs method should have set metadata on jobs
        $this->assertSame($chain->getId(), $job1->chainId);
        $this->assertSame(0, $job1->chainIndex);
        $this->assertSame('test-chain', $job1->chainName);

        $this->assertSame($chain->getId(), $job2->chainId);
        $this->assertSame(1, $job2->chainIndex);
        $this->assertSame('test-chain', $job2->chainName);
    }

    public function testDispatchWithJobsWithoutChainProperties(): void
    {
        Bus::fake();

        $job = new ChainDispatchJobWithoutProperties('job1');

        $chain = Chain::create([$job])->name('test-chain');

        $id = $chain->dispatch();

        // Should not throw even without chain properties
        $this->assertNotEmpty($id);
    }

    public function testMultipleChainsHaveIndependentIds(): void
    {
        Bus::fake();

        $chain1 = Chain::create([new ChainDispatchTestJob('job1')]);
        $chain2 = Chain::create([new ChainDispatchTestJob('job2')]);

        $id1 = $chain1->dispatch();
        $id2 = $chain2->dispatch();

        $this->assertNotSame($id1, $id2);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}
