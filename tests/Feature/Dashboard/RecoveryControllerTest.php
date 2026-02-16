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

class RecoveryControllerTest extends TestCase
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

    // ---- Stuck Jobs ----

    public function testStuckJobsEndpointReturnsJobs(): void
    {
        $this->get('/station/api/stuck')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function testStuckJobsWithThresholdPassesOption(): void
    {
        $this->stuckJobDetector->shouldReceive('detect')
            ->with(['threshold' => 600])
            ->andReturn(new Collection([]));

        $this->get('/station/api/stuck?threshold=600')
            ->assertOk()
            ->assertJson(['data' => [], 'total' => 0]);
    }

    public function testStuckJobsWithoutThresholdUsesDefault(): void
    {
        $this->stuckJobDetector->shouldReceive('detect')
            ->with([])
            ->andReturn(new Collection([]));

        $this->get('/station/api/stuck')
            ->assertOk()
            ->assertJson(['data' => [], 'total' => 0]);
    }

    public function testStuckJobsReturnsDetectedJobs(): void
    {
        $job = new Job(
            id: 'stuck-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\TestJob',
            payload: serialize(['data' => 'test']),
            status: 'processing',
        );

        $this->stuckJobDetector->shouldReceive('detect')
            ->with([])
            ->andReturn(new Collection([$job]));

        $this->get('/station/api/stuck')
            ->assertOk()
            ->assertJson(['total' => 1]);
    }

    public function testStuckJobsHandlesException(): void
    {
        $this->stuckJobDetector->shouldReceive('detect')
            ->andThrow(new RuntimeException('Detection error'));

        $this->get('/station/api/stuck')
            ->assertStatus(400);
    }

    // ---- Recover single job ----

    public function testRecoverSingleJobReturnsSuccess(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->andReturn(true);

        $this->post('/station/api/stuck/job-1/recover', ['strategy' => 'graceful'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function testRecoverSingleJobWithRestartStrategy(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'restart')
            ->andReturn(true);

        $this->post('/station/api/stuck/job-1/recover', ['strategy' => 'restart'])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Job recovery initiated']);
    }

    public function testRecoverSingleJobReturnsFalseWhenUnableToRecover(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->andReturn(false);

        $this->post('/station/api/stuck/job-1/recover')
            ->assertOk()
            ->assertJson(['success' => false, 'message' => 'Unable to recover job']);
    }

    public function testRecoverSingleJobHandlesException(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->andThrow(new RuntimeException('Recovery error'));

        $this->post('/station/api/stuck/job-1/recover')
            ->assertStatus(400);
    }

    public function testRecoverSingleJobWithCheckpointStrategy(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'checkpoint')
            ->andReturn(true);

        $this->post('/station/api/stuck/job-1/recover', ['strategy' => 'checkpoint'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ---- Recover stuck (all) ----

    public function testRecoverStuckReturnsRecoveredCount(): void
    {
        $this->jobResumer->shouldReceive('recoverAll')
            ->with('graceful')
            ->andReturn(3);

        $this->post('/station/api/recover', ['strategy' => 'graceful'])
            ->assertOk()
            ->assertJson(['count' => 3]);
    }

    public function testRecoverStuckWithDefaultStrategy(): void
    {
        $this->jobResumer->shouldReceive('recoverAll')
            ->with('graceful')
            ->andReturn(0);

        $this->post('/station/api/recover')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function testRecoverStuckWithRestartStrategy(): void
    {
        $this->jobResumer->shouldReceive('recoverAll')
            ->with('restart')
            ->andReturn(5);

        $this->post('/station/api/recover', ['strategy' => 'restart'])
            ->assertOk()
            ->assertJson(['count' => 5, 'message' => 'Recovered 5 stuck jobs']);
    }

    public function testRecoverStuckHandlesExceptionGracefully(): void
    {
        $this->jobResumer->shouldReceive('recoverAll')
            ->andThrow(new RuntimeException('Recovery failed'));

        $this->post('/station/api/recover')
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
