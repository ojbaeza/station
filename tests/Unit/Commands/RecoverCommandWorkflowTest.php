<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Station\Commands\RecoverCommand;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Enums\WorkflowStatus;
use Station\Enums\WorkflowStepStatus;
use Station\Tests\TestCase;
use Station\Workflows\WorkflowManager;
use stdClass;

/**
 * Tests for RecoverCommand covering workflow recovery paths:
 * - Workflow recovery with actual recovered workflows (success path)
 * - Workflow recovery with no stuck steps
 * - Workflow recovery with custom threshold
 * - Stuck jobs with --dry-run flag
 * - Recovery with custom strategy and queue options
 *
 * WorkflowManager is final so cannot be mocked. Uses real instances with DB tables.
 */
class RecoverCommandWorkflowTest extends TestCase
{
    private MockInterface&StuckJobDetectorInterface $detector;

    private MockInterface&JobResumerInterface $resumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        $this->detector = Mockery::mock(StuckJobDetectorInterface::class);
        $this->resumer = Mockery::mock(JobResumerInterface::class);

        $this->app->instance(StuckJobDetectorInterface::class, $this->detector);
        $this->app->instance(JobResumerInterface::class, $this->resumer);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRecoverWorkflowsWithNoStuckSteps(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn(collect());

        // Real WorkflowManager with empty DB table - no stuck workflows found
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        $workflowManager = new WorkflowManager($events, [
            'table' => 'station_workflows',
        ]);

        $this->app->instance(WorkflowManager::class, $workflowManager);

        $this->artisan(RecoverCommand::class, ['--workflows' => true])
            ->expectsOutputToContain('No stuck workflow steps found')
            ->assertExitCode(0);
    }

    public function testRecoverWorkflowsWithCustomThreshold(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->withArgs(static fn($options) => $options['threshold'] === 600)
            ->andReturn(collect());

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        $workflowManager = new WorkflowManager($events, [
            'table' => 'station_workflows',
        ]);

        $this->app->instance(WorkflowManager::class, $workflowManager);

        $this->artisan(RecoverCommand::class, [
            '--workflows' => true,
            '--threshold' => 600,
        ])
            ->expectsOutputToContain('No stuck workflow steps found')
            ->assertExitCode(0);
    }

    public function testRecoverWorkflowsWithStuckRunningWorkflow(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn(collect());

        // Insert a running workflow that is stuck (updated_at in the past)
        $workflowId = Str::uuid()->toString();
        DB::table('station_workflows')->insert([
            'id' => $workflowId,
            'definition_id' => 'test-def-id',
            'definition_name' => 'test-workflow',
            'status' => WorkflowStatus::Running->value,
            'current_step' => 'step-1',
            'step_statuses' => json_encode(['step-1' => WorkflowStepStatus::Running->value]),
            'context' => json_encode([]),
            'results' => json_encode([]),
            'input' => json_encode([]),
            'started_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(10),
        ]);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        $workflowManager = new WorkflowManager($events, [
            'table' => 'station_workflows',
        ]);

        // Register a definition so recovery can find it
        $definition = $workflowManager->define('test-workflow');
        $definition->addStep('step-1', stdClass::class);

        $this->app->instance(WorkflowManager::class, $workflowManager);

        // The workflow has a running step that will be timed out by recovery
        $this->artisan(RecoverCommand::class, ['--workflows' => true, '--threshold' => 300])
            ->expectsOutputToContain('Recovered')
            ->assertExitCode(0);
    }

    public function testRecoverWorkflowsDryRunSkipsWorkflowRecovery(): void
    {
        $stuckJobs = collect([
            new Job(
                id: 'job-dry',
                queue: 'default',
                jobClass: 'App\\Jobs\\SlowJob',
                payload: serialize(['data' => 'test']),
                status: JobStatus::Processing->value,
                attempts: 2,
                startedAt: CarbonImmutable::now()->subMinutes(10),
            ),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        // resumer should NOT be called in dry-run mode
        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class, ['--dry-run' => true, '--workflows' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);
    }

    public function testRecoverDryRunShowsTableButTakesNoAction(): void
    {
        $stuckJobs = collect([
            new Job(
                id: 'job-dry',
                queue: 'default',
                jobClass: 'App\\Jobs\\SlowJob',
                payload: serialize(['data' => 'test']),
                status: JobStatus::Processing->value,
                attempts: 2,
                startedAt: CarbonImmutable::now()->subMinutes(10),
            ),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        // resumer should NOT be called in dry-run mode
        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class, ['--dry-run' => true])
            ->expectsOutputToContain('Dry run - no action taken')
            ->assertExitCode(0);
    }

    public function testRecoverWithCustomQueueAndStrategy(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->withArgs(static fn($options) => $options['queue'] === 'emails' && $options['threshold'] === 300)
            ->andReturn(collect());

        $this->artisan(RecoverCommand::class, [
            '--queue' => 'emails',
            '--strategy' => 'checkpoint',
        ])
            ->expectsOutputToContain('No stuck jobs found')
            ->assertExitCode(0);
    }

    public function testRecoverNoConfirmationSkipsRecovery(): void
    {
        $stuckJobs = collect([
            new Job(
                id: 'job-skip',
                queue: 'default',
                jobClass: 'App\\Jobs\\TestJob',
                payload: serialize(['data' => 'test']),
                status: JobStatus::Processing->value,
                attempts: 1,
                startedAt: CarbonImmutable::now()->subMinutes(5),
            ),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        // resumer should NOT be called when user declines
        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class)
            ->expectsConfirmation('Do you want to recover these jobs?', 'no')
            ->assertExitCode(0);
    }

    public function testRecoverWorkflowsHandlesExceptionGracefully(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn(collect());

        // If WorkflowManager throws during recovery, the command catches it gracefully.
        // Use a non-existent table name to trigger a QueryException.
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        $workflowManager = new WorkflowManager($events, [
            'table' => 'nonexistent_workflows_table',
        ]);

        $this->app->instance(WorkflowManager::class, $workflowManager);

        $this->artisan(RecoverCommand::class, ['--workflows' => true])
            ->expectsOutputToContain('Workflow recovery failed')
            ->assertExitCode(0);
    }

    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('station.dashboard.enabled', false);
    }
}
