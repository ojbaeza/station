<?php

declare(strict_types=1);

namespace Station\Telemetry;

use Illuminate\Support\Facades\Cache;

/**
 * Internal meter implementation when OpenTelemetry is not available.
 *
 * Stores metrics in cache/memory and provides basic aggregation.
 */
final class InternalMeter implements MeterInterface
{
    /** @var array<string, int> In-memory counters */
    private array $counters = [];

    /** @var array<string, float> In-memory gauges */
    private array $gauges = [];

    /** @var array<string, array<float>> In-memory histograms */
    private array $histograms = [];

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config = [],
    ) {}

    /**
     * Increment a counter.
     *
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, array $labels = [], int $value = 1): void
    {
        $key = $this->buildKey($name, $labels);

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = 0;
        }

        $this->counters[$key] += $value;

        // Persist to cache if enabled
        if ($this->config['persist'] ?? false) {
            $cacheKey = 'station:metrics:counter:' . $key;
            Cache::increment($cacheKey, $value);
        }
    }

    /**
     * Record a value (gauge).
     *
     * @param array<string, string> $labels
     */
    public function recordValue(string $name, float $value, array $labels = []): void
    {
        $key = $this->buildKey($name, $labels);
        $this->gauges[$key] = $value;

        // Persist to cache if enabled
        if ($this->config['persist'] ?? false) {
            $cacheKey = 'station:metrics:gauge:' . $key;
            Cache::put($cacheKey, $value, now()->addHour());
        }
    }

    /**
     * Record a histogram value.
     *
     * @param array<string, string> $labels
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void
    {
        $key = $this->buildKey($name, $labels);

        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = [];
        }

        $this->histograms[$key][] = $value;

        // Keep only last N values
        $maxValues = (int) ($this->config['histogram_max_values'] ?? 1000);

        if (\count($this->histograms[$key]) > $maxValues) {
            $this->histograms[$key] = \array_slice($this->histograms[$key], -$maxValues);
        }
    }

    /**
     * Get a counter value.
     *
     * @param array<string, string> $labels
     */
    public function getCounter(string $name, array $labels = []): int
    {
        $key = $this->buildKey($name, $labels);

        return $this->counters[$key] ?? 0;
    }

    /**
     * Get a gauge value.
     *
     * @param array<string, string> $labels
     */
    public function getGauge(string $name, array $labels = []): ?float
    {
        $key = $this->buildKey($name, $labels);

        return $this->gauges[$key] ?? null;
    }

    /**
     * Get histogram statistics.
     *
     * @param array<string, string> $labels
     * @return array{count: int, sum: float, min: float, max: float, avg: float, p50: float, p95: float, p99: float}|null
     */
    public function getHistogramStats(string $name, array $labels = []): ?array
    {
        $key = $this->buildKey($name, $labels);

        if (!isset($this->histograms[$key]) || empty($this->histograms[$key])) {
            return null;
        }

        $values = $this->histograms[$key];
        sort($values);

        $count = \count($values);
        $sum = array_sum($values);

        return [
            'count' => $count,
            'sum' => $sum,
            'min' => min($values),
            'max' => max($values),
            'avg' => $sum / $count,
            'p50' => $this->percentile($values, 50),
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
        ];
    }

    /**
     * Get all metrics as an array.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        $metrics = [];

        foreach ($this->counters as $key => $value) {
            $metrics['counters'][$key] = $value;
        }

        foreach ($this->gauges as $key => $value) {
            $metrics['gauges'][$key] = $value;
        }

        foreach ($this->histograms as $key => $values) {
            $metrics['histograms'][$key] = $this->getHistogramStats(...$this->parseKey($key));
        }

        return $metrics;
    }

    /**
     * Export metrics in Prometheus format.
     */
    public function exportPrometheus(): string
    {
        $output = '';

        foreach ($this->counters as $key => $value) {
            [$name, $labels] = $this->parseKey($key);
            $labelStr = $this->formatPrometheusLabels($labels);
            $output .= "{$name}{$labelStr} {$value}\n";
        }

        foreach ($this->gauges as $key => $value) {
            [$name, $labels] = $this->parseKey($key);
            $labelStr = $this->formatPrometheusLabels($labels);
            $output .= "{$name}{$labelStr} {$value}\n";
        }

        foreach ($this->histograms as $key => $values) {
            [$name, $labels] = $this->parseKey($key);
            $labelStr = $this->formatPrometheusLabels($labels);
            $stats = $this->getHistogramStats($name, $labels);

            if ($stats !== null) {
                $output .= "{$name}_count{$labelStr} {$stats['count']}\n";
                $output .= "{$name}_sum{$labelStr} {$stats['sum']}\n";
            }
        }

        return $output;
    }

    /**
     * Clear all metrics.
     */
    public function clear(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
    }

    /**
     * Build a unique key from name and labels.
     *
     * @param array<string, string> $labels
     */
    private function buildKey(string $name, array $labels): string
    {
        if (empty($labels)) {
            return $name;
        }

        ksort($labels);
        $labelStr = json_encode($labels);

        return $name . '|' . $labelStr;
    }

    /**
     * Parse a key back to name and labels.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseKey(string $key): array
    {
        if (!str_contains($key, '|')) {
            return [$key, []];
        }

        [$name, $labelStr] = explode('|', $key, 2);

        return [$name, json_decode($labelStr, true) ?? []];
    }

    /**
     * Calculate percentile.
     *
     * @param array<float> $values Sorted values
     */
    private function percentile(array $values, float $percentile): float
    {
        $count = \count($values);
        $index = ($percentile / 100) * ($count - 1);
        $floor = (int) floor($index);
        $fraction = $index - $floor;

        if ($floor + 1 < $count) {
            return $values[$floor] + $fraction * ($values[$floor + 1] - $values[$floor]);
        }

        return $values[$floor];
    }

    /**
     * Format labels for Prometheus.
     *
     * @param array<string, string> $labels
     */
    private function formatPrometheusLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        $parts = [];

        foreach ($labels as $key => $value) {
            $parts[] = $key . '="' . addslashes($value) . '"';
        }

        return '{' . implode(',', $parts) . '}';
    }
}
