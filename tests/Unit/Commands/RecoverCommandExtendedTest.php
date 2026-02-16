<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Commands\RecoverCommand;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Recovery\JobResumer;
use Station\Recovery\StuckJobDetector;
use Station\StationServiceProvider;
use Station\Workflows\WorkflowManager;

/**
 * Extended tests for RecoverCommand covering:
 * - Exception thrown during resume (partial failure with exit code FAILURE)
 * - Workflow recovery (--workflows flag) with real WorkflowManager
 * - Workflow recovery dry run
 * - Workflow recovery when DB table doesn't exist (exception handling)
 */
class RecoverCommandExtendedTest extends TestCase
{
    private MockInterface&StuckJobDetectorInterface $detector;

    private MockInterface&JobResumerInterface $resumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = Mockery::mock(StuckJobDetectorInterface::class);
        $this->resumer = Mockery::mock(JobResumerInterface::class);

        $this->app->instance(StuckJobDetectorInterface::class, $this->detector);
        $this->app->instance(JobResumerInterface::class, $this->resumer);
        $this->app->instance(StuckJobDetector::class, $this->detector);
        $this->app->instance(JobResumer::class, $this->resumer);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRecoverWithExceptionDuringResumeReportsFailure(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
            $this->createJob('job-2', 'App\\Jobs\\FailingJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        // First job succeeds, second throws
        $this->resumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->andReturn(true);

        $this->resumer->shouldReceive('resume')
            ->with('job-2', 'graceful')
            ->andThrow(new RuntimeException('Resume failed for job-2'));

        // With 1 failure and no --workflows flag, should return FAILURE
        $this->artisan(RecoverCommand::class)
            ->expectsConfirmation('Do you want to recover these jobs?', 'yes')
            ->expectsOutputToContain('1 recovered, 1 failed')
            ->assertExitCode(1);
    }

    public function testRecoverWithAllJobsFailingReportsAllFailed(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $this->resumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->andThrow(new RuntimeException('Total failure'));

        $this->artisan(RecoverCommand::class)
            ->expectsConfirmation('Do you want to recover these jobs?', 'yes')
            ->expectsOutputToContain('0 recovered, 1 failed')
            ->assertExitCode(1);
    }

    public function testRecoverWithWorkflowsFlagAndExceptionReturnsSuccess(): void
    {
        // When --workflows is set but recovery of jobs has failures,
        // the exit code is still SUCCESS (the --workflows flag changes behavior)
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $this->resumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->andThrow(new RuntimeException('Resume failed'));

        // Bind a real WorkflowManager that will fail on DB query (caught by try-catch)
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();
        $workflowManager = new WorkflowManager($events, ['table' => 'station_workflows']);
        $this->app->instance(WorkflowManager::class, $workflowManager);

        $this->artisan(RecoverCommand::class, ['--workflows' => true])
            ->expectsConfirmation('Do you want to recover these jobs?', 'yes')
            ->assertExitCode(0);
    }

    public function testRecoverWorkflowsDryRunShowsMessage(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn(collect());

        $this->artisan(RecoverCommand::class, ['--workflows' => true, '--dry-run' => true])
            ->expectsOutputToContain('Dry run - workflow recovery would run')
            ->assertExitCode(0);
    }

    public function testRecoverWorkflowsWithDbErrorShowsErrorMessage(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn(collect());

        // The real WorkflowManager will try to query the DB table which doesn't exist
        // in the test SQLite environment. The RecoverCommand catches the exception.
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();
        $workflowManager = new WorkflowManager($events, ['table' => 'station_workflows']);
        $this->app->instance(WorkflowManager::class, $workflowManager);

        $this->artisan(RecoverCommand::class, ['--workflows' => true])
            ->expectsOutputToContain('Workflow recovery failed')
            ->assertExitCode(0);
    }

    public function testRecoverWorkflowsDryRunWithCustomThreshold(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->withArgs(static fn($options) => $options['threshold'] === 600)
            ->andReturn(collect());

        $this->artisan(RecoverCommand::class, [
            '--workflows' => true,
            '--dry-run' => true,
            '--threshold' => 600,
        ])
            ->expectsOutputToContain('Dry run - workflow recovery would run with threshold: 600s')
            ->assertExitCode(0);
    }

    public function testRecoverCommandHasWorkflowsOption(): void
    {
        $command = new RecoverCommand(
            Mockery::mock(StuckJobDetectorInterface::class),
            Mockery::mock(JobResumerInterface::class),
        );

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('workflows'));
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    private function createJob(string $id, string $jobClass, string $queue, int $attempts): Job
    {
        return new Job(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: serialize(['data' => 'test']),
            status: JobStatus::Processing->value,
            attempts: $attempts,
            startedAt: CarbonImmutable::now()->subMinutes(5),
        );
    }
}
