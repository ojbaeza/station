<?php

declare(strict_types=1);

namespace Station\Core;

use RuntimeException;
use Station\Enums\Driver;

final class ProcessManager
{
    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Start worker(s) for a connection.
     *
     * @return array{success: bool, pids: list<int>, command: string, message: string}
     */
    public function startWorker(string $connection, string $queue = 'default', int $workers = 1): array
    {
        $this->ensureEnabled();
        $this->validateConnection($connection);

        $artisan = base_path('artisan');
        $logFile = storage_path("logs/station_{$connection}.log");
        $command = \sprintf(
            'nohup php %s station:work %s --queue=%s > %s 2>&1 </dev/null &',
            escapeshellarg($artisan),
            escapeshellarg($connection),
            escapeshellarg($queue),
            escapeshellarg($logFile),
        );

        $allSucceeded = true;

        for ($i = 0; $i < $workers; $i++) {
            $output = [];
            $resultCode = 0;

            exec($command, $output, $resultCode);

            if ($resultCode !== 0) {
                $allSucceeded = false;
            }
        }

        // Allow processes to register, then detect PIDs
        usleep(200_000);

        $pids = [];
        $detected = $this->detectRunningWorkers();

        foreach ($detected[$connection] ?? [] as $info) {
            $pids[] = $info['pid'];
            $this->savePidFile('worker', $connection . '-' . $info['pid'], $info['pid'], $command);
        }

        return [
            'success' => $allSucceeded,
            'pids' => $pids,
            'command' => $command,
            'message' => $allSucceeded ? "Started {$workers} worker(s) for {$connection}" : 'Some workers failed to start',
        ];
    }

    /**
     * Stop all workers for a connection.
     *
     * @return array{success: bool, stopped: int, message: string}
     */
    public function stopWorker(string $connection): array
    {
        $this->ensureEnabled();
        $this->validateConnection($connection);

        $stopped = 0;

        // Stop PID-file tracked workers
        $pidFile = $this->getPidFilePath('worker', $connection);

        if (file_exists($pidFile)) {
            $data = json_decode(file_get_contents($pidFile) ?: '{}', true);
            $pid = $data['pid'] ?? null;

            if ($pid !== null && $this->isProcessRunning($pid)) {
                if ($this->killProcess($pid)) {
                    $stopped++;
                }
            }

            @unlink($pidFile);
        }

        // Also stop externally detected workers
        $detected = $this->detectRunningWorkers();

        foreach ($detected[$connection] ?? [] as $info) {
            if ($this->killProcess($info['pid'])) {
                $stopped++;
            }
        }

        return [
            'success' => true,
            'stopped' => $stopped,
            'message' => "Stopped {$stopped} worker(s) for {$connection}",
        ];
    }

    /**
     * Stop an external worker by PID after verifying it's a station:work process owned by current user.
     *
     * @return array{success: bool, message: string}
     */
    public function stopExternalWorker(int $pid): array
    {
        $this->ensureEnabled();

        if (!$this->isProcessRunning($pid)) {
            return ['success' => false, 'message' => 'Process not running'];
        }

        // Verify it's a station:work process
        $command = $this->getProcessCommand($pid);

        if ($command === null || !str_contains($command, 'station:work')) {
            return ['success' => false, 'message' => 'Process is not a station:work worker'];
        }

        // Verify process ownership
        if (!$this->isOwnedByCurrentUser($pid)) {
            return ['success' => false, 'message' => 'Process not owned by current user'];
        }

        $killed = $this->killProcess($pid);

        if ($killed) {
            $this->cleanupPidFilesForPid($pid);
        }

        return [
            'success' => $killed,
            'message' => $killed ? "Worker (PID {$pid}) stopped" : "Failed to stop worker (PID {$pid})",
        ];
    }

    /**
     * Get worker status per connection with hierarchy.
     *
     * @return array<string, array{running: bool, pid: int|null, workers: list<array{pid: int, ppid: int, command: string, role: string, children: list<int>, type: string, queue: string, cpu: float, memory_mb: int}>}>
     */
    public function getWorkerStatus(): array
    {
        $status = [];
        $detected = $this->detectRunningWorkers();

        foreach (Driver::values() as $connection) {
            $workers = [];
            $mainPid = null;

            // Check PID file
            $pidFile = $this->getPidFilePath('worker', $connection);

            if (file_exists($pidFile)) {
                $data = json_decode(file_get_contents($pidFile) ?: '{}', true);
                $pid = $data['pid'] ?? null;

                if ($pid !== null && $this->isProcessRunning($pid)) {
                    $mainPid = $pid;
                    $cmd = $data['command'] ?? '';
                    $pidQueue = 'default';
                    if (preg_match('/--queue=(\S+)/', $cmd, $qm)) {
                        $pidQueue = $qm[1];
                    }
                    $workers[] = [
                        'pid' => $pid,
                        'ppid' => 0,
                        'command' => $cmd,
                        'role' => 'worker',
                        'children' => [],
                        'type' => 'managed',
                        'queue' => $pidQueue,
                        'cpu' => 0.0,
                        'memory_mb' => 0,
                    ];
                } else {
                    // Stale PID file — clean up
                    @unlink($pidFile);
                }
            }

            // Add externally detected workers (already grouped by connection)
            $connectionProcesses = $detected[$connection] ?? [];

            foreach ($connectionProcesses as $info) {
                $alreadyTracked = false;

                foreach ($workers as $idx => $w) {
                    if ($w['pid'] === $info['pid']) {
                        // Update managed worker with hierarchy info from detection
                        $workers[$idx]['role'] = $info['role'];
                        $workers[$idx]['ppid'] = $info['ppid'];
                        $workers[$idx]['children'] = $info['children'];
                        $workers[$idx]['queue'] = $info['queue'];
                        $workers[$idx]['cpu'] = $info['cpu'];
                        $workers[$idx]['memory_mb'] = $info['memory_mb'];
                        $alreadyTracked = true;

                        break;
                    }
                }

                if (!$alreadyTracked) {
                    $mainPid ??= $info['pid'];
                    $workers[] = [
                        'pid' => $info['pid'],
                        'ppid' => $info['ppid'],
                        'command' => $info['command'],
                        'role' => $info['role'],
                        'children' => $info['children'],
                        'type' => 'external',
                        'queue' => $info['queue'],
                        'cpu' => $info['cpu'],
                        'memory_mb' => $info['memory_mb'],
                    ];
                }
            }

            $status[$connection] = [
                'running' => $workers !== [],
                'pid' => $mainPid !== null ? (int) $mainPid : null,
                'workers' => array_values($workers),
            ];
        }

        return $status;
    }

    /**
     * Start the supervisor process.
     *
     * @return array{success: bool, pid: int|null, message: string}
     */
    public function startSupervisor(string $connection, string $queue = 'default', int $workers = 1): array
    {
        $this->ensureEnabled();
        $this->validateConnection($connection);

        $artisan = base_path('artisan');
        $logFile = storage_path("logs/station_{$connection}.log");
        $command = \sprintf(
            'nohup php %s station:work %s --queue=%s --workers=%d > %s 2>&1 </dev/null &',
            escapeshellarg($artisan),
            escapeshellarg($connection),
            escapeshellarg($queue),
            $workers,
            escapeshellarg($logFile),
        );

        $output = [];
        $resultCode = 0;

        exec($command, $output, $resultCode);

        // Allow process to register, then detect PID
        usleep(200_000);

        $pid = null;
        $detected = $this->detectRunningWorkers();

        foreach ($detected[$connection] ?? [] as $info) {
            // Find the process matching our queue (supervisor role preferred)
            if ($info['queue'] === $queue) {
                $pid = $info['pid'];

                break;
            }
        }

        if ($pid !== null && $pid > 0) {
            $this->savePidFile('supervisor', $connection . '-' . $queue, $pid, $command);
        }

        return [
            'success' => $resultCode === 0,
            'pid' => $pid,
            'message' => $resultCode === 0 ? 'Supervisor started' : 'Failed to start supervisor',
        ];
    }

    /**
     * Stop the supervisor process.
     *
     * @return array{success: bool, message: string}
     */
    public function stopSupervisor(): array
    {
        $this->ensureEnabled();

        $stopped = 0;

        // Stop all supervisor PID files
        foreach ($this->findPidFiles('supervisor') as $pidFile) {
            $data = json_decode(file_get_contents($pidFile) ?: '{}', true);
            $pid = $data['pid'] ?? null;

            if ($pid !== null && $this->isProcessRunning($pid)) {
                if ($this->killProcess($pid)) {
                    $stopped++;
                }
            }

            @unlink($pidFile);
        }

        // Also stop any detected supervisors not tracked by PID files
        $detected = $this->detectRunningWorkers();

        foreach ($detected as $workers) {
            foreach ($workers as $info) {
                if ($info['role'] === 'supervisor') {
                    if ($this->killProcess($info['pid'])) {
                        $stopped++;
                    }
                }
            }
        }

        return [
            'success' => $stopped > 0,
            'message' => $stopped > 0 ? "Stopped {$stopped} supervisor(s)" : 'No supervisor running',
        ];
    }

    /**
     * Get supervisor status.
     *
     * @return array{running: bool, pid: int|null, connection: string|null, queue: string|null, workers: int}
     */
    public function getSupervisorStatus(): array
    {
        $pid = null;
        $connection = null;
        $queue = null;
        $workerCount = 0;

        // Check supervisor PID files
        foreach ($this->findPidFiles('supervisor') as $pidFile) {
            $data = json_decode(file_get_contents($pidFile) ?: '{}', true);
            $filePid = $data['pid'] ?? null;

            if ($filePid !== null && $this->isProcessRunning($filePid)) {
                $pid = $filePid;

                // Parse connection/queue from command
                $command = $data['command'] ?? '';
                if (preg_match('/station:work\s+(\S+)/', $command, $m)) {
                    $connection = $m[1];
                }
                if (preg_match('/--queue=(\S+)/', $command, $m)) {
                    $queue = $m[1];
                }
                if (preg_match('/--workers=(\d+)/', $command, $m)) {
                    $workerCount = (int) $m[1];
                }

                break;
            }

            @unlink($pidFile);
        }

        // Fallback: detect running supervisor
        if ($pid === null) {
            $pid = $this->detectRunningSupervisor();
        }

        return [
            'running' => $pid !== null,
            'pid' => $pid,
            'connection' => $connection,
            'queue' => $queue,
            'workers' => $workerCount,
        ];
    }

    /**
     * Detect running station:work processes with parent/child hierarchy, grouped by connection.
     *
     * @return array<string, list<array{pid: int, ppid: int, command: string, role: string, children: list<int>, queue: string, cpu: float, memory_mb: int}>>
     */
    public function detectRunningWorkers(): array
    {
        $allWorkers = [];

        // Try full format (Linux/macOS), then without pcpu (Alpine/BusyBox), then minimal
        $parsed = $this->detectWithFullPs();
        if ($parsed === null) {
            $parsed = $this->detectWithRssOnlyPs();
        }
        if ($parsed === null) {
            $allWorkers = $this->detectWithMinimalPs();
        } else {
            $allWorkers = $parsed;
        }

        // Build hierarchy: identify supervisors (parents) and workers (children)
        $workerPids = array_column($allWorkers, 'pid');
        $grouped = [];

        foreach ($allWorkers as $worker) {
            $connection = $worker['connection'];

            // PPID in PID list → child worker
            $isChild = \in_array($worker['ppid'], $workerPids, true);

            if ($isChild) {
                $role = 'worker';
            } else {
                // Check if this process has children (is a supervisor)
                $hasChildren = false;

                foreach ($allWorkers as $other) {
                    if ($other['ppid'] === $worker['pid']) {
                        $hasChildren = true;

                        break;
                    }
                }

                $role = $hasChildren ? 'supervisor' : 'worker';
            }

            // Collect children PIDs for supervisors
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

        return $grouped;
    }

    /**
     * Detect a running supervisor process (any station:work process that has children).
     */
    public function detectRunningSupervisor(): ?int
    {
        $detected = $this->detectRunningWorkers();

        foreach ($detected as $workers) {
            foreach ($workers as $info) {
                if ($info['role'] === 'supervisor') {
                    return $info['pid'];
                }
            }
        }

        return null;
    }

    /**
     * Kill a process with SIGTERM, then SIGKILL after 5s grace period.
     */
    private function killProcess(int $pid): bool
    {
        if (!\function_exists('posix_kill')) {
            return false;
        }

        // Send SIGTERM
        posix_kill($pid, 15);

        // Wait up to 5 seconds for graceful shutdown
        for ($i = 0; $i < 50; $i++) {
            usleep(100_000); // 100ms

            if (!$this->isProcessRunning($pid)) {
                return true;
            }
        }

        // Force kill with SIGKILL
        posix_kill($pid, 9);

        usleep(100_000);

        return !$this->isProcessRunning($pid); // @phpstan-ignore booleanNot.alwaysFalse
    }

    /**
     * Check if a process is running (excludes zombies).
     */
    private function isProcessRunning(int $pid): bool
    {
        if (!\function_exists('posix_kill')) {
            return false;
        }

        if (!posix_kill($pid, 0)) {
            return false;
        }

        // On Linux, zombies still have a PID entry but are not running
        if (is_readable("/proc/{$pid}/stat")) {
            $stat = file_get_contents("/proc/{$pid}/stat");

            if ($stat !== false && preg_match('/\)\s+Z/', $stat)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the command string for a PID.
     */
    private function getProcessCommand(int $pid): ?string
    {
        $output = [];

        if (PHP_OS_FAMILY === 'Linux' && is_readable("/proc/{$pid}/cmdline")) {
            $cmdline = file_get_contents("/proc/{$pid}/cmdline");

            return $cmdline !== false ? str_replace("\0", ' ', $cmdline) : null;
        }

        // macOS fallback
        exec(\sprintf('ps -p %d -o command= 2>/dev/null', $pid), $output);

        return $output !== [] ? trim($output[0]) : null;
    }

    /**
     * Check if a process is owned by the current user.
     * Uses posix_kill with signal 0 — succeeds only if the current user can signal the process.
     */
    private function isOwnedByCurrentUser(int $pid): bool
    {
        if (!\function_exists('posix_kill')) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    /**
     * Validate that a connection is a known Station driver.
     */
    private function validateConnection(string $connection): void
    {
        if (!\in_array($connection, Driver::values(), true)) {
            throw new RuntimeException("Invalid connection: {$connection}. Must be one of: " . implode(', ', Driver::values()));
        }
    }

    /**
     * Ensure process management is enabled and POSIX is available.
     */
    private function ensureEnabled(): void
    {
        if (!($this->config['enabled'] ?? false)) {
            throw new RuntimeException('Process management is disabled. Set STATION_PROCESS_MANAGEMENT=true to enable.');
        }

        if (!\function_exists('posix_kill')) {
            throw new RuntimeException('Process management requires POSIX extensions (not available on Windows).');
        }
    }

    /**
     * Save a PID file.
     */
    private function savePidFile(string $type, string $name, int $pid, string $command): void
    {
        $dir = $this->getPidDirectory();

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $this->getPidFilePath($type, $name);

        file_put_contents($path, json_encode([
            'pid' => $pid,
            'started_at' => time(),
            'command' => $command,
        ], JSON_THROW_ON_ERROR));

        chmod($path, 0600);
    }

    /**
     * Remove any PID files that reference a given PID.
     */
    private function cleanupPidFilesForPid(int $pid): void
    {
        foreach (['worker', 'supervisor'] as $type) {
            foreach ($this->findPidFiles($type) as $file) {
                $data = json_decode(file_get_contents($file) ?: '{}', true);

                if (($data['pid'] ?? null) === $pid) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Find all PID files matching a type prefix.
     *
     * @return list<string>
     */
    private function findPidFiles(string $type): array
    {
        $dir = $this->getPidDirectory();

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . "/{$type}-*.pid");

        return $files !== false ? $files : [];
    }

    /**
     * Get PID file path.
     */
    private function getPidFilePath(string $type, string $name): string
    {
        return $this->getPidDirectory() . "/{$type}-{$name}.pid";
    }

    /**
     * Get PID directory path.
     */
    private function getPidDirectory(): string
    {
        return storage_path('station/pids');
    }

    /**
     * Try detecting workers with full ps format (Linux/macOS: pid,ppid,pcpu,rss,args).
     *
     * @return list<array{pid: int, ppid: int, command: string, connection: string, queue: string, cpu: float, memory_mb: int}>|null
     */
    private function detectWithFullPs(): ?array
    {
        $output = [];

        exec('ps -eo pid,ppid,pcpu,rss,args 2>/dev/null', $output);

        // BusyBox outputs "ps: bad -o argument 'pcpu'" to stdout and still exits 0
        if ($output === [] || str_contains($output[0], 'bad -o')) {
            return null;
        }

        $workers = [];

        foreach ($output as $line) {
            if (!str_contains($line, 'station:work') || str_contains($line, 'grep') || str_contains($line, 'ps -eo')) {
                continue;
            }

            if (preg_match('/^\s*(\d+)\s+(\d+)\s+([\d.]+)\s+(\d+)\s+(.+)$/', trim($line), $m)) {
                $workers[] = $this->buildWorkerEntry((int) $m[1], (int) $m[2], trim($m[5]), (float) $m[3], (int) $m[4]);
            }
        }

        return $workers !== [] || $this->psHeaderContains($output, 'PCPU') ? $workers : null;
    }

    /**
     * Try detecting workers with rss-only ps format (Alpine/BusyBox: pid,ppid,rss,args).
     *
     * @return list<array{pid: int, ppid: int, command: string, connection: string, queue: string, cpu: float, memory_mb: int}>|null
     */
    private function detectWithRssOnlyPs(): ?array
    {
        $output = [];

        exec('ps -eo pid,ppid,rss,args 2>/dev/null', $output);

        if ($output === [] || str_contains($output[0], 'bad -o')) {
            return null;
        }

        $workers = [];

        foreach ($output as $line) {
            if (!str_contains($line, 'station:work') || str_contains($line, 'grep') || str_contains($line, 'ps -eo')) {
                continue;
            }

            // BusyBox may show rss as "51m" (MB suffix) instead of raw KB
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)([km])?\s+(.+)$/i', trim($line), $m)) {
                $rssValue = (int) $m[3];
                $rssSuffix = strtolower($m[4]);

                // Convert to KB: BusyBox 'm' = megabytes, 'k' = kilobytes, no suffix = kilobytes
                $rssKb = match ($rssSuffix) {
                    'm' => $rssValue * 1024,
                    'k' => $rssValue,
                    default => $rssValue,
                };

                $workers[] = $this->buildWorkerEntry((int) $m[1], (int) $m[2], trim($m[5]), 0.0, $rssKb);
            }
        }

        return $workers !== [] || $this->psHeaderContains($output, 'RSS') ? $workers : null;
    }

    /**
     * Fallback: detect workers with minimal ps format (pid,ppid,args only).
     *
     * @return list<array{pid: int, ppid: int, command: string, connection: string, queue: string, cpu: float, memory_mb: int}>
     */
    private function detectWithMinimalPs(): array
    {
        $output = [];

        exec('ps -eo pid,ppid,args 2>/dev/null || ps -eo pid,ppid,command 2>/dev/null', $output);

        $workers = [];

        foreach ($output as $line) {
            if (!str_contains($line, 'station:work') || str_contains($line, 'grep') || str_contains($line, 'ps -eo')) {
                continue;
            }

            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/', trim($line), $m)) {
                $workers[] = $this->buildWorkerEntry((int) $m[1], (int) $m[2], trim($m[3]), 0.0, 0);
            }
        }

        return $workers;
    }

    /**
     * Build a worker entry from parsed ps fields.
     *
     * @return array{pid: int, ppid: int, command: string, connection: string, queue: string, cpu: float, memory_mb: int}
     */
    private function buildWorkerEntry(int $pid, int $ppid, string $command, float $cpu, int $rssKb): array
    {
        $connection = 'unknown';
        if (preg_match('/station:work\s+(\S+)/', $command, $cm)) {
            $connection = $cm[1];
        }

        $queue = 'default';
        if (preg_match('/--queue=(\S+)/', $command, $qm)) {
            $queue = $qm[1];
        }

        return [
            'pid' => $pid,
            'ppid' => $ppid,
            'command' => $command,
            'connection' => $connection,
            'queue' => $queue,
            'cpu' => $cpu,
            'memory_mb' => (int) round($rssKb / 1024),
        ];
    }

    /**
     * Check if ps output header contains a column name.
     */
    private function psHeaderContains(array $output, string $column): bool
    {
        return isset($output[0]) && str_contains(strtoupper($output[0]), strtoupper($column));
    }
}
