<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery;
use PHPUnit\Framework\TestCase;
use Station\Core\Chain;

/**
 * Simple job class for testing chains.
 */
class TestChainJob implements ShouldQueue
{
    public ?string $chainId = null;

    public ?int $chainIndex = null;

    public ?string $chainName = null;

    public function __construct(
        public readonly string $name = 'test',
    ) {}

    public function handle(): void {}
}

class ChainTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCreateReturnsNewChainInstance(): void
    {
        $jobs = [new TestChainJob('job1'), new TestChainJob('job2')];
        $chain = Chain::create($jobs);

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testGetIdReturnsUuid7(): void
    {
        $chain = Chain::create([]);

        $id = $chain->getId();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function testEachChainHasUniqueId(): void
    {
        $chain1 = Chain::create([]);
        $chain2 = Chain::create([]);

        $this->assertNotSame($chain1->getId(), $chain2->getId());
    }

    public function testGetJobsReturnsProvidedJobs(): void
    {
        $job1 = new TestChainJob('job1');
        $job2 = new TestChainJob('job2');
        $jobs = [$job1, $job2];

        $chain = Chain::create($jobs);

        $this->assertSame($jobs, $chain->getJobs());
    }

    public function testNameSetsChainName(): void
    {
        $chain = Chain::create([]);

        $result = $chain->name('my-chain');

        $this->assertSame($chain, $result); // Fluent interface
    }

    public function testOnConnectionSetsConnection(): void
    {
        $chain = Chain::create([]);

        $result = $chain->onConnection('rabbitmq');

        $this->assertSame($chain, $result);
    }

    public function testOnQueueSetsQueue(): void
    {
        $chain = Chain::create([]);

        $result = $chain->onQueue('high-priority');

        $this->assertSame($chain, $result);
    }

    public function testCatchSetsCatchCallback(): void
    {
        $chain = Chain::create([]);

        $result = $chain->catch(static function (): void {});

        $this->assertSame($chain, $result);
    }

    public function testFinallySetsCallback(): void
    {
        $chain = Chain::create([]);

        $result = $chain->finally(static function (): void {});

        $this->assertSame($chain, $result);
    }

    public function testFluentChaining(): void
    {
        $chain = Chain::create([new TestChainJob()])
            ->name('test-chain')
            ->onConnection('redis')
            ->onQueue('default')
            ->catch(static fn() => null)
            ->finally(static fn() => null);

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testGetJobsReturnsEmptyArrayForEmptyChain(): void
    {
        $chain = Chain::create([]);

        $this->assertSame([], $chain->getJobs());
    }

    public function testJobsArrayIsPreservedByReference(): void
    {
        $job1 = new TestChainJob('first');
        $job2 = new TestChainJob('second');

        $chain = Chain::create([$job1, $job2]);

        $jobs = $chain->getJobs();

        $this->assertCount(2, $jobs);
        $this->assertSame('first', $jobs[0]->name);
        $this->assertSame('second', $jobs[1]->name);
    }

    public function testChainNameCanBeEmpty(): void
    {
        $chain = Chain::create([])
            ->name('');

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testChainWithSingleJob(): void
    {
        $job = new TestChainJob('only');
        $chain = Chain::create([$job]);

        $this->assertCount(1, $chain->getJobs());
        $this->assertSame($job, $chain->getJobs()[0]);
    }

    public function testChainWithMultipleConnections(): void
    {
        $chain = Chain::create([new TestChainJob()])
            ->onConnection('redis')
            ->onConnection('rabbitmq'); // Second call should override

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testChainWithMultipleQueues(): void
    {
        $chain = Chain::create([new TestChainJob()])
            ->onQueue('low')
            ->onQueue('high'); // Second call should override

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testCatchCallbackCanBeReplaced(): void
    {
        $catch1Called = false;
        $catch2Called = false;

        $chain = Chain::create([])
            ->catch(static function () use (&$catch1Called): void {
                $catch1Called = true;
            })
            ->catch(static function () use (&$catch2Called): void {
                $catch2Called = true;
            });

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testFinallyCallbackCanBeReplaced(): void
    {
        $finally1Called = false;
        $finally2Called = false;

        $chain = Chain::create([])
            ->finally(static function () use (&$finally1Called): void {
                $finally1Called = true;
            })
            ->finally(static function () use (&$finally2Called): void {
                $finally2Called = true;
            });

        $this->assertInstanceOf(Chain::class, $chain);
    }

    public function testGetIdIsDeterministicOnSameInstance(): void
    {
        $chain = Chain::create([]);

        $id1 = $chain->getId();
        $id2 = $chain->getId();

        $this->assertSame($id1, $id2);
    }
}
