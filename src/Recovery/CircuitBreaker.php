<?php

declare(strict_types=1);

namespace Station\Recovery;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Station\Enums\CircuitState;

/**
 * Circuit Breaker implementation for queue connections.
 *
 * States:
 * - CLOSED: Normal operation, requests flow through
 * - OPEN: Failing, requests are blocked
 * - HALF_OPEN: Testing if service recovered
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $name,
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {}

    /**
     * Check if the circuit allows requests through.
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === CircuitState::Closed->value) {
            return true;
        }

        if ($state === CircuitState::Open->value) {
            // Check if we should transition to half-open
            if ($this->shouldAttemptReset()) {
                $this->transitionTo(CircuitState::HalfOpen->value);

                return true;
            }

            return false;
        }

        // Half-open: allow one request through
        return true;
    }

    /**
     * Record a successful operation.
     */
    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state === CircuitState::HalfOpen->value) {
            // Success in half-open means we can close the circuit
            $this->reset();

            return;
        }

        // Reset failure count on success
        $this->cache->put($this->key('failures'), 0, $this->getTtl());
    }

    /**
     * Record a failed operation.
     */
    public function recordFailure(): void
    {
        $state = $this->getState();

        if ($state === CircuitState::HalfOpen->value) {
            // Failure in half-open means we go back to open
            $this->trip();

            return;
        }

        $failures = $this->incrementFailures();

        if ($failures >= $this->getFailureThreshold()) {
            $this->trip();
        }
    }

    /**
     * Trip the circuit breaker (open the circuit).
     */
    public function trip(): void
    {
        $this->transitionTo(CircuitState::Open->value);
        $this->cache->put($this->key('tripped_at'), now()->timestamp, $this->getTtl());
    }

    /**
     * Reset the circuit breaker (close the circuit).
     */
    public function reset(): void
    {
        $this->transitionTo(CircuitState::Closed->value);
        $this->cache->put($this->key('failures'), 0, $this->getTtl());
        $this->cache->forget($this->key('tripped_at'));
    }

    /**
     * Get the current state of the circuit.
     */
    public function getState(): string
    {
        return $this->cache->get($this->key('state'), CircuitState::Closed->value);
    }

    /**
     * Get the number of failures.
     */
    public function getFailureCount(): int
    {
        return (int) $this->cache->get($this->key('failures'), 0);
    }

    /**
     * Check if the circuit breaker is open.
     */
    public function isOpen(): bool
    {
        return $this->getState() === CircuitState::Open->value;
    }

    /**
     * Check if the circuit breaker is closed.
     */
    public function isClosed(): bool
    {
        return $this->getState() === CircuitState::Closed->value;
    }

    /**
     * Check if the circuit breaker is half-open.
     */
    public function isHalfOpen(): bool
    {
        return $this->getState() === CircuitState::HalfOpen->value;
    }

    /**
     * Get circuit breaker status.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->getState(),
            'failures' => $this->getFailureCount(),
            'failure_threshold' => $this->getFailureThreshold(),
            'recovery_timeout' => $this->getRecoveryTimeout(),
            'tripped_at' => $this->cache->get($this->key('tripped_at')),
        ];
    }

    /**
     * Transition to a new state.
     */
    private function transitionTo(string $state): void
    {
        $this->cache->put($this->key('state'), $state, $this->getTtl());
    }

    /**
     * Increment and return the failure count.
     */
    private function incrementFailures(): int
    {
        $key = $this->key('failures');
        $failures = (int) $this->cache->get($key, 0);
        $failures++;
        $this->cache->put($key, $failures, $this->getTtl());

        return $failures;
    }

    /**
     * Check if we should attempt to reset the circuit.
     */
    private function shouldAttemptReset(): bool
    {
        $trippedAt = $this->cache->get($this->key('tripped_at'));

        if ($trippedAt === null) {
            return true;
        }

        return now()->timestamp - $trippedAt >= $this->getRecoveryTimeout();
    }

    /**
     * Get the failure threshold.
     */
    private function getFailureThreshold(): int
    {
        return $this->config['failure_threshold'] ?? 5;
    }

    /**
     * Get the recovery timeout in seconds.
     */
    private function getRecoveryTimeout(): int
    {
        return $this->config['recovery_timeout'] ?? 60;
    }

    /**
     * Get the cache TTL.
     */
    private function getTtl(): int
    {
        return $this->config['ttl'] ?? 3600;
    }

    /**
     * Generate a cache key.
     */
    private function key(string $suffix): string
    {
        return "station:circuit_breaker:{$this->name}:{$suffix}";
    }
}
