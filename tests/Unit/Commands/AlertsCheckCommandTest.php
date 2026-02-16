<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use Station\Alerts\AlertManager;
use Station\Alerts\Evaluators\AlertEvaluatorInterface;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\DTOs\AlertEvaluation;
use Station\DTOs\AlertRule;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;
use Station\Tests\TestCase;

class AlertsCheckCommandTest extends TestCase
{
    private MockInterface&AlertRepositoryInterface $repository;

    private MockInterface&AlertChannelRepositoryInterface $channelRepository;

    private MockInterface&Dispatcher $eventDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(AlertRepositoryInterface::class);
        $this->channelRepository = Mockery::mock(AlertChannelRepositoryInterface::class);
        $this->eventDispatcher = Mockery::mock(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCommandOutputsNoAlertsTriggeredWhenEvaluateReturnsEmpty(): void
    {
        $manager = new AlertManager(
            $this->repository,
            $this->channelRepository,
            $this->eventDispatcher,
            ['enabled' => true],
        );

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([]);

        $this->app->instance(AlertManager::class, $manager);

        $this->artisan('station:alerts:check')
            ->expectsOutput('No alerts triggered.')
            ->assertSuccessful();
    }

    public function testCommandOutputsAlertInfoWhenAlertsAreTriggered(): void
    {
        $manager = new AlertManager(
            $this->repository,
            $this->channelRepository,
            $this->eventDispatcher,
            ['enabled' => true],
        );

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $manager->registerEvaluator(AlertType::HighFailureRate, $evaluator);

        $rule = new AlertRule(
            id: 'rule-1',
            name: 'High Failure Rate',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $evaluator
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(new AlertEvaluation(
                message: 'Failure rate is 15.0%',
                severity: AlertSeverity::Warning,
                context: [],
            ));

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([$rule]);

        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->andReturn(1);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once();

        $this->app->instance(AlertManager::class, $manager);

        $this->artisan('station:alerts:check')
            ->expectsOutput('[Warning] High Failure Rate: Failure rate is 15.0%')
            ->expectsOutput('1 alert(s) triggered.')
            ->assertSuccessful();
    }

    public function testCommandWithSeedOptionCallsSeedFromConfigAndOutputsSeededCount(): void
    {
        $manager = new AlertManager(
            $this->repository,
            $this->channelRepository,
            $this->eventDispatcher,
            [
                'enabled' => true,
                'channels' => [],
                'rules' => [],
            ],
        );

        // seedFromConfig with empty config returns 0
        // getEnabledRules for evaluate returns empty
        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([]);

        $this->app->instance(AlertManager::class, $manager);

        $this->artisan('station:alerts:check', ['--seed' => true])
            ->expectsOutput('Seeded channels and 0 alert rule(s) from config.')
            ->expectsOutput('No alerts triggered.')
            ->assertSuccessful();
    }

    public function testCommandAlwaysReturnsSuccessEvenWithAlerts(): void
    {
        $manager = new AlertManager(
            $this->repository,
            $this->channelRepository,
            $this->eventDispatcher,
            ['enabled' => true],
        );

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $manager->registerEvaluator(AlertType::StuckJobs, $evaluator);

        $rule = new AlertRule(
            id: 'rule-sj',
            name: 'Stuck Jobs',
            type: AlertType::StuckJobs,
            enabled: true,
            condition: ['threshold' => 1],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $evaluator
            ->shouldReceive('evaluate')
            ->once()
            ->andReturn(new AlertEvaluation(
                message: 'Stuck jobs detected',
                severity: AlertSeverity::Critical,
                context: [],
            ));

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([$rule]);

        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->andReturn(1);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once();

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->once();

        $this->app->instance(AlertManager::class, $manager);

        $this->artisan('station:alerts:check')
            ->assertSuccessful();
    }

    public function testCommandWithoutSeedOptionDoesNotCallSeedFromConfig(): void
    {
        $manager = new AlertManager(
            $this->repository,
            $this->channelRepository,
            $this->eventDispatcher,
            ['enabled' => false],
        );

        // evaluate() returns [] since disabled
        // seedFromConfig should NOT be called (no --seed option)
        // We verify this by confirming no repository methods for seeding are called

        $this->app->instance(AlertManager::class, $manager);

        $this->artisan('station:alerts:check')
            ->expectsOutput('No alerts triggered.')
            ->assertSuccessful();
    }
}
