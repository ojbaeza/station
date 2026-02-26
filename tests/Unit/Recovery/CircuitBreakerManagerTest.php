<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Station\Recovery\CircuitBreaker;
use Station\Recovery\CircuitBreakerManager;
use Station\Recovery\CircuitOpenException;

class CircuitBreakerManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = Mockery::mock(CacheRepository::class);
    }

    public function testForReturnsCircuitBreakerInstance(): void
    {
        $manager = new CircuitBreakerManager($this->cache);

        $breaker = $manager->for('rabbitmq');

        $this->assertInstanceOf(CircuitBreaker::class, $breaker);
    }

    public function testForReturnsSameBreakerForSameService(): void
    {
        $manager = new CircuitBreakerManager($this->cache);

        $breaker1 = $manager->for('rabbitmq');
        $breaker2 = $manager->for('rabbitmq');

        $this->assertSame($breaker1, $breaker2);
    }

    public function testForReturnsDifferentBreakersForDifferentServices(): void
    {
        $manager = new CircuitBreakerManager($this->cache);

        $breaker1 = $manager->for('rabbitmq');
        $breaker2 = $manager->for('redis');

        $this->assertNotSame($breaker1, $breaker2);
    }

    public function testExecuteSuccessfulCallback(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, Mockery::any())
            ->once();

        $manager = new CircuitBreakerManager($this->cache);

        $result = $manager->execute('test', static fn() => 'success');

        $this->assertSame('success', $result);
    }

    public function testExecuteRecordsFailureOnException(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('has')
            ->with('station:circuit_breaker:test:failures')
            ->andReturn(false);

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, Mockery::any())
            ->once();

        $this->cache->shouldReceive('increment')
            ->with('station:circuit_breaker:test:failures')
            ->once()
            ->andReturn(1);

        $manager = new CircuitBreakerManager($this->cache);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test error');

        $manager->execute('test', static function (): void {
            throw new RuntimeException('Test error');
        });
    }

    public function testExecuteThrowsCircuitOpenExceptionWhenUnavailable(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('open');

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:tripped_at')
            ->andReturn(time()); // Just tripped, not ready to reset

        $manager = new CircuitBreakerManager($this->cache);

        $this->expectException(CircuitOpenException::class);
        $this->expectExceptionMessage("Circuit breaker 'test' is open");

        $manager->execute('test', static fn() => 'should not run');
    }

    public function testExecuteCallsFallbackWhenCircuitOpen(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('open');

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:tripped_at')
            ->andReturn(time());

        $manager = new CircuitBreakerManager($this->cache);

        $result = $manager->execute(
            'test',
            static fn() => 'primary',
            static fn() => 'fallback',
        );

        $this->assertSame('fallback', $result);
    }

    public function testGetAllStatusReturnsEmptyArrayWhenNoBreakers(): void
    {
        $manager = new CircuitBreakerManager($this->cache);

        $status = $manager->getAllStatus();

        $this->assertSame([], $status);
    }

    public function testGetAllStatusReturnsStatusForAllBreakers(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:rabbitmq:state', 'closed')
            ->andReturn('closed');
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:rabbitmq:failures', 0)
            ->andReturn(0);
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:rabbitmq:tripped_at')
            ->andReturn(null);

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:redis:state', 'closed')
            ->andReturn('open');
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:redis:failures', 0)
            ->andReturn(5);
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:redis:tripped_at')
            ->andReturn(1234567890);

        $manager = new CircuitBreakerManager($this->cache);

        // Create breakers first
        $manager->for('rabbitmq');
        $manager->for('redis');

        $status = $manager->getAllStatus();

        $this->assertArrayHasKey('rabbitmq', $status);
        $this->assertArrayHasKey('redis', $status);
        $this->assertSame('closed', $status['rabbitmq']['state']);
        $this->assertSame('open', $status['redis']['state']);
    }

    public function testResetAllResetsAllBreakers(): void
    {
        // Set up expectations for two breakers being reset
        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:service1:state', 'closed', Mockery::any())
            ->once();
        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:service1:failures', 0, Mockery::any())
            ->once();
        $this->cache->shouldReceive('forget')
            ->with('station:circuit_breaker:service1:tripped_at')
            ->once();

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:service2:state', 'closed', Mockery::any())
            ->once();
        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:service2:failures', 0, Mockery::any())
            ->once();
        $this->cache->shouldReceive('forget')
            ->with('station:circuit_breaker:service2:tripped_at')
            ->once();

        $manager = new CircuitBreakerManager($this->cache);

        // Create breakers
        $manager->for('service1');
        $manager->for('service2');

        $manager->resetAll();
    }

    public function testServiceSpecificConfigOverridesDefaults(): void
    {
        $config = [
            'defaults' => [
                'failure_threshold' => 5,
                'recovery_timeout' => 60,
            ],
            'services' => [
                'critical' => [
                    'failure_threshold' => 2,
                    'recovery_timeout' => 10,
                ],
            ],
        ];

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:critical:state', 'closed')
            ->andReturn('closed');
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:critical:failures', 0)
            ->andReturn(0);
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:critical:tripped_at')
            ->andReturn(null);

        $manager = new CircuitBreakerManager($this->cache, $config);
        $breaker = $manager->for('critical');
        $status = $breaker->getStatus();

        $this->assertSame(2, $status['failure_threshold']);
        $this->assertSame(10, $status['recovery_timeout']);
    }

    public function testExecuteWithNullReturn(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, Mockery::any())
            ->once();

        $manager = new CircuitBreakerManager($this->cache);

        $result = $manager->execute('test', static fn() => null);

        $this->assertNull($result);
    }

    public function testExecuteWithArrayReturn(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, Mockery::any())
            ->once();

        $manager = new CircuitBreakerManager($this->cache);

        $expected = ['status' => 'ok', 'data' => [1, 2, 3]];
        $result = $manager->execute('test', static fn() => $expected);

        $this->assertSame($expected, $result);
    }
}
