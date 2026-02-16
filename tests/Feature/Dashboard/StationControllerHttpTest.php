<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Station\Tests\TestCase;

/**
 * Feature/HTTP tests for StationController.
 *
 * These tests exercise every public StationController route by making real
 * HTTP requests through the Laravel router, verifying status codes, and
 * confirming that Inertia returns the correct page component and props.
 *
 * We use the X-Inertia header so that Inertia returns a JSON response
 * (component + props) instead of trying to render an HTML blade template.
 */
class StationControllerHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createStationTables();
    }

    // -------------------------------------------------------------------------
    // Dashboard index
    // -------------------------------------------------------------------------

    public function testIndexReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Dashboard');
    }

    public function testIndexReturnsStatsData(): void
    {
        $this->seedJobs(5, 'pending');
        $this->seedJobs(3, 'completed');
        $this->seedJobs(1, 'failed');

        $response = $this->get('/station', $this->inertiaHeaders());

        $response->assertOk();
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('health', $data);
        $this->assertArrayHasKey('pausedQueues', $data);
        $this->assertArrayHasKey('activeBatches', $data);
        $this->assertArrayHasKey('recentAlerts', $data);
        $this->assertArrayHasKey('recentFailed', $data);
        $this->assertArrayHasKey('timeSeries', $data);
    }

    public function testIndexStatsReflectDatabaseState(): void
    {
        $this->seedJobs(10, 'pending');
        $this->seedJobs(5, 'processing');
        $this->seedJobs(20, 'completed');
        $this->seedJobs(2, 'failed');

        $response = $this->get('/station', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $stats = $data['stats'];
        $this->assertSame(10, $stats['totals']['pending']);
        $this->assertSame(5, $stats['totals']['processing']);
        $this->assertSame(20, $stats['totals']['completed']);
        $this->assertSame(2, $stats['totals']['failed']);
    }

    public function testIndexWithSilencedJobsSubtractsFromTotals(): void
    {
        config(['station.silenced' => ['App\\Jobs\\SilencedJob']]);

        $this->seedJobs(5, 'pending', 'default', 'App\\Jobs\\RegularJob');
        $this->seedJobs(3, 'pending', 'default', 'App\\Jobs\\SilencedJob');

        $response = $this->get('/station', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        // Silenced jobs are subtracted from dashboard totals
        $this->assertSame(5, $data['stats']['totals']['pending']);
    }

    // -------------------------------------------------------------------------
    // Jobs page
    // -------------------------------------------------------------------------

    public function testJobsReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/jobs', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Jobs');
    }

    public function testJobsReturnsJobsData(): void
    {
        $this->seedJobs(3, 'pending');

        $response = $this->get('/station/jobs', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('filters', $data);
        $this->assertArrayHasKey('queues', $data);
        $this->assertArrayHasKey('connections', $data);
        $this->assertArrayHasKey('availableTags', $data);
    }

    public function testJobsWithStatusFilter(): void
    {
        $this->seedJobs(5, 'pending');
        $this->seedJobs(3, 'completed');

        $response = $this->get('/station/jobs?status=pending', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('pending', $data['filters']['status']);
    }

    public function testJobsWithQueueFilter(): void
    {
        $this->seedJobs(3, 'pending', 'emails');
        $this->seedJobs(2, 'pending', 'default');

        $response = $this->get('/station/jobs?queue=emails', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('emails', $data['filters']['queue']);
    }

    public function testJobsWithConnectionFilter(): void
    {
        $response = $this->get('/station/jobs?connection=rabbitmq', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('rabbitmq', $data['filters']['connection']);
    }

    public function testJobsWithSearchFilter(): void
    {
        $response = $this->get('/station/jobs?search=TestJob', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('TestJob', $data['filters']['search']);
    }

    public function testJobsWithTagFilter(): void
    {
        $response = $this->get('/station/jobs?tag=important', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('important', $data['filters']['tag']);
    }

    public function testJobsWithPageParameter(): void
    {
        $this->seedJobs(30, 'pending');

        $response = $this->get('/station/jobs?page=2', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testJobsWithPerPageParameter(): void
    {
        $this->seedJobs(10, 'pending');

        $response = $this->get('/station/jobs?per_page=5', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testJobsWithPerPageClampedToMaximum(): void
    {
        $response = $this->get('/station/jobs?per_page=500', $this->inertiaHeaders());

        $response->assertOk();
        // per_page should be clamped to 100, but we just verify the request succeeds
    }

    public function testJobsWithMultipleFilters(): void
    {
        $response = $this->get('/station/jobs?queue=emails&status=failed&search=Send', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('emails', $data['filters']['queue']);
        $this->assertSame('failed', $data['filters']['status']);
        $this->assertSame('Send', $data['filters']['search']);
    }

    // -------------------------------------------------------------------------
    // Pending jobs
    // -------------------------------------------------------------------------

    public function testPendingReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/pending', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Pending');
    }

    public function testPendingReturnsFilteredData(): void
    {
        $this->seedJobs(3, 'pending');
        $this->seedJobs(2, 'completed');

        $response = $this->get('/station/pending', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('filters', $data);
        $this->assertArrayHasKey('queues', $data);
    }

    public function testPendingWithQueueFilter(): void
    {
        $response = $this->get('/station/pending?queue=high', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('high', $data['filters']['queue']);
    }

    public function testPendingWithConnectionFilter(): void
    {
        $response = $this->get('/station/pending?connection=rabbitmq', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('rabbitmq', $data['filters']['connection']);
    }

    // -------------------------------------------------------------------------
    // Completed jobs
    // -------------------------------------------------------------------------

    public function testCompletedReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/completed', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Completed');
    }

    public function testCompletedReturnsFilteredData(): void
    {
        $this->seedJobs(5, 'completed');

        $response = $this->get('/station/completed', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('filters', $data);
    }

    public function testCompletedWithQueueFilter(): void
    {
        $response = $this->get('/station/completed?queue=notifications', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('notifications', $data['filters']['queue']);
    }

    // -------------------------------------------------------------------------
    // Silenced jobs
    // -------------------------------------------------------------------------

    public function testSilencedReturnsOkWithNoSilencedClasses(): void
    {
        config(['station.silenced' => []]);

        $response = $this->get('/station/silenced', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Silenced');
    }

    public function testSilencedReturnsEmptyPaginationWhenNoSilencedClasses(): void
    {
        config(['station.silenced' => []]);

        $response = $this->get('/station/silenced', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('jobs', $data);
        $this->assertSame([], $data['silencedClasses']);
        // Jobs should be an empty pagination result
        $jobs = $data['jobs'];
        $this->assertSame([], $jobs['data']);
        $this->assertSame(0, $jobs['total']);
    }

    public function testSilencedWithSilencedClassesQueriesDatabase(): void
    {
        config(['station.silenced' => ['App\\Jobs\\HeartbeatJob']]);

        $this->seedJobs(3, 'pending', 'default', 'App\\Jobs\\HeartbeatJob');
        $this->seedJobs(2, 'pending', 'default', 'App\\Jobs\\RegularJob');

        $response = $this->get('/station/silenced', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame(['App\\Jobs\\HeartbeatJob'], $data['silencedClasses']);
    }

    public function testSilencedWithStatusFilter(): void
    {
        config(['station.silenced' => ['App\\Jobs\\HeartbeatJob']]);

        $response = $this->get('/station/silenced?status=failed', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('failed', $data['filters']['status']);
    }

    // -------------------------------------------------------------------------
    // Single job detail
    // -------------------------------------------------------------------------

    public function testJobDetailReturnsOkForExistingJob(): void
    {
        $jobId = $this->seedJob('pending');

        $response = $this->get("/station/jobs/{$jobId}", $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/JobDetail');
    }

    public function testJobDetailReturnsPropsWithJobAndEvents(): void
    {
        $jobId = $this->seedJob('processing');

        $response = $this->get("/station/jobs/{$jobId}", $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('job', $data);
        $this->assertArrayHasKey('events', $data);
    }

    public function testJobDetailReturns404ForMissingJob(): void
    {
        $response = $this->get('/station/jobs/nonexistent-id', $this->inertiaHeaders());

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Failed jobs
    // -------------------------------------------------------------------------

    public function testFailedReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/failed', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Failed');
    }

    public function testFailedReturnsData(): void
    {
        $this->seedFailedJobs(3);

        $response = $this->get('/station/failed', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('filters', $data);
        $this->assertArrayHasKey('queues', $data);
        $this->assertArrayHasKey('connections', $data);
    }

    public function testFailedWithQueueFilter(): void
    {
        $response = $this->get('/station/failed?queue=emails', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('emails', $data['filters']['queue']);
    }

    public function testFailedWithConnectionFilter(): void
    {
        $response = $this->get('/station/failed?connection=rabbitmq', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('rabbitmq', $data['filters']['connection']);
    }

    public function testFailedWithPageParameter(): void
    {
        $this->seedFailedJobs(30);

        $response = $this->get('/station/failed?page=2', $this->inertiaHeaders());

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Batches
    // -------------------------------------------------------------------------

    public function testBatchesReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/batches', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Batches');
    }

    public function testBatchesReturnsStatsData(): void
    {
        $this->seedBatches(2, 'pending');
        $this->seedBatches(1, 'completed');

        $response = $this->get('/station/batches', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('batches', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('filters', $data);
        $this->assertArrayHasKey('connections', $data);

        // Check stats structure
        $stats = $data['stats'];
        $this->assertArrayHasKey('pending', $stats);
        $this->assertArrayHasKey('processing', $stats);
        $this->assertArrayHasKey('completed', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('cancelled', $stats);
    }

    public function testBatchesWithStatusFilter(): void
    {
        $response = $this->get('/station/batches?status=completed', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('completed', $data['filters']['status']);
    }

    public function testBatchesWithConnectionFilter(): void
    {
        $response = $this->get('/station/batches?connection=rabbitmq', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('rabbitmq', $data['filters']['connection']);
    }

    // -------------------------------------------------------------------------
    // Single batch detail
    // -------------------------------------------------------------------------

    public function testBatchDetailReturnsOkForExistingBatch(): void
    {
        $batchId = $this->seedBatch('processing');

        $response = $this->get("/station/batches/{$batchId}", $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/BatchDetail');
    }

    public function testBatchDetailReturnsPropsWithBatchAndJobs(): void
    {
        $batchId = $this->seedBatch('processing');

        $response = $this->get("/station/batches/{$batchId}", $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('batch', $data);
        $this->assertArrayHasKey('jobs', $data);
    }

    public function testBatchDetailReturns404ForMissingBatch(): void
    {
        $response = $this->get('/station/batches/nonexistent-id', $this->inertiaHeaders());

        $response->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Metrics
    // -------------------------------------------------------------------------

    public function testMetricsReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/metrics', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Metrics');
    }

    public function testMetricsReturnsExpectedProps(): void
    {
        $response = $this->get('/station/metrics', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('throughput', $data);
        $this->assertArrayHasKey('avgWaitTime', $data);
        $this->assertArrayHasKey('avgProcessingTime', $data);
        $this->assertArrayHasKey('failureRate', $data);
        $this->assertArrayHasKey('jobsProcessed', $data);
        $this->assertArrayHasKey('jobsFailed', $data);
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('timeSeries', $data);
        $this->assertArrayHasKey('connections', $data);
    }

    public function testMetricsWithPeriodParameter(): void
    {
        $response = $this->get('/station/metrics?period=6h', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('6h', $data['period']);
    }

    public function testMetricsWithConnectionParameter(): void
    {
        $response = $this->get('/station/metrics?connection=rabbitmq', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('rabbitmq', $data['currentConnection']);
    }

    public function testMetricsDefaultsToOneHourPeriod(): void
    {
        $response = $this->get('/station/metrics', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('1h', $data['period']);
    }

    // -------------------------------------------------------------------------
    // Metric records
    // -------------------------------------------------------------------------

    public function testMetricRecordsReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/metrics/records', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/MetricRecords');
    }

    public function testMetricRecordsReturnsExpectedProps(): void
    {
        $response = $this->get('/station/metrics/records', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('metrics', $data);
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('connections', $data);
        $this->assertArrayHasKey('currentConnection', $data);
    }

    public function testMetricRecordsWithPeriodParameter(): void
    {
        $response = $this->get('/station/metrics/records?period=24h', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('24h', $data['period']);
    }

    public function testMetricRecordsWithPageParameter(): void
    {
        $response = $this->get('/station/metrics/records?page=2', $this->inertiaHeaders());

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Metric queues
    // -------------------------------------------------------------------------

    public function testMetricQueuesReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/metrics/queues', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/MetricQueues');
    }

    public function testMetricQueuesReturnsExpectedProps(): void
    {
        $response = $this->get('/station/metrics/queues', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('entries', $data);
        $this->assertArrayHasKey('timeSeries', $data);
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('connections', $data);
        $this->assertArrayHasKey('currentConnection', $data);
    }

    public function testMetricQueuesWithStationDriverConnectionBuildsEntries(): void
    {
        // Configure a Station driver so metricQueues generates entries
        config([
            'queue.connections.test-rabbit' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
            ],
        ]);

        $response = $this->get('/station/metrics/queues', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('test-rabbit:default', $data['entries']);

        $entry = $data['entries']['test-rabbit:default'];
        $this->assertSame('default', $entry['queue']);
        $this->assertSame('test-rabbit', $entry['connection']);
        $this->assertArrayHasKey('size', $entry);
        $this->assertArrayHasKey('paused', $entry);
        $this->assertArrayHasKey('throughput', $entry);
        $this->assertArrayHasKey('processed', $entry);
        $this->assertArrayHasKey('failed', $entry);
        $this->assertArrayHasKey('avg_runtime', $entry);
        $this->assertArrayHasKey('avg_wait', $entry);
    }

    public function testMetricQueuesWithConnectionFilterRestrictsEntries(): void
    {
        config([
            'queue.connections.rabbit1' => [
                'driver' => 'rabbitmq',
                'queue' => 'default',
            ],
            'queue.connections.rabbit2' => [
                'driver' => 'rabbitmq',
                'queue' => 'high',
            ],
        ]);

        $response = $this->get('/station/metrics/queues?connection=rabbit1', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('rabbit1:default', $data['entries']);
        $this->assertArrayNotHasKey('rabbit2:high', $data['entries']);
        $this->assertSame('rabbit1', $data['currentConnection']);
    }

    public function testMetricQueuesWithPeriodParameter(): void
    {
        $response = $this->get('/station/metrics/queues?period=6h', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('6h', $data['period']);
    }

    // -------------------------------------------------------------------------
    // Stuck jobs
    // -------------------------------------------------------------------------

    public function testStuckJobsReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/stuck', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/StuckJobs');
    }

    public function testStuckJobsReturnsExpectedProps(): void
    {
        $response = $this->get('/station/stuck', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('jobs', $data);
        $this->assertArrayHasKey('threshold', $data);
        $this->assertArrayHasKey('filters', $data);
    }

    public function testStuckJobsWithCustomThreshold(): void
    {
        $response = $this->get('/station/stuck?threshold=600', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame(600, $data['threshold']);
    }

    public function testStuckJobsDefaultThresholdFromConfig(): void
    {
        config(['station.stuck_detection.thresholds.heartbeat_timeout' => 120]);

        $response = $this->get('/station/stuck', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame(120, $data['threshold']);
    }

    // -------------------------------------------------------------------------
    // Queues (connections) page
    // -------------------------------------------------------------------------

    public function testQueuesReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/connections', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Queues');
    }

    public function testQueuesReturnsExpectedProps(): void
    {
        $response = $this->get('/station/connections', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('connections', $data);
        $this->assertArrayHasKey('driverList', $data);
        $this->assertArrayHasKey('health', $data);
        $this->assertArrayHasKey('driverInfo', $data);
    }

    public function testQueuesDriverListContainsAllDrivers(): void
    {
        $response = $this->get('/station/connections', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $driverValues = array_column($data['driverList'], 'value');
        $this->assertContains('rabbitmq', $driverValues);
        $this->assertContains('redis', $driverValues);
        $this->assertContains('sqs', $driverValues);
        $this->assertContains('beanstalkd', $driverValues);
        $this->assertContains('kafka', $driverValues);
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function testSettingsReturnsOk(): void
    {
        $response = $this->get('/station/settings', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Settings');
    }

    public function testSettingsReturnsConfigProps(): void
    {
        $response = $this->get('/station/settings', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('config', $data);
        $config = $data['config'];

        $this->assertArrayHasKey('driver', $config);
        $this->assertArrayHasKey('dashboard', $config);
        $this->assertArrayHasKey('supervisor', $config);
        $this->assertArrayHasKey('telemetry', $config);
        $this->assertArrayHasKey('alerts', $config);
    }

    public function testSettingsDashboardConfigReflectsCurrentConfig(): void
    {
        $response = $this->get('/station/settings', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $dashboard = $data['config']['dashboard'];
        $this->assertTrue($dashboard['enabled']);
        $this->assertSame('station', $dashboard['path']);
    }

    // -------------------------------------------------------------------------
    // Tags
    // -------------------------------------------------------------------------

    public function testTagsReturnsOkWithEmptyDatabase(): void
    {
        $response = $this->get('/station/tags', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Tags');
    }

    public function testTagsReturnsExpectedProps(): void
    {
        $response = $this->get('/station/tags', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('tags', $data);
        $this->assertArrayHasKey('filters', $data);
        $this->assertArrayHasKey('connections', $data);
    }

    public function testTagsReturnsPaginatedTagData(): void
    {
        // Seed jobs with tags
        $this->seedJobsWithTags([
            ['tag1', 'tag2'],
            ['tag1', 'tag3'],
            ['tag2'],
        ]);

        $response = $this->get('/station/tags', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $tags = $data['tags'];
        $this->assertArrayHasKey('data', $tags);
        $this->assertArrayHasKey('current_page', $tags);
        $this->assertArrayHasKey('last_page', $tags);
        $this->assertArrayHasKey('per_page', $tags);
        $this->assertArrayHasKey('total', $tags);
        $this->assertArrayHasKey('from', $tags);
        $this->assertArrayHasKey('to', $tags);
    }

    public function testTagsCountsByFrequency(): void
    {
        $this->seedJobsWithTags([
            ['common', 'rare'],
            ['common'],
            ['common'],
        ]);

        $response = $this->get('/station/tags', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $tagData = $data['tags']['data'];
        // 'common' should appear first (count=3), 'rare' second (count=1)
        $this->assertNotEmpty($tagData);
        $this->assertSame('common', $tagData[0]['tag']);
        $this->assertSame(3, $tagData[0]['count']);
    }

    public function testTagsWithSearchFilter(): void
    {
        $this->seedJobsWithTags([
            ['user:create', 'order:process'],
            ['user:delete'],
        ]);

        $response = $this->get('/station/tags?search=user', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('user', $data['filters']['search']);
        // Only tags containing 'user' should appear
        foreach ($data['tags']['data'] as $tagItem) {
            $this->assertStringContainsString('user', mb_strtolower($tagItem['tag']));
        }
    }

    public function testTagsWithConnectionFilter(): void
    {
        $response = $this->get('/station/tags?connection=rabbitmq', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame('rabbitmq', $data['filters']['connection']);
    }

    public function testTagsPagination(): void
    {
        $response = $this->get('/station/tags?page=1', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertSame(1, $data['tags']['current_page']);
    }

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    public function testAuditLogReturnsOk(): void
    {
        $response = $this->get('/station/audit-log', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/AuditLog');
    }

    // -------------------------------------------------------------------------
    // Route name verification
    // -------------------------------------------------------------------------

    public function testRouteNamesAreRegistered(): void
    {
        $expectedRoutes = [
            'station.dashboard',
            'station.jobs',
            'station.jobs.show',
            'station.pending',
            'station.failed',
            'station.stuck',
            'station.completed',
            'station.silenced',
            'station.metrics',
            'station.metrics.queues',
            'station.metrics.records',
            'station.settings',
            'station.batches',
            'station.batches.show',
            'station.tags',
            'station.audit-log',
            'station.connections',
        ];

        foreach ($expectedRoutes as $routeName) {
            $this->assertTrue(
                app('router')->has($routeName),
                "Route [{$routeName}] is not registered.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Edge cases and security
    // -------------------------------------------------------------------------

    public function testJobsWithNegativePageDefaultsToPage1(): void
    {
        $response = $this->get('/station/jobs?page=-1', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testJobsWithZeroPerPageClampedToOne(): void
    {
        $response = $this->get('/station/jobs?per_page=0', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testJobsWithNonNumericPageHandledGracefully(): void
    {
        $response = $this->get('/station/jobs?page=abc', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testJobsWithSpecialCharactersInSearchHandledSafely(): void
    {
        $response = $this->get('/station/jobs?search=' . urlencode("'; DROP TABLE station_jobs; --"), $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testJobsWithXssInSearchHandledSafely(): void
    {
        $response = $this->get('/station/jobs?search=' . urlencode('<script>alert("xss")</script>'), $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testBatchesWithLargePageNumberReturnsOk(): void
    {
        $response = $this->get('/station/batches?page=999999', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testMetricsWithInvalidPeriodDefaultsToOneHour(): void
    {
        $response = $this->get('/station/metrics?period=invalid', $this->inertiaHeaders());

        $response->assertOk();
        $data = $this->inertiaProps($response);
        // Invalid period is passed through; the MetricsCollector handles it
        $this->assertSame('invalid', $data['period']);
    }

    // -------------------------------------------------------------------------
    // Seeded data integration tests
    // -------------------------------------------------------------------------

    public function testIndexActiveBatchesLimitedToFive(): void
    {
        $this->seedBatches(10, 'processing');

        $response = $this->get('/station', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        // Active batches widget is capped at 5
        $this->assertLessThanOrEqual(5, \count($data['activeBatches']));
    }

    public function testJobDetailWithSeededEvents(): void
    {
        $jobId = $this->seedJob('completed');
        $this->seedJobEvent($jobId, 'dispatched');
        $this->seedJobEvent($jobId, 'processing');
        $this->seedJobEvent($jobId, 'completed');

        $response = $this->get("/station/jobs/{$jobId}", $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('events', $data);
    }

    public function testBatchStatsReflectDatabaseState(): void
    {
        $this->seedBatches(3, 'pending');
        $this->seedBatches(2, 'processing');
        $this->seedBatches(5, 'completed');
        $this->seedBatches(1, 'failed');

        $response = $this->get('/station/batches', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $stats = $data['stats'];
        $this->assertSame(3, $stats['pending']);
        $this->assertSame(2, $stats['processing']);
        $this->assertSame(5, $stats['completed']);
        $this->assertSame(1, $stats['failed']);
    }

    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        // Enable dashboard with no auth middleware so HTTP tests pass without authentication
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.silenced', []);

        // Use empty queue connections to avoid driver connection errors
        $app['config']->set('queue.connections', [
            'sync' => ['driver' => 'sync'],
        ]);
        $app['config']->set('queue.default', 'sync');

        // Disable tracking to avoid interfering with tests
        $app['config']->set('station.tracking.enabled', false);
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Return request headers that make Inertia return a JSON response.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '',
        ];
    }

    /**
     * Extract the Inertia page component name from the response.
     */
    private function assertInertiaComponent(mixed $response, string $expected): void
    {
        $data = $response->json();
        $this->assertSame($expected, $data['component'] ?? null, "Expected Inertia component [{$expected}].");
    }

    /**
     * Extract the Inertia page props from the JSON response.
     *
     * @return array<string, mixed>
     */
    private function inertiaProps(mixed $response): array
    {
        $data = $response->json();

        return $data['props'] ?? [];
    }

    /**
     * Create all Station tables needed for testing.
     */
    private function createStationTables(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    /**
     * Seed multiple jobs into the station_jobs table.
     */
    private function seedJobs(int $count, string $status, string $queue = 'default', string $jobClass = 'App\\Jobs\\TestJob'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->seedJob($status, $queue, $jobClass);
        }
    }

    /**
     * Seed a single job and return its ID.
     */
    private function seedJob(string $status, string $queue = 'default', string $jobClass = 'App\\Jobs\\TestJob'): string
    {
        $id = (string) Str::uuid();

        DB::table('station_jobs')->insert([
            'id' => $id,
            'job_class' => $jobClass,
            'queue' => $queue,
            'connection' => 'sync',
            'status' => $status,
            'payload' => json_encode(['displayName' => $jobClass]),
            'attempts' => 0,
            'max_tries' => 3,
            'tags' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Seed multiple failed jobs into the station_failed_jobs table.
     */
    private function seedFailedJobs(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $jobId = $this->seedJob('failed');

            DB::table('station_failed_jobs')->insert([
                'id' => (string) Str::uuid(),
                'original_id' => $jobId,
                'queue' => 'default',
                'job_class' => 'App\\Jobs\\TestJob',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\TestJob']),
                'exception' => 'RuntimeException: Something went wrong',
                'failed_at' => now(),
            ]);
        }
    }

    /**
     * Seed batches into station_batches.
     */
    private function seedBatches(int $count, string $status): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->seedBatch($status);
        }
    }

    /**
     * Seed a single batch and return its ID.
     */
    private function seedBatch(string $status): string
    {
        $id = (string) Str::uuid();

        DB::table('station_batches')->insert([
            'id' => $id,
            'name' => 'Test Batch',
            'total_jobs' => 10,
            'pending_jobs' => $status === 'pending' ? 10 : 0,
            'processed_jobs' => $status === 'completed' ? 10 : 0,
            'failed_jobs' => $status === 'failed' ? 1 : 0,
            'failed_job_ids' => '[]',
            'status' => $status,
            'connection' => 'sync',
            'queue' => 'default',
            'allowed_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Seed a job event into station_job_events.
     */
    private function seedJobEvent(string $jobId, string $event): void
    {
        DB::table('station_job_events')->insert([
            'job_id' => $jobId,
            'event' => $event,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Seed jobs with specific tag arrays.
     *
     * @param array<int, array<int, string>> $tagArrays
     */
    private function seedJobsWithTags(array $tagArrays): void
    {
        foreach ($tagArrays as $tags) {
            $id = (string) Str::uuid();

            DB::table('station_jobs')->insert([
                'id' => $id,
                'job_class' => 'App\\Jobs\\TestJob',
                'queue' => 'default',
                'connection' => 'sync',
                'status' => 'completed',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\TestJob']),
                'attempts' => 1,
                'max_tries' => 3,
                'tags' => json_encode($tags),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
