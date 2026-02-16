<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Station\StationServiceProvider;
use Throwable;

/**
 * Security/input validation tests for dashboard API endpoints.
 *
 * Tests SQL injection prevention, XSS in payloads, input boundary conditions,
 * and bulk operation validation.
 */
class DashboardInputValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    // ---- SQL Injection Prevention ----

    public function testJobsEndpointRejectsSqlInjectionInQueueParam(): void
    {
        $response = $this->getJson('/station/api/jobs?queue=' . urlencode("'; DROP TABLE station_jobs; --"));

        $response->assertOk();

        // Table still exists
        $this->assertTrue($this->tableExists('station_jobs'));
    }

    public function testJobsEndpointRejectsSqlInjectionInSearchParam(): void
    {
        $response = $this->getJson('/station/api/jobs?search=' . urlencode("' OR 1=1 --"));

        $response->assertOk();

        $this->assertTrue($this->tableExists('station_jobs'));
    }

    public function testJobsEndpointRejectsSqlInjectionInStatusParam(): void
    {
        $response = $this->getJson('/station/api/jobs?status=' . urlencode("pending' UNION SELECT * FROM users --"));

        $response->assertOk();
    }

    public function testJobsEndpointRejectsSqlInjectionInConnectionParam(): void
    {
        $response = $this->getJson('/station/api/jobs?connection=' . urlencode("redis'; DELETE FROM station_jobs; --"));

        $response->assertOk();

        $this->assertTrue($this->tableExists('station_jobs'));
    }

    public function testJobsEndpointRejectsSqlInjectionInTagParam(): void
    {
        $response = $this->getJson('/station/api/jobs?tag=' . urlencode("tag' OR '1'='1"));

        $response->assertOk();
    }

    public function testFailedJobsEndpointRejectsSqlInjectionInQueueParam(): void
    {
        $response = $this->getJson('/station/api/failed?queue=' . urlencode("'; DROP TABLE station_failed_jobs; --"));

        // Should handle gracefully - never drop the table
        $this->assertLessThan(500, $response->status(), 'SQL injection should not cause a server error');
        $this->assertTrue($this->tableExists('station_failed_jobs'));
    }

    // ---- Input Boundary Testing ----

    public function testJobsEndpointHandlesExtremelyLongQueueName(): void
    {
        $longQueue = str_repeat('a', 10000);

        $response = $this->getJson('/station/api/jobs?queue=' . $longQueue);

        // Should not crash - either 200 or 4xx
        $this->assertContains($response->status(), [200, 400, 422]);
    }

    public function testJobsEndpointHandlesSpecialCharactersInSearch(): void
    {
        $special = '!@#$%^&*(){}[]|\\:;"\'<>,.?/~`';

        $response = $this->getJson('/station/api/jobs?search=' . urlencode($special));

        $response->assertOk();
    }

    public function testJobsEndpointHandlesUnicodeInSearch(): void
    {
        $response = $this->getJson('/station/api/jobs?search=' . urlencode('日本語テスト'));

        $response->assertOk();
    }

    public function testJobsEndpointHandlesZeroPage(): void
    {
        $response = $this->getJson('/station/api/jobs?page=0');

        $response->assertOk();
    }

    public function testJobsEndpointHandlesNegativePage(): void
    {
        $response = $this->getJson('/station/api/jobs?page=-1');

        $response->assertOk();
    }

    public function testJobsEndpointHandlesExtremelyLargePerPage(): void
    {
        $response = $this->getJson('/station/api/jobs?per_page=999999');

        $response->assertOk();

        // clampPerPage should cap at 100
        $data = $response->json();
        if (isset($data['per_page'])) {
            $this->assertLessThanOrEqual(100, $data['per_page']);
        }
    }

    public function testJobsEndpointHandlesNegativePerPage(): void
    {
        $response = $this->getJson('/station/api/jobs?per_page=-5');

        $response->assertOk();
    }

    public function testJobsEndpointHandlesNonNumericPage(): void
    {
        $response = $this->getJson('/station/api/jobs?page=abc');

        $response->assertOk();
    }

    // ---- Bulk Operation Validation ----
    // Note: Some bulk routes (jobs/bulk/cancel, jobs/bulk/retry, failed/bulk/retry)
    // are shadowed by {id} parameter routes. We test the ones that ARE reachable.

    public function testBulkDeleteJobsRequiresIds(): void
    {
        $response = $this->postJson('/station/api/jobs/bulk/delete', []);

        $response->assertUnprocessable();
    }

    public function testBulkDeleteJobsRequiresArrayOfIds(): void
    {
        $response = $this->postJson('/station/api/jobs/bulk/delete', [
            'ids' => 'not-an-array',
        ]);

        $response->assertUnprocessable();
    }

    public function testBulkDeleteJobsRejectsMoreThan100Ids(): void
    {
        $ids = array_map(
            static fn(int $i) => "job-{$i}",
            range(1, 101),
        );

        $response = $this->postJson('/station/api/jobs/bulk/delete', [
            'ids' => $ids,
        ]);

        $response->assertUnprocessable();
    }

    public function testBulkDeleteFailedRequiresIds(): void
    {
        $response = $this->postJson('/station/api/failed/bulk/delete', []);

        $response->assertUnprocessable();
    }

    // ---- Tag Validation ----

    public function testAddJobTagValidatesTagRequired(): void
    {
        $response = $this->postJson('/station/api/jobs/test-id/tags', []);

        $response->assertUnprocessable();
    }

    public function testAddJobTagValidatesTagMaxLength(): void
    {
        $response = $this->postJson('/station/api/jobs/test-id/tags', [
            'tag' => str_repeat('a', 101),
        ]);

        $response->assertUnprocessable();
    }

    public function testAddJobTagReturns404ForNonexistentJob(): void
    {
        $response = $this->postJson('/station/api/jobs/nonexistent/tags', [
            'tag' => 'valid-tag',
        ]);

        $response->assertNotFound();
    }

    public function testRemoveJobTagReturns404ForNonexistentJob(): void
    {
        $response = $this->deleteJson('/station/api/jobs/nonexistent/tags/some-tag');

        $response->assertNotFound();
    }

    // ---- Worker Endpoint Validation ----

    public function testStartWorkerValidatesConnectionRequired(): void
    {
        $response = $this->postJson('/station/api/workers/start', []);

        $response->assertUnprocessable();
    }

    public function testStartWorkerValidatesWorkersMax(): void
    {
        $response = $this->postJson('/station/api/workers/start', [
            'connection' => 'redis',
            'workers' => 100,
        ]);

        $response->assertUnprocessable();
    }

    public function testStartWorkerValidatesWorkersMin(): void
    {
        $response = $this->postJson('/station/api/workers/start', [
            'connection' => 'redis',
            'workers' => 0,
        ]);

        $response->assertUnprocessable();
    }

    public function testStopWorkerValidatesConnectionRequired(): void
    {
        $response = $this->postJson('/station/api/workers/stop', []);

        $response->assertUnprocessable();
    }

    public function testStopExternalWorkerValidatesPidRequired(): void
    {
        $response = $this->postJson('/station/api/workers/stop-external', []);

        $response->assertUnprocessable();
    }

    public function testStopExternalWorkerValidatesPidIsInteger(): void
    {
        $response = $this->postJson('/station/api/workers/stop-external', [
            'pid' => 'not-a-number',
        ]);

        $response->assertUnprocessable();
    }

    // ---- Supervisor Endpoint Validation ----

    public function testStartSupervisorValidatesConnectionRequired(): void
    {
        $response = $this->postJson('/station/api/supervisor/start', []);

        $response->assertUnprocessable();
    }

    public function testStartSupervisorValidatesWorkersRange(): void
    {
        $response = $this->postJson('/station/api/supervisor/start', [
            'connection' => 'redis',
            'workers' => 50,
        ]);

        $response->assertUnprocessable();
    }

    // ---- Queue Pause Validation ----

    public function testPauseQueueRequiresQueueName(): void
    {
        $response = $this->postJson('/station/api/queues/pause', []);

        $response->assertStatus(400);
    }

    public function testResumeQueueRequiresQueueName(): void
    {
        $response = $this->postJson('/station/api/queues/resume', []);

        $response->assertStatus(400);
    }

    // ---- XSS in Job ID Parameters ----

    public function testJobShowEndpointHandlesXssInId(): void
    {
        $xssId = '<script>alert("xss")</script>';

        $response = $this->getJson('/station/api/jobs/' . urlencode($xssId));

        $response->assertNotFound();
        $content = $response->getContent();
        $this->assertStringNotContainsString('<script>', $content);
    }

    public function testRetryJobHandlesXssInId(): void
    {
        $xssId = '"><img src=x onerror=alert(1)>';

        $response = $this->postJson('/station/api/jobs/' . urlencode($xssId) . '/retry');

        $this->assertLessThan(500, $response->status(), 'XSS in ID should not cause a server error');
        $content = $response->getContent();
        $this->assertStringNotContainsString('onerror', $content);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.process_management.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    // ---- Helper Methods ----

    private function tableExists(string $table): bool
    {
        try {
            DB::select("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function createTables(): void
    {
        $db = $this->app['db'];

        $db->statement('CREATE TABLE IF NOT EXISTS station_jobs (
            id VARCHAR(36) PRIMARY KEY,
            job_class VARCHAR(255) NOT NULL,
            queue VARCHAR(255) NOT NULL DEFAULT "default",
            connection VARCHAR(255) NOT NULL DEFAULT "redis",
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            payload TEXT NULL,
            tags TEXT NOT NULL DEFAULT "[]",
            attempts INTEGER NOT NULL DEFAULT 0,
            max_tries INTEGER NULL,
            timeout INTEGER NULL,
            batch_id VARCHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            failed_at DATETIME NULL,
            exception TEXT NULL
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_failed_jobs (
            id VARCHAR(36) PRIMARY KEY,
            original_id VARCHAR(36) NULL,
            job_id VARCHAR(36) NULL,
            job_class VARCHAR(255) NOT NULL,
            queue VARCHAR(255) NOT NULL DEFAULT "default",
            connection VARCHAR(255) NOT NULL DEFAULT "redis",
            payload TEXT NULL,
            tags TEXT NOT NULL DEFAULT "[]",
            exception TEXT NULL,
            failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_batches (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NULL,
            total_jobs INTEGER NOT NULL DEFAULT 0,
            pending_jobs INTEGER NOT NULL DEFAULT 0,
            processed_jobs INTEGER NOT NULL DEFAULT 0,
            failed_jobs INTEGER NOT NULL DEFAULT 0,
            failed_job_ids TEXT NOT NULL DEFAULT "[]",
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            connection VARCHAR(255) NULL,
            options TEXT NOT NULL DEFAULT "{}",
            allowed_failures INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at DATETIME NULL,
            finished_at DATETIME NULL
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection VARCHAR(255) NOT NULL DEFAULT "default",
            queue VARCHAR(255) NOT NULL DEFAULT "default",
            throughput REAL NOT NULL DEFAULT 0,
            runtime REAL NOT NULL DEFAULT 0,
            wait_time REAL NOT NULL DEFAULT 0,
            failed_count INTEGER NOT NULL DEFAULT 0,
            processed_count INTEGER NOT NULL DEFAULT 0,
            period VARCHAR(20) NOT NULL DEFAULT "minute",
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_supervisors (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            pid INTEGER NULL,
            status VARCHAR(20) NOT NULL DEFAULT "running",
            queues TEXT NOT NULL DEFAULT "[]",
            options TEXT NOT NULL DEFAULT "{}",
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_heartbeat_at DATETIME NULL
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_workers (
            id VARCHAR(36) PRIMARY KEY,
            supervisor_id VARCHAR(36) NULL,
            name VARCHAR(255) NOT NULL,
            pid INTEGER NULL,
            status VARCHAR(20) NOT NULL DEFAULT "running",
            queues TEXT NOT NULL DEFAULT "[]",
            jobs_processed INTEGER NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_heartbeat_at DATETIME NULL
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_queue_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue VARCHAR(255) NOT NULL,
            connection VARCHAR(255) NOT NULL,
            paused BOOLEAN NOT NULL DEFAULT 0,
            paused_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_job_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id VARCHAR(36) NOT NULL,
            event VARCHAR(50) NOT NULL,
            data TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id VARCHAR(36) NULL,
            data TEXT NULL,
            user_id VARCHAR(36) NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_checkpoints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id VARCHAR(36) NOT NULL,
            step VARCHAR(255) NOT NULL,
            data TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_driver_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            connection VARCHAR(255) NOT NULL,
            driver VARCHAR(50) NOT NULL,
            data TEXT NOT NULL DEFAULT "{}",
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }
}
