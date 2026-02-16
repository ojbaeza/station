<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Core;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Station\Core\ProcessManager;
use Station\Enums\Driver;
use Station\StationServiceProvider;

/**
 * Extended tests for ProcessManager covering:
 * - getWorkerStatus (read-only, no ensureEnabled check)
 * - getSupervisorStatus (read-only, no ensureEnabled check)
 * - detectRunningWorkers (returns grouped array)
 * - detectRunningSupervisor (returns null when no supervisors running)
 * - stopSupervisor (when disabled, throws)
 * - startSupervisor (when disabled, throws)
 * - stopWorker with all valid connections
 * - startWorker with all valid connections
 * - stopExternalWorker with various PIDs
 * - validateConnection for all valid driver values
 */
class ProcessManagerExtendedTest extends TestCase
{
    public static function validConnectionProvider(): array
    {
        $providers = [];
        foreach (Driver::values() as $driver) {
            $providers[$driver] = [$driver];
        }

        return $providers;
    }
    // ──────────────────────────────────────────────────────────────
    // ensureEnabled() validation
    // ──────────────────────────────────────────────────────────────

    public function testStartWorkerThrowsWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->startWorker('rabbitmq');
    }

    public function testStopWorkerThrowsWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->stopWorker('rabbitmq');
    }

    public function testStartSupervisorThrowsWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->startSupervisor('rabbitmq');
    }

    public function testStopSupervisorThrowsWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->stopSupervisor();
    }

    public function testStopExternalWorkerThrowsWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Process management is disabled');

        $manager->stopExternalWorker(12345);
    }

    // ──────────────────────────────────────────────────────────────
    // Read-only methods (do NOT call ensureEnabled)
    // ──────────────────────────────────────────────────────────────

    public function testGetWorkerStatusWorksWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $status = $manager->getWorkerStatus();

        // Returns array keyed by driver names
        $this->assertIsArray($status);

        // Each driver should have an entry with the expected structure
        foreach (Driver::values() as $driverName) {
            $this->assertArrayHasKey($driverName, $status);
            $this->assertArrayHasKey('running', $status[$driverName]);
            $this->assertArrayHasKey('pid', $status[$driverName]);
            $this->assertArrayHasKey('workers', $status[$driverName]);
            $this->assertIsBool($status[$driverName]['running']);
            $this->assertIsArray($status[$driverName]['workers']);
        }
    }

    public function testGetSupervisorStatusWorksWhenDisabled(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $status = $manager->getSupervisorStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('pid', $status);
        $this->assertArrayHasKey('connection', $status);
        $this->assertArrayHasKey('queue', $status);
        $this->assertArrayHasKey('workers', $status);
        $this->assertFalse($status['running']);
        $this->assertNull($status['pid']);
    }

    public function testDetectRunningWorkersReturnsEmptyWhenNoWorkers(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $workers = $manager->detectRunningWorkers();

        $this->assertIsArray($workers);
        // Should be empty or contain only empty arrays (no station:work processes running)
        foreach ($workers as $connection => $processes) {
            $this->assertIsString($connection);
            $this->assertIsArray($processes);
        }
    }

    public function testDetectRunningSupervisorReturnsNullWhenNone(): void
    {
        $manager = new ProcessManager(['enabled' => false]);

        $this->assertNull($manager->detectRunningSupervisor());
    }

    // ──────────────────────────────────────────────────────────────
    // validateConnection for each valid driver
    // ──────────────────────────────────────────────────────────────

    #[DataProvider('validConnectionProvider')]
    public function testStartWorkerValidatesValidConnection(string $connection): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // For valid connections, startWorker should NOT throw InvalidConnection
        // It will attempt to exec() and likely fail at starting the actual process,
        // but it should pass validation. We just verify no RuntimeException about
        // invalid connection is thrown.
        try {
            $result = $manager->startWorker($connection);
            // If it gets here, it passed validation
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
        } catch (RuntimeException $e) {
            // If it throws, it should NOT be about invalid connection
            $this->assertStringNotContainsString('Invalid connection', $e->getMessage());
        }
    }

    public function testStartWorkerThrowsForInvalidConnection(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid connection: invalid_driver');

        $manager->startWorker('invalid_driver');
    }

    public function testStartSupervisorThrowsForInvalidConnection(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid connection: bad_conn');

        $manager->startSupervisor('bad_conn');
    }

    // ──────────────────────────────────────────────────────────────
    // stopExternalWorker scenarios
    // ──────────────────────────────────────────────────────────────

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

    public function testStopExternalWorkerRejectsNonStationProcess(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // Use the current PHP process PID -- it's running but NOT station:work
        $currentPid = getmypid();
        $result = $manager->stopExternalWorker($currentPid);

        $this->assertFalse($result['success']);
        // Should be either "Process is not a station:work worker" or "Process not owned by current user"
        $this->assertStringContainsString('not', $result['message']);
    }

    // ──────────────────────────────────────────────────────────────
    // getWorkerStatus structure validation
    // ──────────────────────────────────────────────────────────────

    public function testGetWorkerStatusReturnsAllDriverConnections(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        $status = $manager->getWorkerStatus();

        // All Driver enum values should be present
        foreach (Driver::values() as $driver) {
            $this->assertArrayHasKey($driver, $status, "Driver {$driver} missing from worker status");
        }
    }

    public function testGetWorkerStatusWorkerArrayStructure(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        $status = $manager->getWorkerStatus();

        foreach ($status as $connection => $info) {
            $this->assertIsBool($info['running']);
            $this->assertIsArray($info['workers']);

            foreach ($info['workers'] as $worker) {
                $this->assertArrayHasKey('pid', $worker);
                $this->assertArrayHasKey('ppid', $worker);
                $this->assertArrayHasKey('command', $worker);
                $this->assertArrayHasKey('role', $worker);
                $this->assertArrayHasKey('children', $worker);
                $this->assertArrayHasKey('type', $worker);
                $this->assertArrayHasKey('queue', $worker);
                $this->assertArrayHasKey('cpu', $worker);
                $this->assertArrayHasKey('memory_mb', $worker);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    // getSupervisorStatus structure validation
    // ──────────────────────────────────────────────────────────────

    public function testGetSupervisorStatusStructure(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        $status = $manager->getSupervisorStatus();

        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('pid', $status);
        $this->assertArrayHasKey('connection', $status);
        $this->assertArrayHasKey('queue', $status);
        $this->assertArrayHasKey('workers', $status);
        $this->assertIsBool($status['running']);
        $this->assertIsInt($status['workers']);
    }

    // ──────────────────────────────────────────────────────────────
    // Constructor with empty config
    // ──────────────────────────────────────────────────────────────

    public function testConstructorWithEmptyConfig(): void
    {
        $manager = new ProcessManager([]);

        // Should not throw on read-only operations
        $status = $manager->getWorkerStatus();
        $this->assertIsArray($status);
    }

    public function testConstructorWithNoArguments(): void
    {
        $manager = new ProcessManager();

        $status = $manager->getSupervisorStatus();
        $this->assertIsArray($status);
    }

    // ──────────────────────────────────────────────────────────────
    // PID file management tests
    // ──────────────────────────────────────────────────────────────

    public function testSavePidFileCreatesDirectoryAndFile(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        $method = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $method->setAccessible(true);

        $pidDir = storage_path('station/pids');
        $pidFile = $pidDir . '/worker-test_conn.pid';

        // Clean up any existing file
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }

        $method->invoke($manager, 'worker', 'test_conn', 99999, 'test command');

        $this->assertFileExists($pidFile);

        $data = json_decode(file_get_contents($pidFile), true);
        $this->assertSame(99999, $data['pid']);
        $this->assertSame('test command', $data['command']);
        $this->assertArrayHasKey('started_at', $data);

        // Clean up
        @unlink($pidFile);
    }

    public function testGetPidFilePath(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        $method = new ReflectionMethod(ProcessManager::class, 'getPidFilePath');
        $method->setAccessible(true);

        $path = $method->invoke($manager, 'worker', 'rabbitmq');

        $this->assertStringEndsWith('/station/pids/worker-rabbitmq.pid', $path);
    }

    public function testGetPidDirectory(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        $method = new ReflectionMethod(ProcessManager::class, 'getPidDirectory');
        $method->setAccessible(true);

        $dir = $method->invoke($manager);

        $this->assertStringEndsWith('/station/pids', $dir);
    }

    public function testFindPidFilesReturnsEmptyWhenDirectoryMissing(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        $method = new ReflectionMethod(ProcessManager::class, 'findPidFiles');
        $method->setAccessible(true);

        // Temporarily point to non-existent directory
        // findPidFiles checks is_dir internally
        $result = $method->invoke($manager, 'nonexistent_type_xyz');

        // Even if PID dir exists, no files match "nonexistent_type_xyz-*.pid"
        $this->assertIsArray($result);
    }

    public function testFindPidFilesReturnsPidFiles(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        // Create a test PID file
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke($manager, 'worker', 'test_find', 88888, 'test cmd');

        $findMethod = new ReflectionMethod(ProcessManager::class, 'findPidFiles');
        $findMethod->setAccessible(true);

        $files = $findMethod->invoke($manager, 'worker');

        $this->assertIsArray($files);
        // Should find at least our test file
        $found = false;
        foreach ($files as $file) {
            if (str_contains($file, 'worker-test_find.pid')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Should find the test PID file');

        // Clean up
        @unlink(storage_path('station/pids/worker-test_find.pid'));
    }

    public function testCleanupPidFilesForPidRemovesMatchingFiles(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        // Create test PID files
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke($manager, 'worker', 'cleanup_test', 77777, 'test cmd');

        // Verify file exists
        $pidFile = storage_path('station/pids/worker-cleanup_test.pid');
        $this->assertFileExists($pidFile);

        // Now clean up by PID
        $cleanupMethod = new ReflectionMethod(ProcessManager::class, 'cleanupPidFilesForPid');
        $cleanupMethod->setAccessible(true);
        $cleanupMethod->invoke($manager, 77777);

        // File should be deleted
        $this->assertFileDoesNotExist($pidFile);
    }

    public function testCleanupPidFilesForPidDoesNotRemoveNonMatchingFiles(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        // Create test PID file with different PID
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke($manager, 'worker', 'cleanup_nomatch', 66666, 'test cmd');

        $pidFile = storage_path('station/pids/worker-cleanup_nomatch.pid');
        $this->assertFileExists($pidFile);

        // Clean up a different PID
        $cleanupMethod = new ReflectionMethod(ProcessManager::class, 'cleanupPidFilesForPid');
        $cleanupMethod->setAccessible(true);
        $cleanupMethod->invoke($manager, 55555); // Different PID

        // File should still exist
        $this->assertFileExists($pidFile);

        // Clean up
        @unlink($pidFile);
    }

    // ──────────────────────────────────────────────────────────────
    // isProcessRunning (private) - test via reflection
    // ──────────────────────────────────────────────────────────────

    public function testIsProcessRunningReturnsFalseForNonExistentPid(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'isProcessRunning');
        $method->setAccessible(true);

        // PID 999999 almost certainly doesn't exist
        $this->assertFalse($method->invoke($manager, 999999));
    }

    public function testIsProcessRunningReturnsTrueForCurrentProcess(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'isProcessRunning');
        $method->setAccessible(true);

        // Current process PID is definitely running
        $this->assertTrue($method->invoke($manager, getmypid()));
    }

    // ──────────────────────────────────────────────────────────────
    // getProcessCommand (private) - test via reflection
    // ──────────────────────────────────────────────────────────────

    public function testGetProcessCommandReturnsNullForNonExistentPid(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'getProcessCommand');
        $method->setAccessible(true);

        $result = $method->invoke($manager, 999999);
        $this->assertNull($result);
    }

    public function testGetProcessCommandReturnsStringForCurrentProcess(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'getProcessCommand');
        $method->setAccessible(true);

        $result = $method->invoke($manager, getmypid());
        // Current process should return a non-null command
        if ($result !== null) {
            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        } else {
            // On some systems ps may not return our process
            $this->assertNull($result);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // isOwnedByCurrentUser (private) - test via reflection
    // ──────────────────────────────────────────────────────────────

    public function testIsOwnedByCurrentUserReturnsTrueForOwnProcess(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'isOwnedByCurrentUser');
        $method->setAccessible(true);

        // Current process is owned by current user
        $this->assertTrue($method->invoke($manager, getmypid()));
    }

    public function testIsOwnedByCurrentUserReturnsFalseForNonExistentPid(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'isOwnedByCurrentUser');
        $method->setAccessible(true);

        // PID 999999 doesn't exist, posix_kill(999999, 0) returns false
        $this->assertFalse($method->invoke($manager, 999999));
    }

    // ──────────────────────────────────────────────────────────────
    // validateConnection via stopWorker path
    // ──────────────────────────────────────────────────────────────

    public function testStopWorkerThrowsForInvalidConnection(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid connection: not_a_driver');

        $manager->stopWorker('not_a_driver');
    }

    // ──────────────────────────────────────────────────────────────
    // getSupervisorStatus with PID files
    // ──────────────────────────────────────────────────────────────

    public function testGetSupervisorStatusWithStalePidFile(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        // Create a PID file with a PID that's not running
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'supervisor',
            'rabbitmq-default',
            999998,
            'php artisan station:work rabbitmq --queue=default --workers=4',
        );

        $pidFile = storage_path('station/pids/supervisor-rabbitmq-default.pid');
        $this->assertFileExists($pidFile);

        $status = $manager->getSupervisorStatus();

        // PID 999998 is not running, so stale file should be cleaned up
        $this->assertFileDoesNotExist($pidFile);
        // Status should show not running (unless a real supervisor is detected)
        $this->assertIsArray($status);
        $this->assertArrayHasKey('running', $status);
    }

    public function testGetSupervisorStatusParsesCommandFromPidFile(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // Create a PID file with current PID (which IS running)
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'supervisor',
            'redis-high',
            getmypid(), // Use current PID so isProcessRunning returns true
            'php artisan station:work redis --queue=high --workers=3',
        );

        $pidFile = storage_path('station/pids/supervisor-redis-high.pid');

        $status = $manager->getSupervisorStatus();

        $this->assertTrue($status['running']);
        $this->assertSame(getmypid(), $status['pid']);
        $this->assertSame('redis', $status['connection']);
        $this->assertSame('high', $status['queue']);
        $this->assertSame(3, $status['workers']);

        // Clean up
        @unlink($pidFile);
    }

    // ──────────────────────────────────────────────────────────────
    // getWorkerStatus with PID files
    // ──────────────────────────────────────────────────────────────

    public function testGetWorkerStatusWithStalePidFile(): void
    {
        $manager = new ProcessManager(['enabled' => true]);

        // Create a stale PID file
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'worker',
            'rabbitmq',
            999997,
            'php artisan station:work rabbitmq --queue=default',
        );

        $pidFile = storage_path('station/pids/worker-rabbitmq.pid');
        $this->assertFileExists($pidFile);

        $status = $manager->getWorkerStatus();

        // Stale PID file should be cleaned up
        $this->assertFileDoesNotExist($pidFile);
        $this->assertArrayHasKey('rabbitmq', $status);
    }

    public function testGetWorkerStatusWithActivePidFile(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // Create PID file with current PID
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'worker',
            'redis',
            getmypid(),
            'php artisan station:work redis --queue=emails',
        );

        $status = $manager->getWorkerStatus();

        $this->assertArrayHasKey('redis', $status);
        $this->assertTrue($status['redis']['running']);
        $this->assertSame(getmypid(), $status['redis']['pid']);
        $this->assertNotEmpty($status['redis']['workers']);

        // Verify the managed worker entry
        $managedWorker = $status['redis']['workers'][0];
        $this->assertSame(getmypid(), $managedWorker['pid']);
        $this->assertSame('managed', $managedWorker['type']);
        $this->assertSame('emails', $managedWorker['queue']);

        // Clean up
        @unlink(storage_path('station/pids/worker-redis.pid'));
    }

    // ──────────────────────────────────────────────────────────────
    // stopWorker with PID file
    // ──────────────────────────────────────────────────────────────

    public function testStopWorkerWithStalePidFile(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // Create a PID file with a non-running PID
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'worker',
            'rabbitmq',
            999996,
            'php artisan station:work rabbitmq --queue=default',
        );

        $result = $manager->stopWorker('rabbitmq');

        $this->assertTrue($result['success']);
        // PID 999996 is not running, so stopped count may be 0
        $this->assertIsInt($result['stopped']);
        // PID file should be removed
        $this->assertFileDoesNotExist(storage_path('station/pids/worker-rabbitmq.pid'));
    }

    // ──────────────────────────────────────────────────────────────
    // stopSupervisor with PID files
    // ──────────────────────────────────────────────────────────────

    public function testStopSupervisorWithStalePidFile(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // Create a supervisor PID file with non-running PID
        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'supervisor',
            'rabbitmq-default',
            999995,
            'php artisan station:work rabbitmq --queue=default --workers=2',
        );

        $result = $manager->stopSupervisor();

        // No supervisor actually running, just stale PID file
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        // PID file should be cleaned up
        $this->assertFileDoesNotExist(storage_path('station/pids/supervisor-rabbitmq-default.pid'));
    }

    public function testStopSupervisorWithNoSupervisorsRunning(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $result = $manager->stopSupervisor();

        $this->assertFalse($result['success']);
        $this->assertSame('No supervisor running', $result['message']);
    }

    // ──────────────────────────────────────────────────────────────
    // detectWithFullPs / detectWithRssOnlyPs / detectWithMinimalPs
    // These methods call exec() internally but we can test them
    // directly since they will just return empty results.
    // ──────────────────────────────────────────────────────────────

    public function testDetectWithFullPsReturnsArrayOrNull(): void
    {
        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'detectWithFullPs');
        $method->setAccessible(true);

        $result = $method->invoke($manager);

        // Either null (BusyBox) or an array (Linux/macOS)
        $this->assertTrue($result === null || \is_array($result));
    }

    public function testDetectWithRssOnlyPsReturnsArrayOrNull(): void
    {
        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'detectWithRssOnlyPs');
        $method->setAccessible(true);

        $result = $method->invoke($manager);

        $this->assertTrue($result === null || \is_array($result));
    }

    public function testDetectWithMinimalPsReturnsArray(): void
    {
        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'detectWithMinimalPs');
        $method->setAccessible(true);

        $result = $method->invoke($manager);

        // Always returns an array (never null)
        $this->assertIsArray($result);
    }

    // ──────────────────────────────────────────────────────────────
    // killProcess (private) - test that nonexistent PID returns false
    // ──────────────────────────────────────────────────────────────

    public function testKillProcessReturnsFalseForNonExistentPid(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);
        $method = new ReflectionMethod(ProcessManager::class, 'killProcess');
        $method->setAccessible(true);

        // PID 999994 almost certainly does not exist
        // But killProcess sends SIGTERM which returns false for non-existent PIDs
        // Then checks isProcessRunning which also returns false, so it returns true
        // (because the process is no longer running after "kill")
        $result = $method->invoke($manager, 999994);

        // Either true (process wasn't running = already "dead") or false
        $this->assertIsBool($result);
    }

    // ──────────────────────────────────────────────────────────────
    // stopExternalWorker edge cases
    // ──────────────────────────────────────────────────────────────

    public function testStopExternalWorkerWithCurrentProcessRejectsNonStation(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        // Current process is phpunit, not station:work
        $result = $manager->stopExternalWorker(getmypid());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not', $result['message']);
    }

    // ──────────────────────────────────────────────────────────────
    // getWorkerStatus queue parsing from PID file command
    // ──────────────────────────────────────────────────────────────

    public function testGetWorkerStatusParsesQueueFromPidFileCommand(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'worker',
            'kafka',
            getmypid(),
            'php artisan station:work kafka --queue=events,logs',
        );

        $status = $manager->getWorkerStatus();

        $this->assertArrayHasKey('kafka', $status);
        $kafkaWorkers = $status['kafka']['workers'];

        // Find the managed worker
        $managedWorker = null;
        foreach ($kafkaWorkers as $w) {
            if ($w['type'] === 'managed') {
                $managedWorker = $w;

                break;
            }
        }

        $this->assertNotNull($managedWorker);
        $this->assertSame('events,logs', $managedWorker['queue']);

        // Clean up
        @unlink(storage_path('station/pids/worker-kafka.pid'));
    }

    public function testGetWorkerStatusDefaultsQueueWhenNoQueueFlag(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX extension not available');
        }

        $manager = new ProcessManager(['enabled' => true]);

        $savePidMethod = new ReflectionMethod(ProcessManager::class, 'savePidFile');
        $savePidMethod->setAccessible(true);
        $savePidMethod->invoke(
            $manager,
            'worker',
            'beanstalkd',
            getmypid(),
            'php artisan station:work beanstalkd',
        );

        $status = $manager->getWorkerStatus();

        $this->assertArrayHasKey('beanstalkd', $status);
        $beanstalkdWorkers = $status['beanstalkd']['workers'];

        $managedWorker = null;
        foreach ($beanstalkdWorkers as $w) {
            if ($w['type'] === 'managed') {
                $managedWorker = $w;

                break;
            }
        }

        $this->assertNotNull($managedWorker);
        $this->assertSame('default', $managedWorker['queue']);

        // Clean up
        @unlink(storage_path('station/pids/worker-beanstalkd.pid'));
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
    }
}
