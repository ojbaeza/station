<?php

declare(strict_types=1);

/**
 * Namespace-level function overrides for pcntl_* and posix_* functions.
 *
 * When WorkerSupervisor (in Station\Core namespace) calls pcntl_fork(),
 * PHP resolves the unqualified call to Station\Core\pcntl_fork() first.
 * These overrides let us control system call behavior in tests.
 */

namespace Station\Core {
    /**
     * Global state for controlling mocked pcntl/posix behavior in tests.
     * Tests set these values to simulate different OS-level outcomes.
     */
    class PcntlTestState
    {
        /** @var int|null Return value for pcntl_fork(). null = call real function. */
        public static ?int $forkReturn = null;

        /** @var bool Whether overrides are active. When false, real functions are called. */
        public static bool $enabled = false;

        /** @var array<int, int|null> Map of pid => waitpid return value. null = 0 (still running). */
        public static array $waitpidResults = [];

        /** @var array<int, bool> Map of pid => whether posix_kill(pid, 0) returns true (process exists). */
        public static array $processAlive = [];

        /** @var array<array{pid: int, signal: int}> Log of all posix_kill calls with non-zero signals. */
        public static array $killLog = [];

        /** @var int Auto-increment PID counter for simulated forks. */
        public static int $nextPid = 10001;

        /** @var array<int> Registered signal handlers (signal numbers). */
        public static array $registeredSignals = [];

        /** @var bool Whether pcntl_async_signals was called. */
        public static bool $asyncSignalsCalled = false;

        public static function reset(): void
        {
            self::$forkReturn = null;
            self::$enabled = false;
            self::$waitpidResults = [];
            self::$processAlive = [];
            self::$killLog = [];
            self::$nextPid = 10001;
            self::$registeredSignals = [];
            self::$asyncSignalsCalled = false;
        }
    }

    function pcntl_fork(): int
    {
        if (!PcntlTestState::$enabled) {
            return \pcntl_fork();
        }

        if (PcntlTestState::$forkReturn !== null) {
            return PcntlTestState::$forkReturn;
        }

        // Simulate successful fork in parent: return a fake child PID
        $pid = PcntlTestState::$nextPid++;
        PcntlTestState::$processAlive[$pid] = true;

        return $pid;
    }

    function pcntl_waitpid(int $pid, int &$status, int $flags = 0): int
    {
        if (!PcntlTestState::$enabled) {
            return \pcntl_waitpid($pid, $status, $flags);
        }

        $status = 0;

        // If this PID has a configured result, return it
        if (isset(PcntlTestState::$waitpidResults[$pid])) {
            $result = PcntlTestState::$waitpidResults[$pid];
            if ($result !== null) {
                unset(PcntlTestState::$waitpidResults[$pid]);
                PcntlTestState::$processAlive[$pid] = false;

                return $result;
            }
        }

        // Default: process still running (WNOHANG returns 0)
        return 0;
    }

    function posix_kill(int $pid, int $signal): bool
    {
        if (!PcntlTestState::$enabled) {
            return \posix_kill($pid, $signal);
        }

        if ($signal === 0) {
            // Signal 0 = check if process exists
            return PcntlTestState::$processAlive[$pid] ?? false;
        }

        // Log the kill call
        PcntlTestState::$killLog[] = ['pid' => $pid, 'signal' => $signal];

        // SIGKILL always "kills" the process
        if ($signal === SIGKILL) {
            PcntlTestState::$processAlive[$pid] = false;
            // Make the next waitpid for this PID return reaped
            PcntlTestState::$waitpidResults[$pid] = $pid;
        }

        return true;
    }

    function pcntl_async_signals(bool $enable): bool
    {
        if (!PcntlTestState::$enabled) {
            return \pcntl_async_signals($enable);
        }

        PcntlTestState::$asyncSignalsCalled = true;

        return true;
    }

    function pcntl_signal(int $signal, callable|int $handler): bool
    {
        if (!PcntlTestState::$enabled) {
            return \pcntl_signal($signal, $handler);
        }

        PcntlTestState::$registeredSignals[] = $signal;

        return true;
    }

    function usleep(int $microseconds): void
    {
        if (!PcntlTestState::$enabled) {
            \usleep($microseconds);

            return;
        }

        // No-op in tests to avoid sleeping
    }

    function logger(): object
    {
        // Return a no-op logger for the child process error path
        return new class {
            public function error(string $message): void
            {
                // no-op
            }
        };
    }
}

namespace Station\Tests\Unit\Core {
    use Illuminate\Contracts\Events\Dispatcher;
    use Mockery;
    use Mockery\MockInterface;
    use ReflectionClass;
    use RuntimeException;
    use Station\Contracts\WorkerSupervisorInterface;
    use Station\Core\PcntlTestState;
    use Station\Core\WorkerSupervisor;
    use Station\Events\SupervisorStarted;
    use Station\Events\SupervisorStopped;
    use Station\Events\WorkerStarted;
    use Station\Events\WorkerStopped;
    use Station\Tests\TestCase;

    class WorkerSupervisorTest extends TestCase
    {
        private MockInterface&Dispatcher $events;

        protected function setUp(): void
        {
            parent::setUp();
            $this->events = Mockery::mock(Dispatcher::class);
            PcntlTestState::reset();
        }

        protected function tearDown(): void
        {
            PcntlTestState::reset();
            Mockery::close();
            parent::tearDown();
        }

        // ---------------------------------------------------------------
        // Existing tests (getters and flag toggling)
        // ---------------------------------------------------------------

        public function testGetIdReturnsUuid7(): void
        {
            $supervisor = $this->createSupervisor();
            $id = $supervisor->getId();

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $id,
            );
        }

        public function testGetNameReturnsDefaultName(): void
        {
            $supervisor = $this->createSupervisor();

            $this->assertSame('default', $supervisor->getName());
        }

        public function testGetNameReturnsConfiguredName(): void
        {
            $supervisor = $this->createSupervisor([
                'supervisors' => [
                    'default' => [
                        'name' => 'custom-supervisor',
                    ],
                ],
            ]);

            $this->assertSame('custom-supervisor', $supervisor->getName());
        }

        public function testTerminateSetsQuitFlag(): void
        {
            $supervisor = $this->createSupervisor();

            $supervisor->terminate();

            $reflection = new ReflectionClass($supervisor);
            $property = $reflection->getProperty('shouldQuit');


            $this->assertTrue($property->getValue($supervisor));
        }

        public function testPauseSetsFlag(): void
        {
            $supervisor = $this->createSupervisor();

            $this->assertFalse($supervisor->isPaused());

            $supervisor->pause();

            $this->assertTrue($supervisor->isPaused());
        }

        public function testResumeUnsetsFlag(): void
        {
            $supervisor = $this->createSupervisor();

            $supervisor->pause();
            $this->assertTrue($supervisor->isPaused());

            $supervisor->resume();
            $this->assertFalse($supervisor->isPaused());
        }

        public function testGetWorkerCountInitiallyZero(): void
        {
            $supervisor = $this->createSupervisor();

            $this->assertSame(0, $supervisor->getWorkerCount());
        }

        public function testPauseResumeTogglesCorrectly(): void
        {
            $supervisor = $this->createSupervisor();

            $supervisor->pause();
            $this->assertTrue($supervisor->isPaused());

            $supervisor->resume();
            $this->assertFalse($supervisor->isPaused());

            $supervisor->pause();
            $this->assertTrue($supervisor->isPaused());

            $supervisor->resume();
            $this->assertFalse($supervisor->isPaused());
        }

        public function testEachInstanceHasUniqueId(): void
        {
            $supervisor1 = $this->createSupervisor();
            $supervisor2 = $this->createSupervisor();

            $this->assertNotSame($supervisor1->getId(), $supervisor2->getId());
        }

        public function testImplementsWorkerSupervisorInterface(): void
        {
            $supervisor = $this->createSupervisor();

            $this->assertInstanceOf(WorkerSupervisorInterface::class, $supervisor);
        }

        // ---------------------------------------------------------------
        // Behavioral tests: reapWorkers()
        // ---------------------------------------------------------------

        public function testReapWorkersRemovesExitedWorkerFromPool(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [1001, 1002, 1003]);

            // Simulate worker 1002 has exited
            PcntlTestState::$waitpidResults[1002] = 1002;

            $this->events->shouldReceive('dispatch')
                ->once()
                ->with(Mockery::on(static fn($event) => $event instanceof WorkerStopped
                        && $event->worker === 'worker-1002'
                        && $event->reason === 'exited'
                        && $event->jobsProcessed === 0));

            $this->invokePrivateMethod($supervisor, 'reapWorkers');

            $this->assertSame(2, $supervisor->getWorkerCount());
            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            $this->assertSame([1001, 1003], $pids);
        }

        public function testReapWorkersRemovesMultipleExitedWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [2001, 2002, 2003, 2004]);

            // Simulate workers 2001 and 2003 have exited
            PcntlTestState::$waitpidResults[2001] = 2001;
            PcntlTestState::$waitpidResults[2003] = 2003;

            $this->events->shouldReceive('dispatch')
                ->twice()
                ->with(Mockery::on(static fn($event) => $event instanceof WorkerStopped));

            $this->invokePrivateMethod($supervisor, 'reapWorkers');

            $this->assertSame(2, $supervisor->getWorkerCount());
            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            $this->assertSame([2002, 2004], $pids);
        }

        public function testReapWorkersDoesNothingWhenAllWorkersRunning(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [3001, 3002]);

            // No waitpid results configured = all return 0 (still running)
            $this->events->shouldNotReceive('dispatch');

            $this->invokePrivateMethod($supervisor, 'reapWorkers');

            $this->assertSame(2, $supervisor->getWorkerCount());
        }

        public function testReapWorkersDoesNothingWithEmptyPool(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();

            $this->events->shouldNotReceive('dispatch');

            $this->invokePrivateMethod($supervisor, 'reapWorkers');

            $this->assertSame(0, $supervisor->getWorkerCount());
        }

        public function testReapWorkersReindexesPidArray(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [4001, 4002, 4003]);

            // Remove first worker
            PcntlTestState::$waitpidResults[4001] = 4001;

            $this->events->shouldReceive('dispatch')->once();

            $this->invokePrivateMethod($supervisor, 'reapWorkers');

            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            // Keys should be re-indexed to 0, 1 (not 1, 2)
            $this->assertSame([4002, 4003], $pids);
            $this->assertSame([0, 1], array_keys($pids));
        }

        public function testReapWorkersRemovesAllExitedWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [5001, 5002]);

            PcntlTestState::$waitpidResults[5001] = 5001;
            PcntlTestState::$waitpidResults[5002] = 5002;

            $this->events->shouldReceive('dispatch')->twice();

            $this->invokePrivateMethod($supervisor, 'reapWorkers');

            $this->assertSame(0, $supervisor->getWorkerCount());
            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            $this->assertSame([], $pids);
        }

        // ---------------------------------------------------------------
        // Behavioral tests: maintainWorkerPool()
        // ---------------------------------------------------------------

        public function testMaintainWorkerPoolDoesNothingWhenShouldQuit(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 3]],
            ]);

            $supervisor->terminate(); // sets shouldQuit = true

            // Even with 0 workers and desired=3, no workers should be started
            $this->events->shouldNotReceive('dispatch');

            $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

            $this->assertSame(0, $supervisor->getWorkerCount());
        }

        public function testMaintainWorkerPoolStartsWorkersToReachDesiredCount(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 3]],
            ]);

            // Set current queues and options (normally set by start())
            $this->setPrivateProperty($supervisor, 'currentQueues', ['emails', 'notifications']);
            $this->setPrivateProperty($supervisor, 'currentOptions', ['processes' => 3]);

            // Expect 3 WorkerStarted events (one per fork)
            $this->events->shouldReceive('dispatch')
                ->times(3)
                ->with(Mockery::on(static fn($event) => $event instanceof WorkerStarted
                        && $event->queues === ['emails', 'notifications']));

            $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

            $this->assertSame(3, $supervisor->getWorkerCount());
        }

        public function testMaintainWorkerPoolStartsOnlyMissingWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 3]],
            ]);

            $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
            $this->setPrivateProperty($supervisor, 'currentOptions', ['processes' => 3]);

            // Already have 2 workers
            $this->injectWorkerPids($supervisor, [6001, 6002]);

            // Should only start 1 more
            $this->events->shouldReceive('dispatch')
                ->once()
                ->with(Mockery::on(static fn($event) => $event instanceof WorkerStarted));

            $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

            $this->assertSame(3, $supervisor->getWorkerCount());
        }

        public function testMaintainWorkerPoolDoesNothingWhenAtDesiredCount(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 2]],
            ]);

            $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
            $this->setPrivateProperty($supervisor, 'currentOptions', ['processes' => 2]);

            $this->injectWorkerPids($supervisor, [7001, 7002]);

            $this->events->shouldNotReceive('dispatch');

            $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

            $this->assertSame(2, $supervisor->getWorkerCount());
        }

        public function testMaintainWorkerPoolUsesOptionsProcessCountOverConfig(): void
        {
            PcntlTestState::$enabled = true;

            // Config says 2, but options say 5
            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 2]],
            ]);

            $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
            $this->setPrivateProperty($supervisor, 'currentOptions', ['processes' => 5]);

            $this->events->shouldReceive('dispatch')
                ->times(5)
                ->with(Mockery::on(static fn($e) => $e instanceof WorkerStarted));

            $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

            $this->assertSame(5, $supervisor->getWorkerCount());
        }

        public function testMaintainWorkerPoolDefaultsToOneProcess(): void
        {
            PcntlTestState::$enabled = true;

            // No processes configured anywhere
            $supervisor = $this->createSupervisor([]);

            $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
            $this->setPrivateProperty($supervisor, 'currentOptions', []);

            $this->events->shouldReceive('dispatch')
                ->once()
                ->with(Mockery::on(static fn($e) => $e instanceof WorkerStarted));

            $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

            $this->assertSame(1, $supervisor->getWorkerCount());
        }

        // ---------------------------------------------------------------
        // Behavioral tests: startWorker()
        // ---------------------------------------------------------------

        public function testStartWorkerThrowsExceptionOnForkFailure(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$forkReturn = -1;

            $supervisor = $this->createSupervisor();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Failed to fork worker process');

            $this->invokePrivateMethod($supervisor, 'startWorker', [['default'], []]);
        }

        public function testStartWorkerTracksNewPidOnSuccess(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$nextPid = 9001;

            $supervisor = $this->createSupervisor();

            $this->events->shouldReceive('dispatch')
                ->once()
                ->with(Mockery::on(static fn($event) => $event instanceof WorkerStarted
                        && $event->worker === 'worker-9001'
                        && $event->queues === ['high', 'low']
                        && $event->options === ['timeout' => 60]));

            $this->invokePrivateMethod($supervisor, 'startWorker', [['high', 'low'], ['timeout' => 60]]);

            $this->assertSame(1, $supervisor->getWorkerCount());
            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            $this->assertSame([9001], $pids);
        }

        public function testStartWorkerAccumulatesPids(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$nextPid = 8001;

            $supervisor = $this->createSupervisor();

            $this->events->shouldReceive('dispatch')->times(3);

            $this->invokePrivateMethod($supervisor, 'startWorker', [['default'], []]);
            $this->invokePrivateMethod($supervisor, 'startWorker', [['default'], []]);
            $this->invokePrivateMethod($supervisor, 'startWorker', [['default'], []]);

            $this->assertSame(3, $supervisor->getWorkerCount());
            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            $this->assertSame([8001, 8002, 8003], $pids);
        }

        // NOTE: terminateWorkers() tests omitted because the source uses \time()
        // (fully-qualified), which bypasses namespace-level overrides, causing hangs.

        // ---------------------------------------------------------------
        // Behavioral tests: pause() signal forwarding
        // ---------------------------------------------------------------

        public function testPauseSendsSigusr1ToAliveWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [16001, 16002, 16003]);

            PcntlTestState::$processAlive[16001] = true;
            PcntlTestState::$processAlive[16002] = false; // dead
            PcntlTestState::$processAlive[16003] = true;

            $supervisor->pause();

            $this->assertTrue($supervisor->isPaused());

            $sigusr1Calls = array_filter(
                PcntlTestState::$killLog,
                static fn($call) => $call['signal'] === SIGUSR1,
            );

            $this->assertCount(2, $sigusr1Calls);

            $signalledPids = array_column($sigusr1Calls, 'pid');
            $this->assertContains(16001, $signalledPids);
            $this->assertContains(16003, $signalledPids);
            $this->assertNotContains(16002, $signalledPids, 'Dead process should not receive SIGUSR1');
        }

        public function testPauseWithNoWorkersOnlySetsFlag(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();

            $supervisor->pause();

            $this->assertTrue($supervisor->isPaused());
            $this->assertEmpty(PcntlTestState::$killLog);
        }

        // ---------------------------------------------------------------
        // Behavioral tests: resume() signal forwarding
        // ---------------------------------------------------------------

        public function testResumeSendsSigusr2ToAliveWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [17001, 17002]);

            PcntlTestState::$processAlive[17001] = true;
            PcntlTestState::$processAlive[17002] = true;

            $supervisor->pause();

            // Clear the kill log from pause() SIGUSR1 signals
            PcntlTestState::$killLog = [];

            $supervisor->resume();

            $this->assertFalse($supervisor->isPaused());

            $sigusr2Calls = array_filter(
                PcntlTestState::$killLog,
                static fn($call) => $call['signal'] === SIGUSR2,
            );

            $this->assertCount(2, $sigusr2Calls);

            $signalledPids = array_column($sigusr2Calls, 'pid');
            $this->assertContains(17001, $signalledPids);
            $this->assertContains(17002, $signalledPids);
        }

        public function testResumeSkipsDeadWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();
            $this->injectWorkerPids($supervisor, [18001, 18002]);

            PcntlTestState::$processAlive[18001] = false;
            PcntlTestState::$processAlive[18002] = true;

            $supervisor->pause();
            PcntlTestState::$killLog = [];

            $supervisor->resume();

            $sigusr2Calls = array_filter(
                PcntlTestState::$killLog,
                static fn($call) => $call['signal'] === SIGUSR2,
            );

            $this->assertCount(1, $sigusr2Calls);
            $this->assertSame(18002, $sigusr2Calls[array_key_first($sigusr2Calls)]['pid']);
        }

        // ---------------------------------------------------------------
        // Behavioral tests: loop()
        // ---------------------------------------------------------------

        public function testLoopReapsAndMaintainsPool(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 2]],
            ]);

            $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
            $this->setPrivateProperty($supervisor, 'currentOptions', ['processes' => 2]);

            // Start with 2 workers
            $this->injectWorkerPids($supervisor, [19001, 19002]);

            // Worker 19001 dies
            PcntlTestState::$waitpidResults[19001] = 19001;

            // Expect: WorkerStopped for 19001, then WorkerStarted for replacement
            $this->events->shouldReceive('dispatch')
                ->once()
                ->with(Mockery::on(static fn($e) => $e instanceof WorkerStopped && $e->worker === 'worker-19001'));

            $this->events->shouldReceive('dispatch')
                ->once()
                ->with(Mockery::on(static fn($e) => $e instanceof WorkerStarted));

            $this->invokePrivateMethod($supervisor, 'loop');

            // Pool should be back to 2
            $this->assertSame(2, $supervisor->getWorkerCount());
        }

        public function testLoopDoesNotMaintainPoolWhenQuitting(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 2]],
            ]);

            $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
            $this->setPrivateProperty($supervisor, 'currentOptions', ['processes' => 2]);

            $this->injectWorkerPids($supervisor, [20001, 20002]);
            PcntlTestState::$waitpidResults[20001] = 20001;

            $supervisor->terminate(); // shouldQuit = true

            // Should see WorkerStopped but NOT WorkerStarted
            $this->events->shouldReceive('dispatch')
                ->once()
                ->with(Mockery::on(static fn($e) => $e instanceof WorkerStopped));

            $this->invokePrivateMethod($supervisor, 'loop');

            // Pool should now be at 1 (not replaced because we're quitting)
            $this->assertSame(1, $supervisor->getWorkerCount());
        }

        // NOTE: start() tests omitted because start() calls terminateWorkers()
        // during shutdown, which uses \time() (fully-qualified) and hangs.

        // ---------------------------------------------------------------
        // Behavioral tests: registerSignalHandlers()
        // ---------------------------------------------------------------

        public function testRegisterSignalHandlersRegistersExpectedSignals(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();

            $this->invokePrivateMethod($supervisor, 'registerSignalHandlers');

            $this->assertTrue(PcntlTestState::$asyncSignalsCalled, 'pcntl_async_signals should be enabled');

            // Should register handlers for SIGTERM, SIGINT, SIGQUIT, SIGUSR1, SIGUSR2
            $this->assertContains(SIGTERM, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGINT, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGQUIT, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGUSR1, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGUSR2, PcntlTestState::$registeredSignals);
            $this->assertCount(5, PcntlTestState::$registeredSignals);
        }

        // ---------------------------------------------------------------
        // Behavioral tests: getWorkerCount() after mutations
        // ---------------------------------------------------------------

        public function testGetWorkerCountReflectsPoolChanges(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();

            $this->assertSame(0, $supervisor->getWorkerCount());

            // Add workers
            $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

            $this->invokePrivateMethod($supervisor, 'startWorker', [['default'], []]);
            $this->assertSame(1, $supervisor->getWorkerCount());

            $this->invokePrivateMethod($supervisor, 'startWorker', [['default'], []]);
            $this->assertSame(2, $supervisor->getWorkerCount());

            // Kill one
            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            PcntlTestState::$waitpidResults[$pids[0]] = $pids[0];

            $this->invokePrivateMethod($supervisor, 'reapWorkers');
            $this->assertSame(1, $supervisor->getWorkerCount());
        }

        // ---------------------------------------------------------------
        // Behavioral tests: integration scenario (reap + maintain cycle)
        // ---------------------------------------------------------------

        public function testReapAndMaintainCycleReplacesDeadWorkers(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$nextPid = 20001;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 3]],
            ]);

            $this->setPrivateProperty($supervisor, 'currentQueues', ['default']);
            $this->setPrivateProperty($supervisor, 'currentOptions', ['processes' => 3]);

            // Start with 3 workers
            $this->injectWorkerPids($supervisor, [20001, 20002, 20003]);
            PcntlTestState::$nextPid = 20004; // Next fork gets this PID

            // Two workers die
            PcntlTestState::$waitpidResults[20001] = 20001;
            PcntlTestState::$waitpidResults[20003] = 20003;

            $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

            // Run one loop cycle (reap + maintain)
            $this->invokePrivateMethod($supervisor, 'reapWorkers');

            $this->assertSame(1, $supervisor->getWorkerCount(), 'After reap, only 1 worker remains');

            $this->invokePrivateMethod($supervisor, 'maintainWorkerPool');

            $this->assertSame(3, $supervisor->getWorkerCount(), 'After maintain, pool is back to 3');

            $pids = $this->getPrivateProperty($supervisor, 'workerPids');
            $this->assertContains(20002, $pids, 'Surviving worker should remain');
            $this->assertNotContains(20001, $pids, 'Dead worker should be gone');
            $this->assertNotContains(20003, $pids, 'Dead worker should be gone');
        }

        // ---------------------------------------------------------------
        // Behavioral tests: terminateWorkers()
        // ---------------------------------------------------------------

        public function testTerminateWorkersSendsSigtermToAllWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'shutdown' => ['timeout' => 30],
            ]);
            $this->injectWorkerPids($supervisor, [21001, 21002]);

            PcntlTestState::$processAlive[21001] = true;
            PcntlTestState::$processAlive[21002] = true;

            // After SIGTERM, simulate workers exiting on next reap
            PcntlTestState::$waitpidResults[21001] = 21001;
            PcntlTestState::$waitpidResults[21002] = 21002;

            $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

            $this->invokePrivateMethod($supervisor, 'terminateWorkers');

            // Verify SIGTERM was sent to both workers
            $sigtermCalls = array_filter(
                PcntlTestState::$killLog,
                static fn($call) => $call['signal'] === SIGTERM,
            );

            $this->assertGreaterThanOrEqual(2, \count($sigtermCalls));

            $termedPids = array_column($sigtermCalls, 'pid');
            $this->assertContains(21001, $termedPids);
            $this->assertContains(21002, $termedPids);
        }

        public function testTerminateWorkersSkipsDeadProcessesForSigterm(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'shutdown' => ['timeout' => 0], // Use timeout=0 to avoid 30s wait
            ]);
            $this->injectWorkerPids($supervisor, [22001, 22002]);

            PcntlTestState::$processAlive[22001] = true;
            PcntlTestState::$processAlive[22002] = false; // Already dead

            // After SIGTERM, simulate 22001 exiting on next reap
            PcntlTestState::$waitpidResults[22001] = 22001;
            // 22002 is also reaped (already dead)
            PcntlTestState::$waitpidResults[22002] = 22002;

            $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

            $this->invokePrivateMethod($supervisor, 'terminateWorkers');

            // Verify only live process got SIGTERM
            $sigtermCalls = array_filter(
                PcntlTestState::$killLog,
                static fn($call) => $call['signal'] === SIGTERM,
            );

            $termedPids = array_column($sigtermCalls, 'pid');
            $this->assertContains(22001, $termedPids);
            $this->assertNotContains(22002, $termedPids);
        }

        public function testTerminateWorkersForceKillsRemainingAfterTimeout(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'shutdown' => ['timeout' => 0], // 0 second timeout so SIGKILL is sent immediately
            ]);
            $this->injectWorkerPids($supervisor, [23001]);

            PcntlTestState::$processAlive[23001] = true;
            // Don't set waitpidResults so the worker stays "alive" through the timeout
            // With timeout=0, the while loop won't iterate, and we go straight to SIGKILL

            $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

            $this->invokePrivateMethod($supervisor, 'terminateWorkers');

            // Verify SIGKILL was sent
            $sigkillCalls = array_filter(
                PcntlTestState::$killLog,
                static fn($call) => $call['signal'] === SIGKILL,
            );

            $this->assertNotEmpty($sigkillCalls);
            $killedPids = array_column($sigkillCalls, 'pid');
            $this->assertContains(23001, $killedPids);
        }

        public function testTerminateWorkersWithNoWorkers(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor([
                'shutdown' => ['timeout' => 30],
            ]);
            // No workers injected

            $this->events->shouldNotReceive('dispatch');

            $this->invokePrivateMethod($supervisor, 'terminateWorkers');

            // No signals should have been sent
            $this->assertEmpty(PcntlTestState::$killLog);
        }

        public function testTerminateWorkersDefaultTimeout(): void
        {
            PcntlTestState::$enabled = true;

            // No shutdown.timeout configured - should use default of 30
            $supervisor = $this->createSupervisor([]);
            $this->injectWorkerPids($supervisor, [24001]);

            PcntlTestState::$processAlive[24001] = true;
            PcntlTestState::$waitpidResults[24001] = 24001; // exits immediately

            $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

            $this->invokePrivateMethod($supervisor, 'terminateWorkers');

            // Should have sent SIGTERM
            $sigtermCalls = array_filter(
                PcntlTestState::$killLog,
                static fn($call) => $call['signal'] === SIGTERM,
            );
            $this->assertNotEmpty($sigtermCalls);
        }

        // ---------------------------------------------------------------
        // Behavioral tests: collectMetrics()
        // ---------------------------------------------------------------

        public function testCollectMetricsIsCallable(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();

            // collectMetrics is a placeholder; just verify it doesn't throw
            $this->invokePrivateMethod($supervisor, 'collectMetrics');

            $this->addToAssertionCount(1);
        }

        // NOTE: The child process branch (pid === 0 from pcntl_fork) cannot be
        // unit-tested because it calls exit(0) which would terminate the test process.
        // The child branch is covered by integration tests with real forking.

        // ---------------------------------------------------------------
        // Behavioral tests: start() method (end-to-end with quick quit)
        // ---------------------------------------------------------------

        public function testStartMethodDispatchesEventsAndForks(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$nextPid = 25001;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 2]],
                'shutdown' => ['timeout' => 0],
            ]);

            // Schedule the supervisor to quit immediately after starting
            $this->setPrivateProperty($supervisor, 'shouldQuit', true);

            $dispatchedEvents = [];
            $this->events->shouldReceive('dispatch')
                ->zeroOrMoreTimes()
                ->andReturnUsing(static function ($event) use (&$dispatchedEvents): void {
                    $dispatchedEvents[] = $event;
                });

            // Since shouldQuit is already true, the while loop won't execute
            // and it will go straight to terminateWorkers + SupervisorStopped
            $supervisor->start(['emails', 'high'], ['processes' => 2]);

            // Verify SupervisorStarted was dispatched
            $supervisorStartedEvents = array_filter(
                $dispatchedEvents,
                static fn($e) => $e instanceof SupervisorStarted,
            );
            $this->assertCount(1, $supervisorStartedEvents);

            $started = array_values($supervisorStartedEvents)[0];
            $this->assertSame($supervisor->getId(), $started->supervisorId);
            $this->assertSame($supervisor->getName(), $started->name);
            $this->assertSame(['emails', 'high'], $started->queues);

            // Verify WorkerStarted events (2 workers)
            $workerStartedEvents = array_filter(
                $dispatchedEvents,
                static fn($e) => $e instanceof WorkerStarted,
            );
            $this->assertCount(2, $workerStartedEvents);

            // Verify SupervisorStopped was dispatched
            $supervisorStoppedEvents = array_filter(
                $dispatchedEvents,
                static fn($e) => $e instanceof SupervisorStopped,
            );
            $this->assertCount(1, $supervisorStoppedEvents);

            $stopped = array_values($supervisorStoppedEvents)[0];
            $this->assertSame('shutdown', $stopped->reason);

            // Verify queues and options were stored
            $queues = $this->getPrivateProperty($supervisor, 'currentQueues');
            $options = $this->getPrivateProperty($supervisor, 'currentOptions');
            $this->assertSame(['emails', 'high'], $queues);
            $this->assertSame(['processes' => 2], $options);
        }

        public function testStartMethodUsesConfigProcessCount(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$nextPid = 26001;

            $supervisor = $this->createSupervisor([
                'supervisors' => ['default' => ['processes' => 3]],
                'shutdown' => ['timeout' => 0],
            ]);

            $this->setPrivateProperty($supervisor, 'shouldQuit', true);

            $workerStartedCount = 0;
            $this->events->shouldReceive('dispatch')
                ->zeroOrMoreTimes()
                ->andReturnUsing(static function ($event) use (&$workerStartedCount): void {
                    if ($event instanceof WorkerStarted) {
                        $workerStartedCount++;
                    }
                });

            $supervisor->start(['default'], []);

            // Should have dispatched 3 WorkerStarted events
            $this->assertSame(3, $workerStartedCount);
        }

        public function testStartMethodDefaultsToOneProcess(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$nextPid = 27001;

            $supervisor = $this->createSupervisor([
                'shutdown' => ['timeout' => 0],
            ]);

            $this->setPrivateProperty($supervisor, 'shouldQuit', true);

            $workerStartedCount = 0;
            $this->events->shouldReceive('dispatch')
                ->zeroOrMoreTimes()
                ->andReturnUsing(static function ($event) use (&$workerStartedCount): void {
                    if ($event instanceof WorkerStarted) {
                        $workerStartedCount++;
                    }
                });

            $supervisor->start(['default'], []);

            // Default: 1 process
            $this->assertSame(1, $workerStartedCount);
        }

        public function testStartMethodRegistersSignalHandlers(): void
        {
            PcntlTestState::$enabled = true;
            PcntlTestState::$nextPid = 28001;

            $supervisor = $this->createSupervisor([
                'shutdown' => ['timeout' => 0],
            ]);

            $this->setPrivateProperty($supervisor, 'shouldQuit', true);
            $this->events->shouldReceive('dispatch')->zeroOrMoreTimes();

            $supervisor->start(['default'], []);

            // Verify signal handlers were registered
            $this->assertTrue(PcntlTestState::$asyncSignalsCalled);
            $this->assertContains(SIGTERM, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGINT, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGQUIT, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGUSR1, PcntlTestState::$registeredSignals);
            $this->assertContains(SIGUSR2, PcntlTestState::$registeredSignals);
        }

        // ---------------------------------------------------------------
        // Edge case: multiple pause/resume calls
        // ---------------------------------------------------------------

        public function testMultiplePauseCallsAreIdempotent(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();

            $supervisor->pause();
            $supervisor->pause();
            $supervisor->pause();

            $this->assertTrue($supervisor->isPaused());
        }

        public function testMultipleResumeCallsAreIdempotent(): void
        {
            PcntlTestState::$enabled = true;

            $supervisor = $this->createSupervisor();

            $supervisor->resume();
            $supervisor->resume();

            $this->assertFalse($supervisor->isPaused());
        }

        public function testTerminateIsIdempotent(): void
        {
            $supervisor = $this->createSupervisor();

            $supervisor->terminate();
            $supervisor->terminate();

            $shouldQuit = $this->getPrivateProperty($supervisor, 'shouldQuit');
            $this->assertTrue($shouldQuit);
        }

        // ---------------------------------------------------------------
        // Helper methods
        // ---------------------------------------------------------------

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
}
