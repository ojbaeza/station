<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Carbon\CarbonImmutable;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Commands\RecoverCommand;
use Station\Contracts\JobResumerInterface;
use Station\Contracts\StuckJobDetectorInterface;
use Station\Core\Job;
use Station\Enums\JobStatus;
use Station\Recovery\JobResumer;
use Station\Recovery\StuckJobDetector;
use Station\StationServiceProvider;

class RecoverCommandTest extends TestCase
{
    private MockInterface&StuckJobDetectorInterface $detector;

    private MockInterface&JobResumerInterface $resumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = Mockery::mock(StuckJobDetectorInterface::class);
        $this->resumer = Mockery::mock(JobResumerInterface::class);

        // Bind to both concrete class and interface to override service provider aliases
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

    public function testRecoverWithDryRunDoesNotRecover(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        // Resumer should never be called in dry-run mode
        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class, ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function testRecoverWithDeclinedConfirmation(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        // Resumer should never be called if confirmation is declined
        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class)
            ->expectsConfirmation('Do you want to recover these jobs?', 'no')
            ->assertExitCode(0);
    }

    public function testRecoverWithDryRunShowsFoundCount(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-123', 'App\\Jobs\\ProcessPayment', 'payments', 3),
            $this->createJob('job-456', 'App\\Jobs\\SendEmail', 'emails', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $this->resumer->shouldNotReceive('resume');

        // Verify the command reports the correct count of stuck jobs
        $this->artisan(RecoverCommand::class, ['--dry-run' => true])
            ->expectsOutputToContain('Found 2 stuck job(s)')
            ->assertExitCode(0);
    }

    public function testRecoverWithDryRunAndQueueFilter(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'high-priority', 2),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->withArgs(static fn($options) => $options['queue'] === 'high-priority')
            ->andReturn($stuckJobs);

        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class, ['--queue' => 'high-priority', '--dry-run' => true])
            ->assertExitCode(0);
    }

    public function testRecoverWithDryRunAndCustomThreshold(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->withArgs(static fn($options) => $options['threshold'] === 600)
            ->andReturn($stuckJobs);

        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class, ['--threshold' => 600, '--dry-run' => true])
            ->assertExitCode(0);
    }

    public function testRecoverWithDryRunAndCustomStrategy(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class, ['--strategy' => 'restart', '--dry-run' => true])
            ->assertExitCode(0);
    }

    public function testRecoverCommandHasCorrectSignature(): void
    {
        $command = new RecoverCommand(
            Mockery::mock(StuckJobDetectorInterface::class),
            Mockery::mock(JobResumerInterface::class),
        );

        $this->assertSame('station:recover', $command->getName());
        $this->assertStringContainsString('Detect and recover stuck jobs', $command->getDescription());
    }

    public function testRecoverCommandHasQueueOption(): void
    {
        $command = new RecoverCommand(
            Mockery::mock(StuckJobDetectorInterface::class),
            Mockery::mock(JobResumerInterface::class),
        );

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('queue'));
        $this->assertNull($definition->getOption('queue')->getDefault());
    }

    public function testRecoverCommandHasStrategyOption(): void
    {
        $command = new RecoverCommand(
            Mockery::mock(StuckJobDetectorInterface::class),
            Mockery::mock(JobResumerInterface::class),
        );

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('strategy'));
        $this->assertSame('graceful', $definition->getOption('strategy')->getDefault());
    }

    public function testRecoverCommandHasDryRunOption(): void
    {
        $command = new RecoverCommand(
            Mockery::mock(StuckJobDetectorInterface::class),
            Mockery::mock(JobResumerInterface::class),
        );

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('dry-run'));
    }

    public function testRecoverCommandHasThresholdOption(): void
    {
        $command = new RecoverCommand(
            Mockery::mock(StuckJobDetectorInterface::class),
            Mockery::mock(JobResumerInterface::class),
        );

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('threshold'));
        $this->assertSame('300', $definition->getOption('threshold')->getDefault());
    }

    public function testRecoverWithConfirmationExecutesRecovery(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
            $this->createJob('job-2', 'App\\Jobs\\ProcessOrder', 'orders', 2),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $this->resumer->shouldReceive('resume')
            ->once()
            ->with('job-1', 'graceful')
            ->andReturn(true);

        $this->resumer->shouldReceive('resume')
            ->once()
            ->with('job-2', 'graceful')
            ->andReturn(true);

        $this->artisan(RecoverCommand::class)
            ->expectsConfirmation('Do you want to recover these jobs?', 'yes')
            ->assertExitCode(0);
    }

    public function testRecoverWithCustomStrategyExecutesRecovery(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $this->resumer->shouldReceive('resume')
            ->once()
            ->with('job-1', 'restart')
            ->andReturn(true);

        $this->artisan(RecoverCommand::class, ['--strategy' => 'restart'])
            ->expectsConfirmation('Do you want to recover these jobs?', 'yes')
            ->assertExitCode(0);
    }

    public function testRecoverWithPartialFailureReportsResults(): void
    {
        $stuckJobs = collect([
            $this->createJob('job-1', 'App\\Jobs\\TestJob', 'default', 1),
            $this->createJob('job-2', 'App\\Jobs\\FailingJob', 'default', 1),
        ]);

        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn($stuckJobs);

        $this->resumer->shouldReceive('resume')
            ->with('job-1', 'graceful')
            ->andReturn(true);

        $this->resumer->shouldReceive('resume')
            ->with('job-2', 'graceful')
            ->andReturn(false);

        $this->artisan(RecoverCommand::class)
            ->expectsConfirmation('Do you want to recover these jobs?', 'yes')
            ->assertExitCode(0);
    }

    public function testRecoverWithNoStuckJobsReportsEmpty(): void
    {
        $this->detector->shouldReceive('detect')
            ->once()
            ->andReturn(collect());

        $this->resumer->shouldNotReceive('resume');

        $this->artisan(RecoverCommand::class)
            ->expectsOutputToContain('No stuck jobs found')
            ->assertExitCode(0);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
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
