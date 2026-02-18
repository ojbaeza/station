<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Core\Job;
use Station\Dashboard\Http\Controllers\RecoveryController;
use Station\StationServiceProvider;

/**
 * Extended feature tests for RecoveryController covering additional
 * edge cases and recovery scenarios.
 */
class RecoveryControllerExtendedTest extends TestCase
{
    private JobResumerInterface&MockInterface $jobResumer;

    private StuckJobDetectorInterface&MockInterface $stuckJobDetector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMockDependencies();
        $this->bindController();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- Recover stuck with various strategies ----

    public function testRecoverStuckWithCheckpointStrategy(): void
    {
        $this->jobResumer->shouldReceive('recoverAll')
            ->with('checkpoint')
            ->once()
            ->andReturn(2);

        $this->post('/station/api/recover', ['strategy' => 'checkpoint'])
            ->assertOk()
            ->assertJson(['count' => 2, 'message' => 'Recovered 2 stuck jobs']);
    }

    public function testRecoverStuckReturnsZeroWhenNoStuckJobs(): void
    {
        $this->jobResumer->shouldReceive('recoverAll')
            ->with('graceful')
            ->once()
            ->andReturn(0);

        $this->post('/station/api/recover')
            ->assertOk()
            ->assertJson(['count' => 0, 'message' => 'Recovered 0 stuck jobs']);
    }

    // ---- Recover single job edge cases ----

    public function testRecoverJobDefaultsToGracefulStrategy(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-default', 'graceful')
            ->once()
            ->andReturn(true);

        $this->post('/station/api/stuck/job-default/recover')
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Job recovery initiated']);
    }

    // ---- Stuck jobs with data ----

    public function testStuckJobsReturnsJobData(): void
    {
        $job1 = new Job(
            id: 'stuck-job-1',
            queue: 'high',
            jobClass: 'App\\Jobs\\ImportJob',
            payload: serialize(['data' => 'test']),
            status: 'processing',
        );

        $job2 = new Job(
            id: 'stuck-job-2',
            queue: 'default',
            jobClass: 'App\\Jobs\\EmailJob',
            payload: serialize(['data' => 'test2']),
            status: 'processing',
        );

        $this->stuckJobDetector->shouldReceive('detect')
            ->with([])
            ->once()
            ->andReturn(new Collection([$job1, $job2]));

        $this->get('/station/api/stuck')
            ->assertOk()
            ->assertJson(['total' => 2]);
    }

    public function testStuckJobsWithCustomThreshold(): void
    {
        $this->stuckJobDetector->shouldReceive('detect')
            ->with(['threshold' => 1200])
            ->once()
            ->andReturn(new Collection([]));

        $this->get('/station/api/stuck?threshold=1200')
            ->assertOk()
            ->assertJson(['total' => 0]);
    }

    // ---- Error handling ----

    public function testRecoverStuckHandlesExceptionFromResumer(): void
    {
        $this->jobResumer->shouldReceive('recoverAll')
            ->with('graceful')
            ->andThrow(new RuntimeException('Database connection lost'));

        $this->post('/station/api/recover')
            ->assertStatus(400);
    }

    public function testStuckJobsDetectionErrorReturnsErrorResponse(): void
    {
        $this->stuckJobDetector->shouldReceive('detect')
            ->andThrow(new RuntimeException('Detection service unavailable'));

        $this->get('/station/api/stuck')
            ->assertStatus(400);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.api.auth', 'none');
        $app['config']->set('station.api.middleware', []);
        $app['config']->set('station.default', 'redis');
        $app['config']->set('queue.connections', []);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    private function createMockDependencies(): void
    {
        $this->jobResumer = Mockery::mock(JobResumerInterface::class);
        $this->jobResumer->shouldReceive('recoverAll')->byDefault()->andReturn(0);
        $this->jobResumer->shouldReceive('resume')->byDefault()->andReturn(true);

        $this->stuckJobDetector = Mockery::mock(StuckJobDetectorInterface::class);
        $this->stuckJobDetector->shouldReceive('detect')->byDefault()->andReturn(new Collection([]));
    }

    private function bindController(): void
    {
        $controller = new RecoveryController(
            stuckJobDetector: $this->stuckJobDetector,
            jobResumer: $this->jobResumer,
        );

        $this->app->instance(RecoveryController::class, $controller);
    }
}
