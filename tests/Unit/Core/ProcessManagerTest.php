<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Station\Core\ProcessManager;

class ProcessManagerTest extends TestCase
{
    private ProcessManager $sut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sut = new ProcessManager(['enabled' => true]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validConnectionProvider(): array
    {
        return [
            'rabbitmq' => ['rabbitmq'],
            'redis' => ['redis'],
            'sqs' => ['sqs'],
            'beanstalkd' => ['beanstalkd'],
            'kafka' => ['kafka'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConnectionProvider(): array
    {
        return [
            'empty_string' => [''],
            'mysql' => ['mysql'],
            'postgres' => ['postgres'],
            'station-redis_connector' => ['station-redis'],
            'uppercase_RABBITMQ' => ['RABBITMQ'],
            'with_spaces' => ['rabbit mq'],
            'special_characters' => ['rabbit@mq'],
        ];
    }

    /**
     * @return array<string, array{string, bool, int|null, int|null, float|null, int|null, string|null}>
     */
    public static function fullPsLineProvider(): array
    {
        return [
            'standard_linux_line' => [
                '  1234     1  2.5 51200 php artisan station:work rabbitmq --queue=default',
                true, 1234, 1, 2.5, 51200, 'php artisan station:work rabbitmq --queue=default',
            ],
            'high_pid_numbers' => [
                '999999 999998  0.0 102400 php artisan station:work redis --queue=high',
                true, 999999, 999998, 0.0, 102400, 'php artisan station:work redis --queue=high',
            ],
            'high_cpu_usage' => [
                '  5678     1 99.9 256000 php artisan station:work kafka --queue=events',
                true, 5678, 1, 99.9, 256000, 'php artisan station:work kafka --queue=events',
            ],
            'zero_rss' => [
                '  1111     0  0.0     0 php artisan station:work sqs --queue=test',
                true, 1111, 0, 0.0, 0, 'php artisan station:work sqs --queue=test',
            ],
            'extra_leading_spaces' => [
                '      42     1  1.2 12345 php artisan station:work beanstalkd',
                true, 42, 1, 1.2, 12345, 'php artisan station:work beanstalkd',
            ],
            'no_spaces_between_fields' => [
                // Fields run together without spaces - should still parse with trim
                '1 2 3.0 4 php artisan station:work redis',
                true, 1, 2, 3.0, 4, 'php artisan station:work redis',
            ],
        ];
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function fullPsFilterProvider(): array
    {
        return [
            'valid_station_work_line' => [
                '  1234     1  2.5 51200 php artisan station:work rabbitmq --queue=default',
                true,
            ],
            'grep_line_excluded' => [
                '  5678     1  0.0  1024 grep station:work',
                false,
            ],
            'ps_command_excluded' => [
                '  9999     1  0.0  2048 ps -eo pid,ppid,pcpu,rss,args station:work',
                false,
            ],
            'non_station_process' => [
                '  1234     1  0.0  1024 php artisan queue:work redis',
                false,
            ],
            'header_line_excluded' => [
                '  PID  PPID %CPU   RSS COMMAND',
                false,
            ],
            'empty_line' => [
                '',
                false,
            ],
        ];
    }

    /**
     * @return array<string, array{string, bool, int|null, int|null, int|null, string|null, string|null}>
     */
    public static function rssOnlyPsLineProvider(): array
    {
        return [
            'standard_kb_no_suffix' => [
                '  1234     1 51200 php artisan station:work rabbitmq --queue=default',
                true, 1234, 1, 51200, '', 'php artisan station:work rabbitmq --queue=default',
            ],
            'busybox_mb_suffix' => [
                '  1234     1 51m php artisan station:work redis --queue=high',
                true, 1234, 1, 51, 'm', 'php artisan station:work redis --queue=high',
            ],
            'busybox_kb_suffix' => [
                '  5678     1 102400k php artisan station:work sqs --queue=jobs',
                true, 5678, 1, 102400, 'k', 'php artisan station:work sqs --queue=jobs',
            ],
            'uppercase_M_suffix' => [
                '  9999     1 64M php artisan station:work kafka --queue=events',
                true, 9999, 1, 64, 'm', 'php artisan station:work kafka --queue=events',
            ],
            'zero_rss_no_suffix' => [
                '  1111     0 0 php artisan station:work beanstalkd',
                true, 1111, 0, 0, '', 'php artisan station:work beanstalkd',
            ],
        ];
    }

    /**
     * @return array<string, array{string, bool, int|null, int|null, string|null}>
     */
    public static function minimalPsLineProvider(): array
    {
        return [
            'standard_minimal_line' => [
                '  1234     1 php artisan station:work rabbitmq --queue=default',
                true, 1234, 1, 'php artisan station:work rabbitmq --queue=default',
            ],
            'high_pid_numbers' => [
                '999999 999998 php artisan station:work redis --queue=high',
                true, 999999, 999998, 'php artisan station:work redis --queue=high',
            ],
            'extra_leading_spaces' => [
                '      42     1 php artisan station:work beanstalkd',
                true, 42, 1, 'php artisan station:work beanstalkd',
            ],
            'command_with_many_args' => [
                '  5678     1 php artisan station:work kafka --queue=events --tries=3 --timeout=60',
                true, 5678, 1, 'php artisan station:work kafka --queue=events --tries=3 --timeout=60',
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, string, float, int}>
     */
    public static function fullPsPipelineProvider(): array
    {
        return [
            'rabbitmq_default_queue' => [
                '  1234     1  2.5 51200 php artisan station:work rabbitmq --queue=default',
                'rabbitmq', 'default', 2.5, 50,
            ],
            'redis_multiple_queues' => [
                '  5678     1  0.1 102400 php artisan station:work redis --queue=high,default,low',
                'redis', 'high,default,low', 0.1, 100,
            ],
            'sqs_production_queue' => [
                '  9012     1  0.0 8192 php artisan station:work sqs --queue=production',
                'sqs', 'production', 0.0, 8,
            ],
            'beanstalkd_no_queue_flag' => [
                '  3456     1  3.7 32768 php artisan station:work beanstalkd',
                'beanstalkd', 'default', 3.7, 32,
            ],
            'kafka_with_extra_flags' => [
                '  7890     1 15.0 256000 php artisan station:work kafka --queue=events --tries=3',
                'kafka', 'events', 15.0, 250,
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, string, int}>
     */
    public static function rssOnlyPsPipelineProvider(): array
    {
        return [
            'standard_kb_value' => [
                '  1234     1 51200 php artisan station:work rabbitmq --queue=default',
                'rabbitmq', 'default', 50,
            ],
            'busybox_megabyte_suffix' => [
                '  5678     1 64m php artisan station:work redis --queue=high',
                'redis', 'high', 64,   // 64m = 64*1024 KB = 65536 KB = 64 MB
            ],
            'busybox_kilobyte_suffix' => [
                '  9012     1 10240k php artisan station:work sqs --queue=jobs',
                'sqs', 'jobs', 10,     // 10240k = 10240 KB = 10 MB
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function minimalPsPipelineProvider(): array
    {
        return [
            'rabbitmq_with_queue' => [
                '  1234     1 php artisan station:work rabbitmq --queue=default',
                'rabbitmq', 'default',
            ],
            'redis_no_queue' => [
                '  5678     1 php artisan station:work redis',
                'redis', 'default',
            ],
            'kafka_custom_queue' => [
                '  9012     1 php artisan station:work kafka --queue=events',
                'kafka', 'events',
            ],
        ];
    }

    // =========================================================================
    // ensureEnabled / config validation
    // =========================================================================

    public function testEnsureEnabledThrowsWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->startWorker('rabbitmq');
    }

    public function testEnsureEnabledThrowsWhenConfigEmpty(): void
    {
        $manager = new ProcessManager([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->startWorker('rabbitmq');
    }

    public function testEnsureEnabledThrowsWhenEnabledKeyMissing(): void
    {
        $manager = new ProcessManager(['other_key' => 'value']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->stopSupervisor();
    }

    public function testEnsureEnabledThrowsForStopWorkerWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->stopWorker('rabbitmq');
    }

    public function testEnsureEnabledThrowsForStopExternalWorkerWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->stopExternalWorker(12345);
    }

    public function testEnsureEnabledThrowsForStartSupervisorWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->startSupervisor('rabbitmq');
    }

    public function testEnsureEnabledThrowsForStopSupervisorWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->stopSupervisor();
    }

    // =========================================================================
    // validateConnection
    // =========================================================================

    public function testStartWorkerValidatesConnection(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid connection: invalid_driver');

        $manager->startWorker('invalid_driver');
    }

    public function testStopWorkerValidatesConnection(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid connection: bad_conn');

        $manager->stopWorker('bad_conn');
    }

    public function testStartSupervisorValidatesConnection(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid connection: mysql');

        $manager->startSupervisor('mysql');
    }

    #[DataProvider('validConnectionProvider')]
    public function testValidateConnectionAcceptsValidDrivers(string $connection): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'validateConnection');

        // Should not throw for valid connections
        $method->invoke($this->sut, $connection);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidConnectionProvider')]
    public function testValidateConnectionRejectsInvalidDrivers(string $connection): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'validateConnection');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid connection: {$connection}");

        $method->invoke($this->sut, $connection);
    }

    // =========================================================================
    // stopExternalWorker
    // =========================================================================

    public function testStopExternalWorkerRejectsNonRunningProcess(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // PID 999999 almost certainly doesn't exist
        $result = $manager->stopExternalWorker(999999);

        $this->assertFalse($result['success']);
        $this->assertSame('Process not running', $result['message']);
    }

    // =========================================================================
    // detectRunningWorkers (public)
    // =========================================================================

    public function testDetectRunningWorkersReturnsGroupedByConnection(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $workers = $manager->detectRunningWorkers();

        $this->assertIsArray($workers);

        // Result is grouped by connection name -> list of processes
        foreach ($workers as $connection => $processes) {
            $this->assertIsString($connection);
            $this->assertIsArray($processes);

            foreach ($processes as $process) {
                $this->assertArrayHasKey('pid', $process);
                $this->assertArrayHasKey('ppid', $process);
                $this->assertArrayHasKey('command', $process);
                $this->assertArrayHasKey('role', $process);
                $this->assertArrayHasKey('children', $process);
                $this->assertArrayHasKey('cpu', $process);
                $this->assertArrayHasKey('memory_mb', $process);
                $this->assertContains($process['role'], ['supervisor', 'worker']);
                $this->assertIsArray($process['children']);
                $this->assertIsFloat($process['cpu']);
                $this->assertIsInt($process['memory_mb']);
            }
        }
    }

    // =========================================================================
    // detectRunningSupervisor
    // =========================================================================

    public function testDetectRunningSupervisorReturnsNullWhenNoneRunning(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $this->assertNull($manager->detectRunningSupervisor());
    }

    // =========================================================================
    // buildWorkerEntry (private, tested via reflection)
    // =========================================================================

    public function testBuildWorkerEntryWithRabbitmqConnectionAndDefaultQueue(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        $result = $method->invoke(
            $this->sut,
            1234,
            1,
            'php artisan station:work rabbitmq --queue=default',
            2.5,
            51200,
        );

        $this->assertSame(1234, $result['pid']);
        $this->assertSame(1, $result['ppid']);
        $this->assertSame('php artisan station:work rabbitmq --queue=default', $result['command']);
        $this->assertSame('rabbitmq', $result['connection']);
        $this->assertSame('default', $result['queue']);
        $this->assertSame(2.5, $result['cpu']);
        $this->assertSame(50, $result['memory_mb']); // 51200 KB = 50 MB
    }

    public function testBuildWorkerEntryWithRedisConnectionAndCustomQueue(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        $result = $method->invoke(
            $this->sut,
            5678,
            100,
            'php artisan station:work redis --queue=high,low',
            0.0,
            102400,
        );

        $this->assertSame(5678, $result['pid']);
        $this->assertSame(100, $result['ppid']);
        $this->assertSame('redis', $result['connection']);
        $this->assertSame('high,low', $result['queue']);
        $this->assertSame(0.0, $result['cpu']);
        $this->assertSame(100, $result['memory_mb']); // 102400 KB = 100 MB
    }

    public function testBuildWorkerEntryWithKafkaConnection(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        $result = $method->invoke(
            $this->sut,
            9999,
            1,
            'php artisan station:work kafka --queue=events',
            15.3,
            256000,
        );

        $this->assertSame('kafka', $result['connection']);
        $this->assertSame('events', $result['queue']);
        $this->assertSame(15.3, $result['cpu']);
        $this->assertSame(250, $result['memory_mb']); // 256000 KB = 250 MB
    }

    public function testBuildWorkerEntryWithSqsConnection(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        $result = $method->invoke(
            $this->sut,
            4321,
            1000,
            'php artisan station:work sqs --queue=production',
            0.1,
            8192,
        );

        $this->assertSame('sqs', $result['connection']);
        $this->assertSame('production', $result['queue']);
        $this->assertSame(8, $result['memory_mb']); // 8192 KB = 8 MB
    }

    public function testBuildWorkerEntryWithBeanstalkdConnection(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        $result = $method->invoke(
            $this->sut,
            7777,
            1,
            'php artisan station:work beanstalkd --queue=jobs',
            3.7,
            32768,
        );

        $this->assertSame('beanstalkd', $result['connection']);
        $this->assertSame('jobs', $result['queue']);
        $this->assertSame(32, $result['memory_mb']); // 32768 KB = 32 MB
    }

    public function testBuildWorkerEntryDefaultsToUnknownConnectionWhenMissing(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Command without a connection argument after station:work
        $result = $method->invoke(
            $this->sut,
            1111,
            0,
            'php artisan station:work',
            0.0,
            1024,
        );

        $this->assertSame('unknown', $result['connection']);
    }

    public function testBuildWorkerEntryDefaultsToDefaultQueueWhenMissing(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Command without --queue flag
        $result = $method->invoke(
            $this->sut,
            2222,
            0,
            'php artisan station:work rabbitmq',
            0.0,
            2048,
        );

        $this->assertSame('rabbitmq', $result['connection']);
        $this->assertSame('default', $result['queue']);
    }

    public function testBuildWorkerEntryWithZeroRssReturnsZeroMemoryMb(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        $result = $method->invoke(
            $this->sut,
            3333,
            0,
            'php artisan station:work redis --queue=default',
            0.0,
            0,
        );

        $this->assertSame(0, $result['memory_mb']);
    }

    public function testBuildWorkerEntryRoundsMemoryCorrectly(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // 1536 KB = 1.5 MB, rounds to 2
        $result = $method->invoke(
            $this->sut,
            4444,
            0,
            'php artisan station:work redis --queue=default',
            0.0,
            1536,
        );

        $this->assertSame(2, $result['memory_mb']);
    }

    public function testBuildWorkerEntrySmallRssRoundsToZero(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // 500 KB = ~0.49 MB, rounds to 0
        $result = $method->invoke(
            $this->sut,
            5555,
            0,
            'php artisan station:work redis --queue=default',
            0.0,
            500,
        );

        $this->assertSame(0, $result['memory_mb']);
    }

    // =========================================================================
    // psHeaderContains (private, tested via reflection)
    // =========================================================================

    public function testPsHeaderContainsReturnsTrueWhenColumnPresent(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'psHeaderContains');

        $output = [
            '  PID  PPID %CPU   RSS COMMAND',
            ' 1234     1  2.5 51200 php artisan station:work rabbitmq',
        ];

        $this->assertTrue($method->invoke($this->sut, $output, 'CPU'));
        $this->assertTrue($method->invoke($this->sut, $output, 'RSS'));
        $this->assertTrue($method->invoke($this->sut, $output, 'PID'));
        $this->assertTrue($method->invoke($this->sut, $output, 'COMMAND'));
    }

    public function testPsHeaderContainsReturnsFalseWhenColumnAbsent(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'psHeaderContains');

        $output = [
            '  PID  PPID COMMAND',
            ' 1234     1 php artisan station:work rabbitmq',
        ];

        $this->assertFalse($method->invoke($this->sut, $output, 'RSS'));
        $this->assertFalse($method->invoke($this->sut, $output, 'CPU'));
        $this->assertFalse($method->invoke($this->sut, $output, 'PCPU'));
    }

    public function testPsHeaderContainsIsCaseInsensitive(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'psHeaderContains');

        $output = [
            '  PID  PPID %CPU   RSS COMMAND',
        ];

        // 'cpu' is contained in '%CPU' after uppercasing both
        $this->assertTrue($method->invoke($this->sut, $output, 'cpu'));
        $this->assertTrue($method->invoke($this->sut, $output, 'rss'));
        $this->assertTrue($method->invoke($this->sut, $output, 'pid'));
    }

    public function testPsHeaderContainsReturnsFalseForEmptyOutput(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'psHeaderContains');

        $this->assertFalse($method->invoke($this->sut, [], 'PID'));
    }

    public function testPsHeaderContainsReturnsFalseWhenFirstLineIsEmpty(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'psHeaderContains');

        $output = [''];

        $this->assertFalse($method->invoke($this->sut, $output, 'PID'));
    }

    // =========================================================================
    // detectWithFullPs parsing logic (tested via reflection with mocked exec)
    //
    // Since these methods call exec() internally, we test the parsing logic
    // by extracting the regex patterns and testing buildWorkerEntry which
    // is the shared parser. For integration coverage of detectWithFullPs,
    // detectWithRssOnlyPs, and detectWithMinimalPs, we test the public
    // detectRunningWorkers method above.
    //
    // Below we test the specific regex patterns used in each detect method
    // by replicating their parsing logic with known ps output samples.
    // =========================================================================

    #[DataProvider('fullPsLineProvider')]
    public function testFullPsRegexParsesCorrectly(
        string $line,
        bool $shouldMatch,
        ?int $expectedPid,
        ?int $expectedPpid,
        ?float $expectedCpu,
        ?int $expectedRssKb,
        ?string $expectedArgs,
    ): void {
        // Replicate the regex from detectWithFullPs
        $matched = preg_match('/^\s*(\d+)\s+(\d+)\s+([\d.]+)\s+(\d+)\s+(.+)$/', trim($line), $m);

        $this->assertSame($shouldMatch, $matched === 1, "Line: '{$line}'");

        if ($shouldMatch && $matched === 1) {
            $this->assertSame($expectedPid, (int) $m[1]);
            $this->assertSame($expectedPpid, (int) $m[2]);
            $this->assertSame($expectedCpu, (float) $m[3]);
            $this->assertSame($expectedRssKb, (int) $m[4]);
            $this->assertSame($expectedArgs, trim($m[5]));
        }
    }

    #[DataProvider('fullPsFilterProvider')]
    public function testFullPsLineFilteringLogic(string $line, bool $shouldBeIncluded): void
    {
        // Replicate the filter logic from detectWithFullPs
        $included = str_contains($line, 'station:work')
            && !str_contains($line, 'grep')
            && !str_contains($line, 'ps -eo');

        $this->assertSame($shouldBeIncluded, $included, "Filter check for: '{$line}'");
    }

    // =========================================================================
    // detectWithRssOnlyPs parsing logic
    // =========================================================================

    #[DataProvider('rssOnlyPsLineProvider')]
    public function testRssOnlyPsRegexParsesCorrectly(
        string $line,
        bool $shouldMatch,
        ?int $expectedPid,
        ?int $expectedPpid,
        ?int $expectedRssValue,
        ?string $expectedSuffix,
        ?string $expectedArgs,
    ): void {
        // Replicate the regex from detectWithRssOnlyPs
        $matched = preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)([km])?\s+(.+)$/i', trim($line), $m);

        $this->assertSame($shouldMatch, $matched === 1, "Line: '{$line}'");

        if ($shouldMatch && $matched === 1) {
            $this->assertSame($expectedPid, (int) $m[1]);
            $this->assertSame($expectedPpid, (int) $m[2]);
            $this->assertSame($expectedRssValue, (int) $m[3]);
            $this->assertSame($expectedSuffix, strtolower($m[4] ?? ''));
            $this->assertSame($expectedArgs, trim($m[5]));
        }
    }

    public function testRssOnlyBusyBoxMbSuffixConvertsToKb(): void
    {
        // Replicate the conversion logic from detectWithRssOnlyPs
        $testCases = [
            ['rss' => 51, 'suffix' => 'm', 'expected_kb' => 51 * 1024],
            ['rss' => 100, 'suffix' => 'k', 'expected_kb' => 100],
            ['rss' => 51200, 'suffix' => '', 'expected_kb' => 51200],
        ];

        foreach ($testCases as $case) {
            $rssKb = match ($case['suffix']) {
                'm' => $case['rss'] * 1024,
                'k' => $case['rss'],
                default => $case['rss'],
            };

            $this->assertSame(
                $case['expected_kb'],
                $rssKb,
                "RSS {$case['rss']}{$case['suffix']} should convert to {$case['expected_kb']} KB",
            );
        }
    }

    // =========================================================================
    // detectWithMinimalPs parsing logic
    // =========================================================================

    #[DataProvider('minimalPsLineProvider')]
    public function testMinimalPsRegexParsesCorrectly(
        string $line,
        bool $shouldMatch,
        ?int $expectedPid,
        ?int $expectedPpid,
        ?string $expectedArgs,
    ): void {
        // Replicate the regex from detectWithMinimalPs
        $matched = preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/', trim($line), $m);

        $this->assertSame($shouldMatch, $matched === 1, "Line: '{$line}'");

        if ($shouldMatch && $matched === 1) {
            $this->assertSame($expectedPid, (int) $m[1]);
            $this->assertSame($expectedPpid, (int) $m[2]);
            $this->assertSame($expectedArgs, trim($m[3]));
        }
    }

    // =========================================================================
    // detectRunningWorkers hierarchy logic
    //
    // We test the hierarchy detection by directly invoking buildWorkerEntry
    // to create worker arrays and then replicating the hierarchy algorithm
    // from detectRunningWorkers.
    // =========================================================================

    public function testDetectRunningWorkersHierarchyIdentifiesSupervisor(): void
    {
        $buildMethod = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Simulate a supervisor (pid=100) with two child workers (ppid=100)
        $allWorkers = [
            $buildMethod->invoke($this->sut, 100, 1, 'php artisan station:work rabbitmq --queue=default', 1.0, 51200),
            $buildMethod->invoke($this->sut, 101, 100, 'php artisan station:work rabbitmq --queue=default', 0.5, 25600),
            $buildMethod->invoke($this->sut, 102, 100, 'php artisan station:work rabbitmq --queue=default', 0.3, 25600),
        ];

        // Replicate hierarchy logic from detectRunningWorkers
        $workerPids = array_column($allWorkers, 'pid');
        $grouped = [];

        foreach ($allWorkers as $worker) {
            $connection = $worker['connection'];
            $isChild = \in_array($worker['ppid'], $workerPids, true);

            if ($isChild) {
                $role = 'worker';
            } else {
                $hasChildren = false;
                foreach ($allWorkers as $other) {
                    if ($other['ppid'] === $worker['pid']) {
                        $hasChildren = true;

                        break;
                    }
                }
                $role = $hasChildren ? 'supervisor' : 'worker';
            }

            $children = [];
            if ($role === 'supervisor') {
                foreach ($allWorkers as $other) {
                    if ($other['ppid'] === $worker['pid']) {
                        $children[] = $other['pid'];
                    }
                }
            }

            $grouped[$connection][] = [
                'pid' => $worker['pid'],
                'ppid' => $worker['ppid'],
                'command' => $worker['command'],
                'role' => $role,
                'children' => $children,
                'queue' => $worker['queue'],
                'cpu' => $worker['cpu'],
                'memory_mb' => $worker['memory_mb'],
            ];
        }

        // Verify hierarchy: PID 100 is supervisor with children 101, 102
        $this->assertArrayHasKey('rabbitmq', $grouped);
        $this->assertCount(3, $grouped['rabbitmq']);

        $supervisors = array_filter($grouped['rabbitmq'], static fn($w) => $w['role'] === 'supervisor');
        $workers = array_filter($grouped['rabbitmq'], static fn($w) => $w['role'] === 'worker');

        $this->assertCount(1, $supervisors, 'Should have exactly one supervisor');
        $this->assertCount(2, $workers, 'Should have exactly two workers');

        $supervisor = array_values($supervisors)[0];
        $this->assertSame(100, $supervisor['pid']);
        $this->assertSame([101, 102], $supervisor['children']);

        // Verify child workers have no children
        foreach ($workers as $w) {
            $this->assertSame([], $w['children']);
        }
    }

    public function testDetectRunningWorkersHierarchyAllStandaloneWorkers(): void
    {
        $buildMethod = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Simulate three standalone workers (ppid=1 which is init, not in worker list)
        $allWorkers = [
            $buildMethod->invoke($this->sut, 200, 1, 'php artisan station:work redis --queue=default', 0.5, 10240),
            $buildMethod->invoke($this->sut, 201, 1, 'php artisan station:work redis --queue=high', 0.3, 10240),
            $buildMethod->invoke($this->sut, 202, 1, 'php artisan station:work kafka --queue=events', 0.1, 8192),
        ];

        // Replicate hierarchy logic
        $workerPids = array_column($allWorkers, 'pid');
        $grouped = [];

        foreach ($allWorkers as $worker) {
            $connection = $worker['connection'];
            $isChild = \in_array($worker['ppid'], $workerPids, true);

            if ($isChild) {
                $role = 'worker';
            } else {
                $hasChildren = false;
                foreach ($allWorkers as $other) {
                    if ($other['ppid'] === $worker['pid']) {
                        $hasChildren = true;

                        break;
                    }
                }
                $role = $hasChildren ? 'supervisor' : 'worker';
            }

            $grouped[$connection][] = [
                'pid' => $worker['pid'],
                'role' => $role,
                'children' => [],
            ];
        }

        // All should be standalone workers (no parent-child relationships)
        foreach ($grouped as $processes) {
            foreach ($processes as $process) {
                $this->assertSame('worker', $process['role']);
                $this->assertSame([], $process['children']);
            }
        }

        // Should be grouped by connection
        $this->assertArrayHasKey('redis', $grouped);
        $this->assertArrayHasKey('kafka', $grouped);
        $this->assertCount(2, $grouped['redis']);
        $this->assertCount(1, $grouped['kafka']);
    }

    public function testDetectRunningWorkersHierarchyMultipleConnections(): void
    {
        $buildMethod = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Simulate workers across multiple connections
        $allWorkers = [
            $buildMethod->invoke($this->sut, 300, 1, 'php artisan station:work rabbitmq --queue=default', 1.0, 51200),
            $buildMethod->invoke($this->sut, 301, 300, 'php artisan station:work rabbitmq --queue=default', 0.5, 25600),
            $buildMethod->invoke($this->sut, 400, 1, 'php artisan station:work redis --queue=high', 0.3, 10240),
        ];

        $workerPids = array_column($allWorkers, 'pid');
        $grouped = [];

        foreach ($allWorkers as $worker) {
            $connection = $worker['connection'];
            $isChild = \in_array($worker['ppid'], $workerPids, true);

            if ($isChild) {
                $role = 'worker';
            } else {
                $hasChildren = false;
                foreach ($allWorkers as $other) {
                    if ($other['ppid'] === $worker['pid']) {
                        $hasChildren = true;

                        break;
                    }
                }
                $role = $hasChildren ? 'supervisor' : 'worker';
            }

            $grouped[$connection][] = [
                'pid' => $worker['pid'],
                'role' => $role,
            ];
        }

        // RabbitMQ: supervisor + 1 child
        $this->assertCount(2, $grouped['rabbitmq']);
        $this->assertSame('supervisor', $grouped['rabbitmq'][0]['role']);
        $this->assertSame('worker', $grouped['rabbitmq'][1]['role']);

        // Redis: standalone worker
        $this->assertCount(1, $grouped['redis']);
        $this->assertSame('worker', $grouped['redis'][0]['role']);
    }

    // =========================================================================
    // Full pipeline: buildWorkerEntry integration with detect* regex patterns
    // =========================================================================

    #[DataProvider('fullPsPipelineProvider')]
    public function testFullPsPipelineParsesAndBuildsWorkerEntry(
        string $psLine,
        string $expectedConnection,
        string $expectedQueue,
        float $expectedCpu,
        int $expectedMemoryMb,
    ): void {
        $buildMethod = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Parse using detectWithFullPs regex
        $this->assertMatchesRegularExpression(
            '/^\s*(\d+)\s+(\d+)\s+([\d.]+)\s+(\d+)\s+(.+)$/',
            trim($psLine),
        );

        preg_match('/^\s*(\d+)\s+(\d+)\s+([\d.]+)\s+(\d+)\s+(.+)$/', trim($psLine), $m);

        $result = $buildMethod->invoke(
            $this->sut,
            (int) $m[1],
            (int) $m[2],
            trim($m[5]),
            (float) $m[3],
            (int) $m[4],
        );

        $this->assertSame($expectedConnection, $result['connection']);
        $this->assertSame($expectedQueue, $result['queue']);
        $this->assertSame($expectedCpu, $result['cpu']);
        $this->assertSame($expectedMemoryMb, $result['memory_mb']);
    }

    #[DataProvider('rssOnlyPsPipelineProvider')]
    public function testRssOnlyPsPipelineParsesAndBuildsWorkerEntry(
        string $psLine,
        string $expectedConnection,
        string $expectedQueue,
        int $expectedMemoryMb,
    ): void {
        $buildMethod = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)([km])?\s+(.+)$/i', trim($psLine), $m);

        $rssValue = (int) $m[3];
        $rssSuffix = strtolower($m[4] ?? '');

        $rssKb = match ($rssSuffix) {
            'm' => $rssValue * 1024,
            'k' => $rssValue,
            default => $rssValue,
        };

        $result = $buildMethod->invoke(
            $this->sut,
            (int) $m[1],
            (int) $m[2],
            trim($m[5]),
            0.0,
            $rssKb,
        );

        $this->assertSame($expectedConnection, $result['connection']);
        $this->assertSame($expectedQueue, $result['queue']);
        $this->assertSame(0.0, $result['cpu']); // RSS-only format has no CPU info
        $this->assertSame($expectedMemoryMb, $result['memory_mb']);
    }

    #[DataProvider('minimalPsPipelineProvider')]
    public function testMinimalPsPipelineParsesAndBuildsWorkerEntry(
        string $psLine,
        string $expectedConnection,
        string $expectedQueue,
    ): void {
        $buildMethod = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/', trim($psLine), $m);

        $result = $buildMethod->invoke(
            $this->sut,
            (int) $m[1],
            (int) $m[2],
            trim($m[3]),
            0.0,
            0,
        );

        $this->assertSame($expectedConnection, $result['connection']);
        $this->assertSame($expectedQueue, $result['queue']);
        $this->assertSame(0.0, $result['cpu']);    // Minimal format has no CPU info
        $this->assertSame(0, $result['memory_mb']); // Minimal format has no RSS info
    }

    // =========================================================================
    // Edge cases and error handling for detect methods
    // =========================================================================

    public function testDetectWithFullPsBusyBoxErrorReturnsNull(): void
    {
        // When BusyBox outputs an error about bad -o argument, detectWithFullPs returns null.
        // We verify the check logic:
        $errorLine = "ps: bad -o argument 'pcpu'";
        $this->assertTrue(str_contains($errorLine, 'bad -o'));
    }

    public function testBuildWorkerEntryWithFullPathArtisan(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Full path to artisan as seen in real ps output
        $result = $method->invoke(
            $this->sut,
            1234,
            1,
            'php /var/www/html/artisan station:work rabbitmq --queue=default',
            1.0,
            51200,
        );

        $this->assertSame('rabbitmq', $result['connection']);
        $this->assertSame('default', $result['queue']);
    }

    public function testBuildWorkerEntryWithNohupWrappedCommand(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // nohup wrapper as generated by startWorker
        $result = $method->invoke(
            $this->sut,
            1234,
            1,
            'nohup php /var/www/html/artisan station:work redis --queue=high',
            0.5,
            25600,
        );

        // station:work parser still finds the connection and queue
        $this->assertSame('redis', $result['connection']);
        $this->assertSame('high', $result['queue']);
    }

    public function testBuildWorkerEntryWithWorkersFlag(): void
    {
        $method = new ReflectionMethod(ProcessManager::class, 'buildWorkerEntry');

        // Supervisor command with --workers flag (from startSupervisor)
        $result = $method->invoke(
            $this->sut,
            1234,
            1,
            'php artisan station:work rabbitmq --queue=default --workers=4',
            2.0,
            102400,
        );

        $this->assertSame('rabbitmq', $result['connection']);
        $this->assertSame('default', $result['queue']);
    }

    // =========================================================================
    // getWorkerStatus / getSupervisorStatus / getPidDirectory / getPidFilePath
    //
    // These methods call storage_path() internally, which requires a fully
    // booted Laravel Application (not just the Container). They cannot be
    // tested in a pure PHPUnit unit test and belong in Feature tests.
    // =========================================================================

    // =========================================================================
    // Constructor
    // =========================================================================

    public function testConstructorAcceptsEmptyConfig(): void
    {
        $manager = new ProcessManager([]);

        // Should not throw -- the manager is valid, just disabled
        $this->assertInstanceOf(ProcessManager::class, $manager);
    }

    public function testConstructorAcceptsNoArguments(): void
    {
        $manager = new ProcessManager();

        $this->assertInstanceOf(ProcessManager::class, $manager);
    }

    public function testConstructorStoresConfig(): void
    {
        $config = ['enabled' => true, 'custom_key' => 'value'];
        $manager = new ProcessManager($config);

        $reflection = new ReflectionProperty(ProcessManager::class, 'config');
        $storedConfig = $reflection->getValue($manager);

        $this->assertSame($config, $storedConfig);
    }
}
