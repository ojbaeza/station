<?php

declare(strict_types=1);

namespace Station\Telemetry;

use OpenTelemetry\API\Globals;

/**
 * OpenTelemetry meter wrapper.
 *
 * Uses the OpenTelemetry PHP SDK when available.
 */
final class OpenTelemetryMeter implements MeterInterface
{
    private mixed $meter = null;

    /** @var array<string, mixed> */
    private array $instruments = [];

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {
        $this->initialize();
    }

    /**
     * Increment a counter.
     *
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, array $labels = [], int $value = 1): void
    {
        if ($this->meter === null) {
            return;
        }

        $counter = $this->getOrCreateCounter($name);
        $counter->add($value, $labels);
    }

    /**
     * Record a value (gauge).
     *
     * @param array<string, string> $labels
     */
    public function recordValue(string $name, float $value, array $labels = []): void
    {
        if ($this->meter === null) {
            return;
        }

        $gauge = $this->getOrCreateGauge($name);
        $gauge->record($value, $labels);
    }

    /**
     * Record a histogram value.
     *
     * @param array<string, string> $labels
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void
    {
        if ($this->meter === null) {
            return;
        }

        $histogram = $this->getOrCreateHistogram($name);
        $histogram->record($value, $labels);
    }

    /**
     * Initialize the OpenTelemetry meter.
     */
    private function initialize(): void
    {
        // Check if OpenTelemetry is available
        if (!class_exists('\OpenTelemetry\SDK\Metrics\MeterProvider')) {
            return;
        }

        // Get or create the meter
        $meterProvider = Globals::meterProvider();
        $this->meter = $meterProvider->getMeter(
            $this->config['service_name'] ?? 'station',
            $this->config['service_version'] ?? '0.1.0',
        );
    }

    /**
     * Get or create a counter instrument.
     */
    private function getOrCreateCounter(string $name): mixed
    {
        $key = 'counter:' . $name;

        if (!isset($this->instruments[$key])) {
            $this->instruments[$key] = $this->meter->createCounter(
                $name,
                'count',
                "Counter for {$name}",
            );
        }

        return $this->instruments[$key];
    }

    /**
     * Get or create a gauge instrument.
     */
    private function getOrCreateGauge(string $name): mixed
    {
        $key = 'gauge:' . $name;

        if (!isset($this->instruments[$key])) {
            $this->instruments[$key] = $this->meter->createObservableGauge(
                $name,
                '1',
                "Gauge for {$name}",
            );
        }

        return $this->instruments[$key];
    }

    /**
     * Get or create a histogram instrument.
     */
    private function getOrCreateHistogram(string $name): mixed
    {
        $key = 'histogram:' . $name;

        if (!isset($this->instruments[$key])) {
            $this->instruments[$key] = $this->meter->createHistogram(
                $name,
                'ms',
                "Histogram for {$name}",
            );
        }

        return $this->instruments[$key];
    }
}
