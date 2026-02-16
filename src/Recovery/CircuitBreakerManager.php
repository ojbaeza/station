<?php

declare(strict_types=1);

namespace Station\Recovery;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Manages circuit breakers for different services/connections.
 */
final class CircuitBreakerManager
{
    /** @var array<string, CircuitBreaker> */
    private array $breakers = [];

    public function __construct(
        private readonly CacheRepository $cache,
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {}

    /**
     * Get or create a circuit breaker for a service.
     */
    public function for(string $service): CircuitBreaker
    {
        if (!isset($this->breakers[$service])) {
            $this->breakers[$service] = new CircuitBreaker(
                $this->cache,
                $service,
                $this->getConfigFor($service),
            );
        }

        return $this->breakers[$service];
    }

    /**
     * Execute a callback with circuit breaker protection.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @param callable(): T|null $fallback
     * @return T
     *
     * @throws CircuitOpenException
     */
    public function execute(string $service, callable $callback, ?callable $fallback = null): mixed
    {
        $breaker = $this->for($service);

        if (!$breaker->isAvailable()) {
            if ($fallback !== null) {
                return $fallback();
            }

            throw new CircuitOpenException(
                "Circuit breaker '{$service}' is open. Service is unavailable.",
            );
        }

        try {
            $result = $callback();
            $breaker->recordSuccess();

            return $result;
        } catch (Throwable $e) {
            $breaker->recordFailure();

            throw $e;
        }
    }

    /**
     * Get status of all circuit breakers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllStatus(): array
    {
        $status = [];

        foreach ($this->breakers as $name => $breaker) {
            $status[$name] = $breaker->getStatus();
        }

        return $status;
    }

    /**
     * Reset all circuit breakers.
     */
    public function resetAll(): void
    {
        foreach ($this->breakers as $breaker) {
            $breaker->reset();
        }
    }

    /**
     * Get configuration for a specific service.
     *
     * @return array<string, mixed>
     */
    private function getConfigFor(string $service): array
    {
        $defaults = $this->config['defaults'] ?? [
            'failure_threshold' => 5,
            'recovery_timeout' => 60,
            'ttl' => 3600,
        ];

        $serviceConfig = $this->config['services'][$service] ?? [];

        return array_merge($defaults, $serviceConfig);
    }
}
