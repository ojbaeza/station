<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Station\Telemetry\MeterInterface;
use Station\Telemetry\OpenTelemetryMeter;
use stdClass;

class OpenTelemetryMeterTest extends TestCase
{
    public function testImplementsMeterInterface(): void
    {
        $meter = new OpenTelemetryMeter();

        $this->assertInstanceOf(MeterInterface::class, $meter);
    }

    public function testIncrementCounterVariationsDoNotThrowWithoutSdk(): void
    {
        $this->expectNotToPerformAssertions();

        $meter = new OpenTelemetryMeter();

        $meter->incrementCounter('test_counter');
        $meter->incrementCounter('jobs_processed', ['queue' => 'high', 'driver' => 'redis']);
        $meter->incrementCounter('batch_jobs', [], 10);
        $meter->incrementCounter('items_processed', ['type' => 'order'], 25);
    }

    public function testRecordValueVariationsDoNotThrowWithoutSdk(): void
    {
        $this->expectNotToPerformAssertions();

        $meter = new OpenTelemetryMeter();

        $meter->recordValue('current_workers', 5.0);
        $meter->recordValue('queue_depth', 100.0, ['queue' => 'default']);
        $meter->recordValue('temperature', -10.5);
        $meter->recordValue('pending_jobs', 0.0);
    }

    public function testRecordHistogramVariationsDoNotThrowWithoutSdk(): void
    {
        $this->expectNotToPerformAssertions();

        $meter = new OpenTelemetryMeter();

        $meter->recordHistogram('job_duration_ms', 150.5);
        $meter->recordHistogram('job_duration_ms', 250.0, ['job_class' => 'ProcessOrder']);
        $meter->recordHistogram('response_time_ms', 0.5);
        $meter->recordHistogram('batch_duration_ms', 999999.99);
    }

    public function testConstructorAcceptsConfigVariations(): void
    {
        $this->expectNotToPerformAssertions();

        new OpenTelemetryMeter(['service_name' => 'station-test', 'service_version' => '3.0.0']);
        new OpenTelemetryMeter([]);
    }

    public function testMultipleCallsOnSameInstrumentDoNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $meter = new OpenTelemetryMeter();

        $meter->incrementCounter('requests', [], 1);
        $meter->incrementCounter('requests', [], 2);
        $meter->incrementCounter('requests', [], 3);

        $meter->recordValue('memory_mb', 100.0);
        $meter->recordValue('memory_mb', 150.0);
        $meter->recordValue('memory_mb', 200.0);

        $meter->recordHistogram('latency', 10.0);
        $meter->recordHistogram('latency', 20.0);
        $meter->recordHistogram('latency', 30.0);
    }

    public function testSpecialCharactersInInstrumentNamesDoNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $meter = new OpenTelemetryMeter();

        $meter->incrementCounter('station.jobs.processed', ['queue' => 'high']);
        $meter->recordValue('station.workers.active', 5.0);
        $meter->recordHistogram('station.job.duration_ms', 100.0);
    }

    public function testIncrementCounterWithMockedMeter(): void
    {
        $meter = new OpenTelemetryMeter();

        // Create a mock counter
        $mockCounter = new class {
            public int $addCalls = 0;

            public int $lastValue = 0;

            /** @var array<string, string> */
            public array $lastLabels = [];

            /** @param array<string, string> $labels */
            public function add(int $value, array $labels = []): void
            {
                $this->addCalls++;
                $this->lastValue = $value;
                $this->lastLabels = $labels;
            }
        };

        // Create a mock meter that returns our counter
        $mockMeterObj = new class($mockCounter) {
            private mixed $counter;

            public function __construct(mixed $counter)
            {
                $this->counter = $counter;
            }

            public function createCounter(string $name, string $unit, string $description): mixed
            {
                return $this->counter;
            }

            public function createObservableGauge(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function record(float $value, array $labels = []): void {}
                };
            }

            public function createHistogram(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function record(float $value, array $labels = []): void {}
                };
            }
        };

        // Inject the mock meter via reflection
        $reflection = new ReflectionClass($meter);
        $property = $reflection->getProperty('meter');
        $property->setValue($meter, $mockMeterObj);

        // Now test incrementCounter
        $meter->incrementCounter('test_counter', ['queue' => 'high'], 5);

        $this->assertSame(1, $mockCounter->addCalls);
        $this->assertSame(5, $mockCounter->lastValue);
        $this->assertSame(['queue' => 'high'], $mockCounter->lastLabels);
    }

    public function testRecordValueWithMockedMeter(): void
    {
        $meter = new OpenTelemetryMeter();

        // Create a mock gauge
        $mockGauge = new class {
            public int $recordCalls = 0;

            public float $lastValue = 0.0;

            /** @var array<string, string> */
            public array $lastLabels = [];

            /** @param array<string, string> $labels */
            public function record(float $value, array $labels = []): void
            {
                $this->recordCalls++;
                $this->lastValue = $value;
                $this->lastLabels = $labels;
            }
        };

        // Create a mock meter
        $mockMeterObj = new class($mockGauge) {
            private mixed $gauge;

            public function __construct(mixed $gauge)
            {
                $this->gauge = $gauge;
            }

            public function createCounter(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function add(int $value, array $labels = []): void {}
                };
            }

            public function createObservableGauge(string $name, string $unit, string $description): mixed
            {
                return $this->gauge;
            }

            public function createHistogram(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function record(float $value, array $labels = []): void {}
                };
            }
        };

        // Inject the mock meter via reflection
        $reflection = new ReflectionClass($meter);
        $property = $reflection->getProperty('meter');
        $property->setValue($meter, $mockMeterObj);

        // Now test recordValue
        $meter->recordValue('workers_active', 10.5, ['driver' => 'redis']);

        $this->assertSame(1, $mockGauge->recordCalls);
        $this->assertSame(10.5, $mockGauge->lastValue);
        $this->assertSame(['driver' => 'redis'], $mockGauge->lastLabels);
    }

    public function testRecordHistogramWithMockedMeter(): void
    {
        $meter = new OpenTelemetryMeter();

        // Create a mock histogram
        $mockHistogram = new class {
            public int $recordCalls = 0;

            public float $lastValue = 0.0;

            /** @var array<string, string> */
            public array $lastLabels = [];

            /** @param array<string, string> $labels */
            public function record(float $value, array $labels = []): void
            {
                $this->recordCalls++;
                $this->lastValue = $value;
                $this->lastLabels = $labels;
            }
        };

        // Create a mock meter
        $mockMeterObj = new class($mockHistogram) {
            private mixed $histogram;

            public function __construct(mixed $histogram)
            {
                $this->histogram = $histogram;
            }

            public function createCounter(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function add(int $value, array $labels = []): void {}
                };
            }

            public function createObservableGauge(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function record(float $value, array $labels = []): void {}
                };
            }

            public function createHistogram(string $name, string $unit, string $description): mixed
            {
                return $this->histogram;
            }
        };

        // Inject the mock meter via reflection
        $reflection = new ReflectionClass($meter);
        $property = $reflection->getProperty('meter');
        $property->setValue($meter, $mockMeterObj);

        // Now test recordHistogram
        $meter->recordHistogram('job_duration_ms', 250.5, ['job' => 'ProcessOrder']);

        $this->assertSame(1, $mockHistogram->recordCalls);
        $this->assertSame(250.5, $mockHistogram->lastValue);
        $this->assertSame(['job' => 'ProcessOrder'], $mockHistogram->lastLabels);
    }

    public function testInstrumentsCacheWithMockedMeter(): void
    {
        $meter = new OpenTelemetryMeter();

        // Use stdClass to track calls (anonymous classes can't use references)
        $tracker = new stdClass();
        $tracker->createCounterCalls = 0;

        // Create a mock meter that tracks createCounter calls
        $mockMeterObj = new class($tracker) {
            private stdClass $tracker;

            public function __construct(stdClass $tracker)
            {
                $this->tracker = $tracker;
            }

            public function createCounter(string $name, string $unit, string $description): mixed
            {
                $this->tracker->createCounterCalls++;

                return new class {
                    /** @param array<string, string> $labels */
                    public function add(int $value, array $labels = []): void {}
                };
            }

            public function createObservableGauge(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function record(float $value, array $labels = []): void {}
                };
            }

            public function createHistogram(string $name, string $unit, string $description): mixed
            {
                return new class {
                    /** @param array<string, string> $labels */
                    public function record(float $value, array $labels = []): void {}
                };
            }
        };

        // Inject the mock meter via reflection
        $reflection = new ReflectionClass($meter);
        $property = $reflection->getProperty('meter');
        $property->setValue($meter, $mockMeterObj);

        // Call incrementCounter multiple times with same name
        $meter->incrementCounter('same_counter');
        $meter->incrementCounter('same_counter');
        $meter->incrementCounter('same_counter');

        // Should only create counter once (cached)
        $this->assertSame(1, $tracker->createCounterCalls);
    }
}
