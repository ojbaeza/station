<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Station\Contracts\MetricsRepositoryInterface;
use Station\Core\PcntlTestState;
use Station\Core\WorkerSupervisor;
use Station\Scaling\AutoScaler;
use Station\Tests\TestCase;

class WorkerSupervisorScalingTest extends TestCase
{
    private MockInterface&Dispatcher $events;

    private MockInterface&Dispatcher $scalerEvents;

    private MockInterface&MetricsRepositoryInterface $metricsRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = Mockery::mock(Dispatcher::class);
        $this->scalerEvents = Mockery::mock(Dispatcher::class);
        $this->scalerEvents->shouldReceive('dispatch')->zeroOrMoreTimes();
        $this->metricsRepository = Mockery::mock(MetricsRepositoryInterface::class);
        $this->metricsRepository->shouldReceive('getRecent')->byDefault()->andReturn([]);
        PcntlTestState::reset();
    }

    protected function tearDown(): void
    {
        PcntlTestState::reset();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<string, array{count: int, minWorkers: int, maxWorkers: int, expected: int}>
     */
    public static function scaleWorkersDataProvider(): array
    {
        return [
            'normal_count_within_bounds' => [
                'count' => 5,
                'minWorkers' => 1,
                'maxWorkers' => 10,
                'expected' => 5,
            ],
            'count_below_min_workers_clamped_to_min' => [
                'count' => 0,
                'minWorkers' => 2,
                'maxWorkers' => 10,
                'expected' => 2,
            ],
            'count_above_max_workers_clamped_to_max' => [
                'count' => 15,
                'minWorkers' => 1,
                'maxWorkers' => 10,
                'expected' => 10,
            ],
            'count_equals_min_workers' => [
                'count' => 1,
                'minWorkers' => 1,
                'maxWorkers' => 10,
                'expected' => 1,
            ],
            'count_equals_max_workers' => [
                'count' => 10,
                'minWorkers' => 1,
                'maxWorkers' => 10,
                'expected' => 10,
            ],
            'count_zero_with_min_workers_one_clamped_to_one' => [
                'count' => 0,
                'minWorkers' => 1,
                'maxWorkers' => 10,
                'expected' => 1,
            ],
            'min_equals_max_both_five_count_below' => [
                'count' => 3,
                'minWorkers' => 5,
                'maxWorkers' => 5,
                'expected' => 5,
            ],
            'min_equals_max_both_five_count_above' => [
                'count' => 8,
                'minWorkers' => 5,
                'maxWorkers' => 5,
                'expected' => 5,
            ],
            'min_equals_max_both_five_count_exactly_equal' => [
                'count' => 5,
                'minWorkers' => 5,
                'maxWorkers' => 5,
                'expected' => 5,
            ],
            'negative_count_clamped_to_min' => [
                'count' => -1,
                'minWorkers' => 1,
                'maxWorkers' => 10,
                'expected' => 1,
            ],
            'large_count_clamped_to_max' => [
                'count' => 1000,
                'minWorkers' => 1,
                'maxWorkers' => 50,
                'expected' => 50,
            ],
            'count_one_within_default_bounds' => [
                'count' => 1,
                'minWorkers' => 1,
                'maxWorkers' => 10,
                'expected' => 1,
            ],
        ];
    }

    /**
     * Provides scenarios for evaluateScaling() tests.
     *
     * Each scenario defines:
     * - autoScalerConfig: null (no scaler) or config array for creating a real AutoScaler
     * - metricsData: data to return from MetricsRepositoryInterface::getRecent()
     * - expectedTarget: the expected targetProcesses value after evaluateScaling()
     * - description: human-readable description
     *
     * @return array<string, array{autoScalerConfig: ?array<string, mixed>, metricsData: array<int, array<string, mixed>>, expectedTarget: int, description: string}>
     */
    public static function evaluateScalingDataProvider(): array
    {
        return [
            'no_autoscaler_set' => [
                'autoScalerConfig' => null,
                'metricsData' => [],
                'expectedTarget' => 1,
                'description' => 'Without autoScaler, targetProcesses remains unchanged',
            ],
            'autoscaler_disabled' => [
                'autoScalerConfig' => [
                    'enabled' => false,
                    'queues' => ['default'],
                ],
                'metricsData' => [],
                'expectedTarget' => 1,
                'description' => 'Disabled autoScaler should not trigger scaling',
            ],
            'autoscaler_enabled_empty_metrics_no_change' => [
                'autoScalerConfig' => [
                    'enabled' => true,
                    'queues' => ['default'],
                    'queue_config' => [
                        'default' => [
                            'enabled' => true,
                            'min_workers' => 1,
                            'max_workers' => 10,
                            'strategy' => 'queue_size',
                            'jobs_per_worker' => 100,
                            'cooldown' => 0,
                        ],
                    ],
                ],
                'metricsData' => [],
                'expectedTarget' => 1,
                'description' => 'Empty metrics (queue_size=0) results in 0 target, clamped to min_workers=1, no change from default',
            ],
            'autoscaler_enabled_high_queue_size_scales_up' => [
                'autoScalerConfig' => [
                    'enabled' => true,
                    'queues' => ['default'],
                    'queue_config' => [
                        'default' => [
                            'enabled' => true,
                            'min_workers' => 1,
                            'max_workers' => 10,
                            'strategy' => 'queue_size',
                            'jobs_per_worker' => 100,
                            'cooldown' => 0,
                        ],
                    ],
                ],
                'metricsData' => [
                    ['queue_size' => 500, 'jobs_processed' => 0, 'average_wait_time' => 0],
                ],
                'expectedTarget' => 5,
                'description' => '500 jobs / 100 per worker = 5 target workers',
            ],
            'autoscaler_enabled_scales_up_clamped_by_max' => [
                'autoScalerConfig' => [
                    'enabled' => true,
                    'queues' => ['default'],
                    'queue_config' => [
                        'default' => [
                            'enabled' => true,
                            'min_workers' => 1,
                            'max_workers' => 8,
                            'strategy' => 'queue_size',
                            'jobs_per_worker' => 100,
                            'cooldown' => 0,
                        ],
                    ],
                ],
                'metricsData' => [
                    ['queue_size' => 2000, 'jobs_processed' => 0, 'average_wait_time' => 0],
                ],
                'expectedTarget' => 8,
                'description' => '2000 jobs / 100 per worker = 20 target, clamped to max_workers=8',
            ],
        ];
    }

    /**
     * @return array<string, array{currentPids: array<int>, targetProcesses: int, shouldQuit: bool, expectedForks: int, expectedSigterms: int, description: string}>
     */
    public static function maintainWorkerPoolDataProvider(): array
    {
        return [
            'fewer_pids_than_target_forks_new_workers' => [
                'currentPids' => [30001],
                'targetProcesses' => 3,
                'shouldQuit' => false,
                'expectedForks' => 2,
                'expectedSigterms' => 0,
                'description' => 'Should fork 2 new workers to reach target of 3',
            ],
            'more_pids_than_target_sigterms_excess' => [
                'currentPids' => [31001, 31002, 31003, 31004],
                'targetProcesses' => 2,
                'shouldQuit' => false,
                'expectedForks' => 0,
                'expectedSigterms' => 2,
                'description' => 'Should SIGTERM 2 youngest workers to reach target of 2',
            ],
            'pids_equal_target_no_action' => [
                'currentPids' => [32001, 32002, 32003],
                'targetProcesses' => 3,
                'shouldQuit' => false,
                'expectedForks' => 0,
                'expectedSigterms' => 0,
                'description' => 'No action when worker count matches target',
            ],
            'should_quit_true_no_action_regardless' => [
                'currentPids' => [33001],
                'targetProcesses' => 5,
                'shouldQuit' => true,
                'expectedForks' => 0,
                'expectedSigterms' => 0,
                'description' => 'shouldQuit=true should prevent any scaling action',
            ],
            'empty_pids_fork_to_target' => [
                'currentPids' => [],
                'targetProcesses' => 2,
                'shouldQuit' => false,
                'expectedForks' => 2,
                'expectedSigterms' => 0,
                'description' => 'Should fork 2 workers from empty pool',
            ],
            'scale_down_to_one' => [
                'currentPids' => [34001, 34002, 34003],
                'targetProcesses' => 1,
                'shouldQuit' => false,
                'expectedForks' => 0,
                'expectedSigterms' => 2,
                'description' => 'Should SIGTERM 2 workers to scale down to 1',
            ],
            'should_quit_with_excess_workers_still_no_action' => [
                'currentPids' => [35001, 35002, 35003, 35004, 35005],
                'targetProcesses' => 2,
                'shouldQuit' => true,
                'expectedForks' => 0,
                'expectedSigterms' => 0,
                'description' => 'shouldQuit=true should prevent scale-down as well',
            ],
        ];
    }

    /**
     * @return array<string, array{iteration: int, expectScalerInvoked: bool}>
     */
    public static function loopAutoScalingThrottleDataProvider(): array
    {
        return [
            'iteration_1_no_scaling' => [
                'iteration' => 0,
                'expectScalerInvoked' => false,
            ],
            'iteration_5_no_scaling' => [
                'iteration' => 4,
                'expectScalerInvoked' => false,
            ],
            'iteration_9_no_scaling' => [
                'iteration' => 8,
                'expectScalerInvoked' => false,
            ],
            'iteration_10_triggers_scaling' => [
                'iteration' => 9,
                'expectScalerInvoked' => true,
            ],
            'iteration_11_no_scaling' => [
                'iteration' => 10,
                'expectScalerInvoked' => false,
            ],
            'iteration_20_triggers_scaling' => [
                'iteration' => 19,
                'expectScalerInvoked' => true,
            ],
            'iteration_30_triggers_scaling' => [
                'iteration' => 29,
                'expectScalerInvoked' => true,
            ],
            'iteration_15_no_scaling' => [
                'iteration' => 14,
                'expectScalerInvoked' => false,
            ],
            'iteration_50_triggers_scaling' => [
                'iteration' => 49,
                'expectScalerInvoked' => true,
            ],
            'iteration_99_no_scaling' => [
                'iteration' => 98,
                'expectScalerInvoked' => false,
            ],
            'iteration_100_triggers_scaling' => [
                'iteration' => 99,
                'expectScalerInvoked' => true,
            ],
        ];
    }

    #[DataProvider('scaleWorkersDataProvider')]
    public function testScaleWorkersClampsBetweenMinAndMax(
        int $count,
        int $minWorkers,
        int $maxWorkers,
        int $expected,
    ): void {
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => $minWorkers,
                        'max_workers' => $maxWorkers,
                    ],
                ],
            ],
        ]);

        $supervisor->scaleWorkers($count);

        $targetProcesses = $this->getPrivateProperty($supervisor, 'targetProcesses');
        $this->assertSame(
            $expected,
            $targetProcesses,
            "scaleWorkers({$count}) with min={$minWorkers}, max={$maxWorkers} should set targetProcesses to {$expected}",
        );
    }

    public function testScaleWorkersUsesDefaultMinAndMaxWhenConfigMissing(): void
    {
        // No scaling config at all -- defaults: min=1, max=10
        $supervisor = $this->createSupervisor([]);

        $supervisor->scaleWorkers(5);
        $this->assertSame(5, $this->getPrivateProperty($supervisor, 'targetProcesses'));

        $supervisor->scaleWorkers(0);
        $this->assertSame(1, $this->getPrivateProperty($supervisor, 'targetProcesses'));

        $supervisor->scaleWorkers(15);
        $this->assertSame(10, $this->getPrivateProperty($supervisor, 'targetProcesses'));
    }

    public function testScaleWorkersCanBeCalledMultipleTimes(): void
    {
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        $supervisor->scaleWorkers(3);
        $this->assertSame(3, $this->getPrivateProperty($supervisor, 'targetProcesses'));

        $supervisor->scaleWorkers(7);
        $this->assertSame(7, $this->getPrivateProperty($supervisor, 'targetProcesses'));

        $supervisor->scaleWorkers(1);
        $this->assertSame(1, $this->getPrivateProperty($supervisor, 'targetProcesses'));
    }

    #[DataProvider('evaluateScalingDataProvider')]
    public function testEvaluateScalingWithVariousAutoScalerStates(
        ?array $autoScalerConfig,
        array $metricsData,
        int $expectedTarget,
        string $description,
    ): void {
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        if ($autoScalerConfig !== null) {
            $this->metricsRepository->shouldReceive('getRecent')
                ->andReturn($metricsData);

            $autoScaler = new AutoScaler(
                $this->metricsRepository,
                $this->scalerEvents,
                $autoScalerConfig,
            );

            $supervisor->setAutoScaler($autoScaler);
        }

        $this->invokePrivateMethod($supervisor, 'evaluateScaling');

        $this->assertSame(
            $expectedTarget,
            $this->getPrivateProperty($supervisor, 'targetProcesses'),
            $description,
        );
    }

    public function testEvaluateScalingWithDisabledAutoScalerDoesNotChangeTarget(): void
    {
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        // Set an initial target
        $this->setPrivateProperty($supervisor, 'targetProcesses', 4);

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            ['enabled' => false],
        );
        $supervisor->setAutoScaler($autoScaler);

        $this->invokePrivateMethod($supervisor, 'evaluateScaling');

        // Target should remain at 4 since AutoScaler is disabled
        $this->assertSame(4, $this->getPrivateProperty($supervisor, 'targetProcesses'));
    }

    public function testEvaluateScalingWithMultipleQueuesAppliesAllResults(): void
    {
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        // Create a dedicated metrics repository for this test to avoid byDefault interference
        $metricsRepo = Mockery::mock(MetricsRepositoryInterface::class);

        // Configure metrics to return different queue sizes for different queues
        // AutoScaler::getQueueMetrics() calls $this->metrics->getRecent($queue, 5)
        $metricsRepo->shouldReceive('getRecent')
            ->with('emails', 5)
            ->andReturn([
                ['queue_size' => 600, 'jobs_processed' => 0, 'average_wait_time' => 0],
            ]);

        $metricsRepo->shouldReceive('getRecent')
            ->with('notifications', 5)
            ->andReturn([
                ['queue_size' => 300, 'jobs_processed' => 0, 'average_wait_time' => 0],
            ]);

        $autoScaler = new AutoScaler(
            $metricsRepo,
            $this->scalerEvents,
            [
                'enabled' => true,
                'queues' => ['emails', 'notifications'],
                'queue_config' => [
                    'emails' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                    'notifications' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );

        $supervisor->setAutoScaler($autoScaler);

        $this->invokePrivateMethod($supervisor, 'evaluateScaling');

        // emails: 600/100=6, notifications: 300/100=3
        // Both scale results have non-empty action, last result (notifications=3) wins
        $targetProcesses = $this->getPrivateProperty($supervisor, 'targetProcesses');
        $this->assertSame(
            3,
            $targetProcesses,
            'With multiple queue results, the last non-empty action should set the targetProcesses',
        );
    }

    public function testEvaluateScalingNoActionWhenTargetMatchesCurrent(): void
    {
        // When AutoScaler.scale() returns null for a queue (no change needed),
        // no action is taken and targetProcesses stays the same.
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        $this->setPrivateProperty($supervisor, 'targetProcesses', 3);

        // Empty metrics means queue_size=0, target=0 clamped to min=1,
        // but currentWorkers for the queue defaults to min=1, so target(1)==current(1) -> null result
        $this->metricsRepository->shouldReceive('getRecent')
            ->andReturn([]);

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            [
                'enabled' => true,
                'queues' => ['default'],
                'queue_config' => [
                    'default' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );

        $supervisor->setAutoScaler($autoScaler);

        $this->invokePrivateMethod($supervisor, 'evaluateScaling');

        // targetProcesses should remain 3 since scale() returned empty (no action)
        $this->assertSame(3, $this->getPrivateProperty($supervisor, 'targetProcesses'));
    }

    #[DataProvider('maintainWorkerPoolDataProvider')]
    public function testMaintainWorkerPoolScalesCorrectly(
        array $currentPids,
        int $targetProcesses,
        bool $shouldQuit,
        int $expectedForks,
        int $expectedSigterms,
        string $description,
    ): void {
        PcntlTestState::$enabled = true;
        PcntlTestState::$nextPid = 40001;

        $supervisor = $this->createSupervisor([]);
        $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
        $this->setPrivateProperty($supervisor, 'currentOptions', []);
        $this->setPrivateProperty($supervisor, 'targetProcesses', $targetProcesses);

        if ($shouldQuit) {
            $supervisor->terminate();
        }

        $this->injectWorkerPids($supervisor, $currentPids);

        // Mark all existing PIDs as alive for SIGTERM checks
        foreach ($currentPids as $pid) {
            PcntlTestState::$processAlive[$pid] = true;
        }

        $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

        $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

        // Verify fork count: new PIDs in the pool minus original PIDs
        $finalPids = $this->getPrivateProperty($supervisor, 'workerPids');

        if (!$shouldQuit) {
            $this->assertSame(
                $targetProcesses,
                \count($finalPids),
                $description . ' - final worker count should match target',
            );
        }

        // Verify SIGTERM signals sent
        $sigtermCalls = array_filter(
            PcntlTestState::$killLog,
            static fn($call) => $call['signal'] === SIGTERM,
        );
        $this->assertCount(
            $expectedSigterms,
            $sigtermCalls,
            $description . ' - SIGTERM count mismatch',
        );
    }

    public function testMaintainWorkerPoolSigtermsYoungestPidsFirst(): void
    {
        PcntlTestState::$enabled = true;

        $supervisor = $this->createSupervisor([]);
        $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
        $this->setPrivateProperty($supervisor, 'currentOptions', []);
        $this->setPrivateProperty($supervisor, 'targetProcesses', 2);

        // PIDs listed in order: 36001 oldest, 36004 youngest
        $this->injectWorkerPids($supervisor, [36001, 36002, 36003, 36004]);

        foreach ([36001, 36002, 36003, 36004] as $pid) {
            PcntlTestState::$processAlive[$pid] = true;
        }

        $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

        $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

        // The youngest PIDs should have been SIGTERM'd (array_pop removes from end)
        $sigtermCalls = array_filter(
            PcntlTestState::$killLog,
            static fn($call) => $call['signal'] === SIGTERM,
        );
        $sigtermedPids = array_column($sigtermCalls, 'pid');

        // 36004 and 36003 are the youngest (last in array), should be terminated
        $this->assertContains(36004, $sigtermedPids, 'Youngest PID 36004 should be SIGTERM\'d');
        $this->assertContains(36003, $sigtermedPids, 'Second youngest PID 36003 should be SIGTERM\'d');
        $this->assertNotContains(36001, $sigtermedPids, 'Oldest PID 36001 should remain');
        $this->assertNotContains(36002, $sigtermedPids, 'Second oldest PID 36002 should remain');

        // Remaining PIDs should be the oldest
        $remainingPids = $this->getPrivateProperty($supervisor, 'workerPids');
        $this->assertSame([36001, 36002], $remainingPids);
    }

    #[DataProvider('loopAutoScalingThrottleDataProvider')]
    public function testLoopAutoScalingThrottledByIterationCount(
        int $iteration,
        bool $expectScalerInvoked,
    ): void {
        PcntlTestState::$enabled = true;

        $supervisor = $this->createSupervisor([]);
        $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
        $this->setPrivateProperty($supervisor, 'currentOptions', []);

        // Set loopIteration so the next loop() call will increment to iteration+1
        $this->setPrivateProperty($supervisor, 'loopIteration', $iteration);

        // Use a real AutoScaler that is enabled, and track whether scale() is invoked
        // by checking if metricsRepository->getRecent() is called (which scale->scaleQueue invokes).
        // When expectScalerInvoked is true, we expect getRecent to be called.
        // When false, getRecent should NOT be called (evaluateScaling is never reached).

        $metricsRepo = Mockery::mock(MetricsRepositoryInterface::class);

        if ($expectScalerInvoked) {
            // scale() is called -> scaleQueue() -> getQueueMetrics() -> getRecent()
            $metricsRepo->shouldReceive('getRecent')
                ->atLeast()->once()
                ->andReturn([]);
        } else {
            $metricsRepo->shouldNotReceive('getRecent');
        }

        $scalerEventsLocal = Mockery::mock(Dispatcher::class);
        $scalerEventsLocal->shouldReceive('dispatch')->zeroOrMoreTimes();

        $autoScaler = new AutoScaler(
            $metricsRepo,
            $scalerEventsLocal,
            [
                'enabled' => true,
                'queues' => ['default'],
                'queue_config' => [
                    'default' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );

        $supervisor->setAutoScaler($autoScaler);

        $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

        $this->invokePrivateMethod($supervisor, 'loop');

        // Verify that loop incremented the iteration counter
        $actualIteration = $this->getPrivateProperty($supervisor, 'loopIteration');
        $this->assertSame(
            $iteration + 1,
            $actualIteration,
            'loopIteration should be incremented by 1 after loop()',
        );
    }

    public function testSetAutoScalerInjectsScalerIntoSupervisor(): void
    {
        $supervisor = $this->createSupervisor([]);

        $this->assertNull($this->getPrivateProperty($supervisor, 'autoScaler'));

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            ['enabled' => false],
        );
        $supervisor->setAutoScaler($autoScaler);

        $this->assertSame(
            $autoScaler,
            $this->getPrivateProperty($supervisor, 'autoScaler'),
        );
    }

    public function testSetAutoScalerReplacesExistingScaler(): void
    {
        $supervisor = $this->createSupervisor([]);

        $firstScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            ['enabled' => false],
        );
        $secondScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            ['enabled' => true],
        );

        $supervisor->setAutoScaler($firstScaler);
        $this->assertSame($firstScaler, $this->getPrivateProperty($supervisor, 'autoScaler'));

        $supervisor->setAutoScaler($secondScaler);
        $this->assertSame($secondScaler, $this->getPrivateProperty($supervisor, 'autoScaler'));
    }

    public function testEvaluateScalingThenMaintainPoolIntegration(): void
    {
        PcntlTestState::$enabled = true;
        PcntlTestState::$nextPid = 50001;

        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
        $this->setPrivateProperty($supervisor, 'currentOptions', []);

        // Start with 2 workers
        $this->injectWorkerPids($supervisor, [50001, 50002]);
        PcntlTestState::$nextPid = 50003;

        // Configure AutoScaler to recommend 5 workers via high queue size
        $this->metricsRepository->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 500, 'jobs_processed' => 0, 'average_wait_time' => 0],
            ]);

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            [
                'enabled' => true,
                'queues' => ['default'],
                'queue_config' => [
                    'default' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );

        $supervisor->setAutoScaler($autoScaler);

        $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

        // evaluateScaling changes targetProcesses
        $this->invokePrivateMethod($supervisor, 'evaluateScaling');
        $this->assertSame(5, $this->getPrivateProperty($supervisor, 'targetProcesses'));

        // maintainWorkerPool forks new workers to reach target
        $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');
        $this->assertSame(5, $supervisor->getWorkerCount());
    }

    public function testEvaluateScalingScaleDownThenMaintainPoolIntegration(): void
    {
        PcntlTestState::$enabled = true;

        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
        $this->setPrivateProperty($supervisor, 'currentOptions', []);

        // Start with 5 workers
        $this->injectWorkerPids($supervisor, [51001, 51002, 51003, 51004, 51005]);
        foreach ([51001, 51002, 51003, 51004, 51005] as $pid) {
            PcntlTestState::$processAlive[$pid] = true;
        }

        // Configure AutoScaler: empty queue means target workers = 0, clamped to min=2
        // The AutoScaler's currentWorkers for 'default' defaults to min=2
        // queue_size=0 means 0 workers needed, clamped to min=2
        // But currentWorkers=2 (the default), so target==current -> null, no action
        //
        // Instead, let's set a small queue size that produces 2 workers:
        // queue_size=200, jobs_per_worker=100 -> target=2
        // AutoScaler's currentWorkers for default = min_workers=2 (default)
        // So target(2) == current(2) -> null... We need current != target.
        //
        // Use setCurrentWorkers on the AutoScaler to set it to 5 first.
        $this->metricsRepository->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 200, 'jobs_processed' => 0, 'average_wait_time' => 0],
            ]);

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            [
                'enabled' => true,
                'queues' => ['default'],
                'queue_config' => [
                    'default' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );

        // Set the AutoScaler's internal current worker count for 'default' to 5
        // so that when it calculates target=2, it sees a scale_down from 5 to 2
        $autoScaler->setCurrentWorkers('default', 5);

        $supervisor->setAutoScaler($autoScaler);

        $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

        // evaluateScaling changes targetProcesses to 2
        $this->invokePrivateMethod($supervisor, 'evaluateScaling');
        $this->assertSame(2, $this->getPrivateProperty($supervisor, 'targetProcesses'));

        // maintainWorkerPool sends SIGTERM to excess workers
        $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

        // Verify SIGTERM was sent to 3 workers (youngest first)
        $sigtermCalls = array_filter(
            PcntlTestState::$killLog,
            static fn($call) => $call['signal'] === SIGTERM,
        );
        $this->assertCount(3, $sigtermCalls);

        // After scale-down the pool has 2 workers
        $this->assertSame(2, $supervisor->getWorkerCount());
    }

    public function testEvaluateScalingWithResultExceedingMaxIsClampedByScaleWorkers(): void
    {
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 8,
                    ],
                ],
            ],
        ]);

        // Configure AutoScaler to produce a target of 20 (which exceeds max_workers=8)
        // queue_size=2000 / jobs_per_worker=100 = 20 target
        // AutoScaler clamps to its own max_workers=20, then scaleWorkers clamps to supervisor's max=8
        $this->metricsRepository->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 2000, 'jobs_processed' => 0, 'average_wait_time' => 0],
            ]);

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            [
                'enabled' => true,
                'queues' => ['default'],
                'queue_config' => [
                    'default' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 20,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );
        $supervisor->setAutoScaler($autoScaler);

        $this->invokePrivateMethod($supervisor, 'evaluateScaling');

        // scaleWorkers(20) should be clamped to the supervisor's max_workers=8
        $this->assertSame(8, $this->getPrivateProperty($supervisor, 'targetProcesses'));
    }

    public function testEvaluateScalingWithResultBelowMinIsClampedByScaleWorkers(): void
    {
        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 3,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        // Configure a scenario that produces target < 3:
        // queue_size=100 / jobs_per_worker=100 = 1 target
        // AutoScaler clamps to its own min=1, supervisor's scaleWorkers clamps to 3
        $this->metricsRepository->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 100, 'jobs_processed' => 0, 'average_wait_time' => 0],
            ]);

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            [
                'enabled' => true,
                'queues' => ['default'],
                'queue_config' => [
                    'default' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );
        // Set current to something > 1 so that scale_down is produced
        $autoScaler->setCurrentWorkers('default', 5);

        $supervisor->setAutoScaler($autoScaler);

        $this->invokePrivateMethod($supervisor, 'evaluateScaling');

        // AutoScaler says target=1, scaleWorkers(1) is clamped to min_workers=3
        $this->assertSame(3, $this->getPrivateProperty($supervisor, 'targetProcesses'));
    }

    public function testLoopAtIteration10WithAutoScalerScalesUp(): void
    {
        PcntlTestState::$enabled = true;
        PcntlTestState::$nextPid = 60001;

        $supervisor = $this->createSupervisor([
            'scaling' => [
                'policies' => [
                    'default' => [
                        'min_workers' => 1,
                        'max_workers' => 10,
                    ],
                ],
            ],
        ]);

        $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
        $this->setPrivateProperty($supervisor, 'currentOptions', []);
        $this->setPrivateProperty($supervisor, 'targetProcesses', 2);
        $this->injectWorkerPids($supervisor, [60001, 60002]);
        PcntlTestState::$nextPid = 60003;

        // Set iteration to 9 so next loop() call makes it 10 (multiple of 10)
        $this->setPrivateProperty($supervisor, 'loopIteration', 9);

        // Configure AutoScaler to produce 4 workers
        // queue_size=400, jobs_per_worker=100 -> target=4
        $this->metricsRepository->shouldReceive('getRecent')
            ->andReturn([
                ['queue_size' => 400, 'jobs_processed' => 0, 'average_wait_time' => 0],
            ]);

        $autoScaler = new AutoScaler(
            $this->metricsRepository,
            $this->scalerEvents,
            [
                'enabled' => true,
                'queues' => ['default'],
                'queue_config' => [
                    'default' => [
                        'enabled' => true,
                        'min_workers' => 1,
                        'max_workers' => 10,
                        'strategy' => 'queue_size',
                        'jobs_per_worker' => 100,
                        'cooldown' => 0,
                    ],
                ],
            ],
        );
        // AutoScaler needs to see current=2 so target=4 triggers scale_up
        $autoScaler->setCurrentWorkers('default', 2);

        $supervisor->setAutoScaler($autoScaler);

        $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

        $this->invokePrivateMethod($supervisor, 'loop');

        // After loop: evaluateScaling set target to 4, maintainWorkerPool forked 2 more
        $this->assertSame(4, $this->getPrivateProperty($supervisor, 'targetProcesses'));
        $this->assertSame(4, $supervisor->getWorkerCount());
    }

    public function testLoopWithoutAutoScalerDoesNotCallEvaluateScaling(): void
    {
        PcntlTestState::$enabled = true;

        $supervisor = $this->createSupervisor([]);
        $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
        $this->setPrivateProperty($supervisor, 'currentOptions', []);
        $this->setPrivateProperty($supervisor, 'loopIteration', 9);

        // No autoScaler set - the condition in loop() checks autoScaler !== null
        $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

        // Should not throw, evaluateScaling short-circuits when autoScaler is null
        $this->invokePrivateMethod($supervisor, 'loop');

        $this->assertSame(10, $this->getPrivateProperty($supervisor, 'loopIteration'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createSupervisor(array $config = []): WorkerSupervisor
    {
        return new WorkerSupervisor(
            $this->events,
            $config,
        );
    }

    /**
     * Inject worker PIDs directly into the supervisor via reflection.
     *
     * @param array<int, int> $pids
     */
    private function injectWorkerPids(WorkerSupervisor $supervisor, array $pids): void
    {
        $this->setPrivateProperty($supervisor, 'workerPids', $pids);
    }

    /**
     * Invoke a private method on an object.
     *
     * @param array<int, mixed> $args
     */
    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);

        return $method->invokeArgs($object, $args);
    }

    /**
     * Get a private property value from an object.
     */
    private function getPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);

        return $prop->getValue($object);
    }

    /**
     * Set a private property value on an object.
     */
    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);

        $prop->setValue($object, $value);
    }
}
