<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Recovery;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Station\Recovery\CircuitBreaker;

class CircuitBreakerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = Mockery::mock(CacheRepository::class);
    }

    public function testIsAvailableReturnsTrueWhenClosed(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertTrue($breaker->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenOpenAndRecoveryTimeNotPassed(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('open');

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:tripped_at')
            ->andReturn(time()); // Just tripped

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertFalse($breaker->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenHalfOpen(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('half_open');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertTrue($breaker->isAvailable());
    }

    public function testRecordSuccessResetsFailureCountWhenClosed(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, 3600)
            ->once();

        $breaker = new CircuitBreaker($this->cache, 'test');
        $breaker->recordSuccess();
    }

    public function testRecordSuccessClosesCircuitWhenHalfOpen(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('half_open');

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:state', 'closed', 3600)
            ->once();

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, 3600)
            ->once();

        $this->cache->shouldReceive('forget')
            ->with('station:circuit_breaker:test:tripped_at')
            ->once();

        $breaker = new CircuitBreaker($this->cache, 'test');
        $breaker->recordSuccess();
    }

    public function testRecordFailureIncrementsFailureCount(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('has')
            ->with('station:circuit_breaker:test:failures')
            ->andReturn(false);

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, 3600)
            ->once();

        $this->cache->shouldReceive('increment')
            ->with('station:circuit_breaker:test:failures')
            ->once()
            ->andReturn(1);

        $breaker = new CircuitBreaker($this->cache, 'test');
        $breaker->recordFailure();
    }

    public function testRecordFailureTripsCircuitWhenThresholdReached(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('has')
            ->with('station:circuit_breaker:test:failures')
            ->andReturn(true);

        $this->cache->shouldReceive('increment')
            ->with('station:circuit_breaker:test:failures')
            ->once()
            ->andReturn(5); // Returns 5 (threshold)

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:state', 'open', 3600)
            ->once();

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:tripped_at', Mockery::type('int'), 3600)
            ->once();

        $breaker = new CircuitBreaker($this->cache, 'test');
        $breaker->recordFailure();
    }

    public function testRecordFailureReopensCircuitWhenHalfOpen(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('half_open');

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:state', 'open', 3600)
            ->once();

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:tripped_at', Mockery::type('int'), 3600)
            ->once();

        $breaker = new CircuitBreaker($this->cache, 'test');
        $breaker->recordFailure();
    }

    public function testTripOpensCircuit(): void
    {
        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:state', 'open', 3600)
            ->once();

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:tripped_at', Mockery::type('int'), 3600)
            ->once();

        $breaker = new CircuitBreaker($this->cache, 'test');
        $breaker->trip();
    }

    public function testResetClosesCircuit(): void
    {
        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:state', 'closed', 3600)
            ->once();

        $this->cache->shouldReceive('put')
            ->with('station:circuit_breaker:test:failures', 0, 3600)
            ->once();

        $this->cache->shouldReceive('forget')
            ->with('station:circuit_breaker:test:tripped_at')
            ->once();

        $breaker = new CircuitBreaker($this->cache, 'test');
        $breaker->reset();
    }

    public function testGetStateReturnsCurrentState(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('open');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertSame('open', $breaker->getState());
    }

    public function testGetFailureCountReturnsCurrentCount(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:failures', 0)
            ->andReturn(3);

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertSame(3, $breaker->getFailureCount());
    }

    public function testIsOpenReturnsTrueWhenOpen(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('open');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertTrue($breaker->isOpen());
    }

    public function testIsOpenReturnsFalseWhenClosed(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertFalse($breaker->isOpen());
    }

    public function testIsClosedReturnsTrueWhenClosed(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('closed');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertTrue($breaker->isClosed());
    }

    public function testIsClosedReturnsFalseWhenOpen(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('open');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertFalse($breaker->isClosed());
    }

    public function testIsHalfOpenReturnsTrueWhenHalfOpen(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:test:state', 'closed')
            ->andReturn('half_open');

        $breaker = new CircuitBreaker($this->cache, 'test');

        $this->assertTrue($breaker->isHalfOpen());
    }

    public function testGetStatusReturnsCompleteStatus(): void
    {
        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:rabbitmq:state', 'closed')
            ->andReturn('open');

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:rabbitmq:failures', 0)
            ->andReturn(5);

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:rabbitmq:tripped_at')
            ->andReturn(1234567890);

        $breaker = new CircuitBreaker($this->cache, 'rabbitmq');
        $status = $breaker->getStatus();

        $this->assertSame('rabbitmq', $status['name']);
        $this->assertSame('open', $status['state']);
        $this->assertSame(5, $status['failures']);
        $this->assertSame(5, $status['failure_threshold']);
        $this->assertSame(60, $status['recovery_timeout']);
        $this->assertSame(1234567890, $status['tripped_at']);
    }

    public function testCustomConfigOverridesDefaults(): void
    {
        $config = [
            'failure_threshold' => 10,
            'recovery_timeout' => 120,
            'ttl' => 7200,
        ];

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:custom:state', 'closed')
            ->andReturn('closed');

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:custom:failures', 0)
            ->andReturn(0);

        $this->cache->shouldReceive('get')
            ->with('station:circuit_breaker:custom:tripped_at')
            ->andReturn(null);

        $breaker = new CircuitBreaker($this->cache, 'custom', $config);
        $status = $breaker->getStatus();

        $this->assertSame(10, $status['failure_threshold']);
        $this->assertSame(120, $status['recovery_timeout']);
    }
}
