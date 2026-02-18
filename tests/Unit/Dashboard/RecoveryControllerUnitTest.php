<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Dashboard;

use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Dashboard\Http\Controllers\RecoveryController;
use Station\StationServiceProvider;

/**
 * Unit tests for RecoveryController covering bulkRecoverJobs
 * and recoverJob failure path. These test the controller methods
 * directly without routing to avoid route shadowing issues
 * where {id} catches 'bulk' in /stuck/{id}/recover vs /stuck/bulk/recover.
 */
class RecoveryControllerUnitTest extends TestCase
{
    private StuckJobDetectorInterface&MockInterface $stuckJobDetector;

    private JobResumerInterface&MockInterface $jobResumer;

    private RecoveryController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stuckJobDetector = Mockery::mock(StuckJobDetectorInterface::class);
        $this->jobResumer = Mockery::mock(JobResumerInterface::class);

        $this->controller = new RecoveryController(
            stuckJobDetector: $this->stuckJobDetector,
            jobResumer: $this->jobResumer,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testBulkRecoverJobsWithValidIdsReturnsResults(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->once()
            ->andReturn(true);

        $this->jobResumer->shouldReceive('resume')
            ->with('job-2', 'graceful')
            ->once()
            ->andReturn(true);

        $request = Request::create('/stuck/bulk/recover', 'POST', [
            'ids' => ['job-1', 'job-2'],
        ]);
        $request->setLaravelSession($this->app['session.store']);

        $response = $this->controller->bulkRecoverJobs($request);

        $data = json_decode($response->getContent(), true);

        $this->assertSame(2, $data['processed']);
        $this->assertSame(0, $data['failed']);
        $this->assertTrue($data['success']);
    }

    public function testBulkRecoverJobsWithCustomStrategy(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'checkpoint')
            ->once()
            ->andReturn(true);

        $request = Request::create('/stuck/bulk/recover', 'POST', [
            'ids' => ['job-1'],
            'strategy' => 'checkpoint',
        ]);
        $request->setLaravelSession($this->app['session.store']);

        $response = $this->controller->bulkRecoverJobs($request);

        $data = json_decode($response->getContent(), true);

        $this->assertSame(1, $data['processed']);
        $this->assertTrue($data['success']);
    }

    public function testBulkRecoverJobsWithPartialFailure(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->once()
            ->andReturn(true);

        $this->jobResumer->shouldReceive('resume')
            ->with('job-2', 'graceful')
            ->once()
            ->andThrow(new RuntimeException('Resume failed'));

        $request = Request::create('/stuck/bulk/recover', 'POST', [
            'ids' => ['job-1', 'job-2'],
        ]);
        $request->setLaravelSession($this->app['session.store']);

        $response = $this->controller->bulkRecoverJobs($request);

        $data = json_decode($response->getContent(), true);

        $this->assertSame(1, $data['processed']);
        $this->assertSame(1, $data['failed']);
        $this->assertFalse($data['success']);
    }

    public function testRecoverJobWithFailedResumerReturnsFalseSuccess(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-fail', 'graceful')
            ->once()
            ->andReturn(false);

        $request = Request::create('/stuck/job-fail/recover', 'POST');

        $response = $this->controller->recoverJob('job-fail', $request);

        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['success']);
        $this->assertSame('Unable to recover job', $data['message']);
    }

    public function testRecoverJobWithExceptionReturnsErrorResponse(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-error', 'graceful')
            ->once()
            ->andThrow(new RuntimeException('Connection lost'));

        $request = Request::create('/stuck/job-error/recover', 'POST');

        $response = $this->controller->recoverJob('job-error', $request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRecoverJobWithCustomStrategy(): void
    {
        $this->jobResumer->shouldReceive('resume')
            ->with('job-restart', 'restart')
            ->once()
            ->andReturn(true);

        $request = Request::create('/stuck/job-restart/recover', 'POST', [
            'strategy' => 'restart',
        ]);

        $response = $this->controller->recoverJob('job-restart', $request);

        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertSame('Job recovery initiated', $data['message']);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
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
    }
}
