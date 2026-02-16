<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Alerts;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Station\Alerts\AlertManager;
use Station\Alerts\Evaluators\AlertEvaluatorInterface;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\DTOs\AlertChannel;
use Station\DTOs\AlertEvaluation;
use Station\DTOs\AlertRecord;
use Station\DTOs\AlertRule;
use Station\DTOs\PaginatedResult;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;
use Station\Events\AlertTriggered;

class AlertManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&AlertRepositoryInterface $repository;

    private MockInterface&AlertChannelRepositoryInterface $channelRepository;

    private MockInterface&Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(AlertRepositoryInterface::class);
        $this->channelRepository = Mockery::mock(AlertChannelRepositoryInterface::class);
        $this->events = Mockery::mock(Dispatcher::class);

        // Set up minimal container for logger() and now() and Notification facade
        $container = new Container();
        $container->instance('log', new NullLogger());
        $container->instance('config', new class {
            /**
             */
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        });
        Container::setInstance($container);

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::setInstance(null);
        parent::tearDown();
    }

    // ---- evaluate() tests ----

    public function testEvaluateReturnsEmptyWhenDisabled(): void
    {
        $manager = $this->makeManager(['enabled' => false]);

        $result = $manager->evaluate();

        $this->assertSame([], $result);
    }

    public function testEvaluateSkipsRulesInCooldown(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $ruleInCooldown = new AlertRule(
            id: 'rule-1',
            name: 'Test Rule',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
            last_triggered_at: '2026-01-15 11:58:00', // 2 minutes ago, still in 5-min cooldown
        );

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([$ruleInCooldown]);

        $result = $manager->evaluate();

        $this->assertSame([], $result);
    }

    public function testEvaluateSkipsRulesWithNoEvaluatorRegistered(): void
    {
        $manager = $this->makeManager(['enabled' => true]);
        // No evaluator registered for HighFailureRate

        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([$rule]);

        $result = $manager->evaluate();

        $this->assertSame([], $result);
    }

    public function testEvaluateFiresAlertWhenEvaluatorReturnsNonNull(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $evaluation = new AlertEvaluation(
            message: 'Test alert',
            severity: AlertSeverity::Warning,
            context: ['key' => 'value'],
        );
        $evaluator->shouldReceive('evaluate')->once()->andReturn($evaluation);

        $manager->registerEvaluator(AlertType::HighFailureRate, $evaluator);

        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([$rule]);

        // fire() expectations
        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->with(['ch-1'])
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->andReturn(42);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once()
            ->with('rule-1');

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(AlertTriggered::class));

        $result = $manager->evaluate();

        $this->assertCount(1, $result);
        $this->assertInstanceOf(AlertRecord::class, $result[0]);
        $this->assertSame(42, $result[0]->id);
        $this->assertSame('Test alert', $result[0]->message);
    }

    public function testEvaluateCatchesEvaluatorExceptionsAndContinues(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $failingEvaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $failingEvaluator->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('boom'));

        $manager->registerEvaluator(AlertType::HighFailureRate, $failingEvaluator);

        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([$rule]);

        $result = $manager->evaluate();

        $this->assertSame([], $result);
    }

    public function testEvaluateReturnsNullWhenEvaluatorReturnsNull(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $evaluator->shouldReceive('evaluate')->once()->andReturn(null);

        $manager->registerEvaluator(AlertType::HighFailureRate, $evaluator);

        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('getEnabledRules')
            ->once()
            ->andReturn([$rule]);

        $result = $manager->evaluate();

        $this->assertSame([], $result);
    }

    // ---- evaluateType() tests ----

    public function testEvaluateTypeReturnsNullWhenDisabled(): void
    {
        $manager = $this->makeManager(['enabled' => false]);

        $result = $manager->evaluateType(AlertType::HighFailureRate);

        $this->assertNull($result);
    }

    public function testEvaluateTypeReturnsNullWithNoEvaluator(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $result = $manager->evaluateType(AlertType::HighFailureRate);

        $this->assertNull($result);
    }

    public function testEvaluateTypeSkipsDisabledRules(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $manager->registerEvaluator(AlertType::HighFailureRate, $evaluator);

        $disabledRule = new AlertRule(
            id: 'rule-1',
            name: 'Disabled Rule',
            type: AlertType::HighFailureRate,
            enabled: false,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $this->repository
            ->shouldReceive('getRulesByType')
            ->once()
            ->with(AlertType::HighFailureRate)
            ->andReturn([$disabledRule]);

        $result = $manager->evaluateType(AlertType::HighFailureRate);

        $this->assertNull($result);
    }

    public function testEvaluateTypeSkipsCooldownRules(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $manager->registerEvaluator(AlertType::HighFailureRate, $evaluator);

        $ruleInCooldown = new AlertRule(
            id: 'rule-1',
            name: 'Cooldown Rule',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
            last_triggered_at: '2026-01-15 11:58:00',
        );

        $this->repository
            ->shouldReceive('getRulesByType')
            ->once()
            ->andReturn([$ruleInCooldown]);

        $result = $manager->evaluateType(AlertType::HighFailureRate);

        $this->assertNull($result);
    }

    public function testEvaluateTypeFiresAndReturnsAlertRecord(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $evaluation = new AlertEvaluation(
            message: 'Reactive alert',
            severity: AlertSeverity::Warning,
            context: [],
        );
        $evaluator->shouldReceive('evaluate')->once()->andReturn($evaluation);

        $manager->registerEvaluator(AlertType::HighFailureRate, $evaluator);

        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('getRulesByType')
            ->once()
            ->with(AlertType::HighFailureRate)
            ->andReturn([$rule]);

        // fire() expectations
        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->andReturn(99);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once()
            ->with('rule-1');

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(AlertTriggered::class));

        $result = $manager->evaluateType(AlertType::HighFailureRate);

        $this->assertNotNull($result);
        $this->assertSame(99, $result->id);
        $this->assertSame('Reactive alert', $result->message);
    }

    public function testEvaluateTypeCatchesExceptionsAndReturnsNull(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $evaluator = Mockery::mock(AlertEvaluatorInterface::class);
        $evaluator->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('boom'));

        $manager->registerEvaluator(AlertType::HighFailureRate, $evaluator);

        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('getRulesByType')
            ->once()
            ->andReturn([$rule]);

        $result = $manager->evaluateType(AlertType::HighFailureRate);

        $this->assertNull($result);
    }

    // ---- fire() tests ----

    public function testFireCreatesAlertRecordStoresItAndDispatchesEvent(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $rule = $this->makeRule();
        $evaluation = new AlertEvaluation(
            message: 'Fire test',
            severity: AlertSeverity::Critical,
            context: ['x' => 1],
        );

        $channel = new AlertChannel(
            id: 'ch-1',
            name: 'slack-main',
            type: AlertChannelType::Slack,
            enabled: true,
            config: ['webhook_url' => 'https://hooks.slack.com/test'],
        );

        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->with(['ch-1'])
            ->andReturn([$channel]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->with(Mockery::on(static fn(AlertRecord $record): bool => $record->id === null
                    && $record->rule_id === 'rule-1'
                    && $record->rule_name === 'Test Rule'
                    && $record->type === AlertType::HighFailureRate
                    && $record->severity === AlertSeverity::Critical
                    && $record->message === 'Fire test'
                    && $record->channels_notified === ['slack-main']))
            ->andReturn(10);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once()
            ->with('rule-1');

        $this->events
            ->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(static fn(AlertTriggered $event): bool => $event->alert->id === 10
                    && $event->alert->message === 'Fire test'));

        $record = $manager->fire($rule, $evaluation);

        $this->assertSame(10, $record->id);
        $this->assertSame('Fire test', $record->message);
        $this->assertSame(AlertSeverity::Critical, $record->severity);
        $this->assertSame(['slack-main'], $record->channels_notified);
    }

    public function testFireExcludesDisabledChannelsFromNotifiedList(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $rule = $this->makeRule(channelIds: ['ch-1', 'ch-2']);
        $evaluation = new AlertEvaluation(
            message: 'test',
            severity: AlertSeverity::Info,
            context: [],
        );

        $enabledChannel = new AlertChannel(
            id: 'ch-1',
            name: 'slack-enabled',
            type: AlertChannelType::Slack,
            enabled: true,
            config: ['webhook_url' => 'https://hooks.slack.com/test'],
        );

        $disabledChannel = new AlertChannel(
            id: 'ch-2',
            name: 'slack-disabled',
            type: AlertChannelType::Slack,
            enabled: false,
            config: ['webhook_url' => 'https://hooks.slack.com/test2'],
        );

        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->andReturn([$enabledChannel, $disabledChannel]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->with(Mockery::on(static function (AlertRecord $record): bool {
                // Only enabled channel should be in channels_notified
                return $record->channels_notified === ['slack-enabled'];
            }))
            ->andReturn(11);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->once();

        $record = $manager->fire($rule, $evaluation);

        $this->assertSame(['slack-enabled'], $record->channels_notified);
    }

    // ---- testChannel() tests ----

    public function testTestChannelReturnsFalseForUnknownChannel(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $this->channelRepository
            ->shouldReceive('find')
            ->once()
            ->with('unknown-id')
            ->andReturn(null);

        $result = $manager->testChannel('unknown-id');

        $this->assertFalse($result);
    }

    public function testTestChannelSendsToFoundChannel(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $channel = new AlertChannel(
            id: 'ch-1',
            name: 'log-channel',
            type: AlertChannelType::Log,
            enabled: true,
            config: ['channel' => 'station-alerts'],
        );

        $this->channelRepository
            ->shouldReceive('find')
            ->once()
            ->with('ch-1')
            ->andReturn($channel);

        // sendToChannels will be called - it uses Notification facade internally
        // Since we don't have the facade set up, it will throw, but it's caught
        // The method should still return true
        $result = $manager->testChannel('ch-1');

        $this->assertTrue($result);
    }

    // ---- CRUD proxies ----

    public function testCreateRuleProxiesToRepository(): void
    {
        $manager = $this->makeManager();
        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with($rule);

        $manager->createRule($rule);
    }

    public function testUpdateRuleProxiesToRepository(): void
    {
        $manager = $this->makeManager();

        $this->repository
            ->shouldReceive('updateRule')
            ->once()
            ->with('rule-1', ['name' => 'Updated']);

        $manager->updateRule('rule-1', ['name' => 'Updated']);
    }

    public function testDeleteRuleProxiesToRepository(): void
    {
        $manager = $this->makeManager();

        $this->repository
            ->shouldReceive('deleteRule')
            ->once()
            ->with('rule-1');

        $manager->deleteRule('rule-1');
    }

    public function testGetRulesProxiesToRepository(): void
    {
        $manager = $this->makeManager();
        $rules = [$this->makeRule()];

        $this->repository
            ->shouldReceive('getAllRules')
            ->once()
            ->andReturn($rules);

        $result = $manager->getRules();

        $this->assertSame($rules, $result);
    }

    public function testGetRuleProxiesToRepository(): void
    {
        $manager = $this->makeManager();
        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('findRule')
            ->once()
            ->with('rule-1')
            ->andReturn($rule);

        $result = $manager->getRule('rule-1');

        $this->assertSame($rule, $result);
    }

    public function testGetHistoryProxiesToRepository(): void
    {
        $manager = $this->makeManager();
        $paginated = PaginatedResult::empty();

        $this->repository
            ->shouldReceive('paginateHistory')
            ->once()
            ->with(['type' => 'high_failure_rate'], 2, 10)
            ->andReturn($paginated);

        $result = $manager->getHistory(['type' => 'high_failure_rate'], 2, 10);

        $this->assertSame($paginated, $result);
    }

    public function testResolveAlertProxiesToRepository(): void
    {
        $manager = $this->makeManager();

        $this->repository
            ->shouldReceive('resolveRecord')
            ->once()
            ->with(42);

        $manager->resolveAlert(42);
    }

    public function testPruneHistoryProxiesToRepository(): void
    {
        $manager = $this->makeManager();

        $this->repository
            ->shouldReceive('pruneHistory')
            ->once()
            ->with(30)
            ->andReturn(5);

        $result = $manager->pruneHistory(30);

        $this->assertSame(5, $result);
    }

    // ---- toggleRule() tests ----

    public function testToggleRuleTogglesEnabledStatus(): void
    {
        $manager = $this->makeManager();
        $rule = $this->makeRule(); // enabled = true

        $this->repository
            ->shouldReceive('findRule')
            ->with('rule-1')
            ->twice()
            ->andReturn($rule);

        $this->repository
            ->shouldReceive('updateRule')
            ->once()
            ->with('rule-1', ['enabled' => false]);

        $result = $manager->toggleRule('rule-1');

        $this->assertNotNull($result);
    }

    public function testToggleRuleReturnsNullForUnknownRule(): void
    {
        $manager = $this->makeManager();

        $this->repository
            ->shouldReceive('findRule')
            ->once()
            ->with('unknown')
            ->andReturn(null);

        $result = $manager->toggleRule('unknown');

        $this->assertNull($result);
    }

    // ---- testRule() tests ----

    public function testTestRuleReturnsNullForUnknownRule(): void
    {
        $manager = $this->makeManager();

        $this->repository
            ->shouldReceive('findRule')
            ->once()
            ->with('unknown')
            ->andReturn(null);

        $result = $manager->testRule('unknown');

        $this->assertNull($result);
    }

    public function testTestRuleFiresTestAlertForKnownRule(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $rule = $this->makeRule();

        $this->repository
            ->shouldReceive('findRule')
            ->once()
            ->with('rule-1')
            ->andReturn($rule);

        // fire() expectations
        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->with(['ch-1'])
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->andReturn(100);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once()
            ->with('rule-1');

        $this->events
            ->shouldReceive('dispatch')
            ->once();

        $result = $manager->testRule('rule-1');

        $this->assertNotNull($result);
        $this->assertSame(100, $result->id);
        $this->assertStringContainsString('Test alert for rule', $result->message);
    }

    public function testTestRuleUsesWarningSeverityForWorkerDownType(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $rule = new AlertRule(
            id: 'rule-wd',
            name: 'Worker Down Rule',
            type: AlertType::WorkerDown,
            enabled: true,
            condition: ['min_workers' => 1],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $this->repository
            ->shouldReceive('findRule')
            ->once()
            ->with('rule-wd')
            ->andReturn($rule);

        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->with(Mockery::on(static fn(AlertRecord $record): bool => $record->severity === AlertSeverity::Warning))
            ->andReturn(101);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->once();

        $result = $manager->testRule('rule-wd');

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Warning, $result->severity);
    }

    public function testTestRuleUsesInfoSeverityForNonWorkerDownType(): void
    {
        $manager = $this->makeManager(['enabled' => true]);

        $rule = $this->makeRule(); // type = HighFailureRate

        $this->repository
            ->shouldReceive('findRule')
            ->once()
            ->with('rule-1')
            ->andReturn($rule);

        $this->channelRepository
            ->shouldReceive('findMany')
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRecord')
            ->once()
            ->with(Mockery::on(static fn(AlertRecord $record): bool => $record->severity === AlertSeverity::Info))
            ->andReturn(102);

        $this->repository
            ->shouldReceive('markTriggered')
            ->once();

        $this->events
            ->shouldReceive('dispatch')
            ->once();

        $result = $manager->testRule('rule-1');

        $this->assertNotNull($result);
        $this->assertSame(AlertSeverity::Info, $result->severity);
    }

    // ---- helpers ----

    /**
     * @param array<string, mixed> $config
     */
    private function makeManager(array $config = []): AlertManager
    {
        return new AlertManager(
            $this->repository,
            $this->channelRepository,
            $this->events,
            $config,
        );
    }

    /**
     * @param array<int, string> $channelIds
     */
    private function makeRule(array $channelIds = ['ch-1']): AlertRule
    {
        return new AlertRule(
            id: 'rule-1',
            name: 'Test Rule',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: $channelIds,
            cooldown: 300,
        );
    }
}
