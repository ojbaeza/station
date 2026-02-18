<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use Station\Alerts\AlertManager;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\Dashboard\Http\Controllers\AlertController;
use Station\DTOs\AlertRule;
use Station\DTOs\PaginatedResult;
use Station\Enums\AlertType;
use Station\StationServiceProvider;

/**
 * Extended feature tests for AlertController covering:
 * - alertHistory with 'resolved' filter
 * - alertHistory with severity filter
 * - storeRule with metadata
 * - updateRule with multiple fields
 * - storeChannel with explicit enabled=false
 */
class AlertControllerExtendedTest extends TestCase
{
    private AlertRepositoryInterface&MockInterface $alertRepository;

    private AlertChannelRepositoryInterface&MockInterface $channelRepository;

    private AlertManager $alertManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alertRepository = Mockery::mock(AlertRepositoryInterface::class);
        $this->channelRepository = Mockery::mock(AlertChannelRepositoryInterface::class);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        $this->alertManager = new AlertManager(
            $this->alertRepository,
            $this->channelRepository,
            $events,
            ['enabled' => true],
        );

        $controller = new AlertController($this->alertManager, $this->channelRepository);
        $this->app->instance(AlertController::class, $controller);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- Alert History with resolved filter ----

    public function testAlertHistoryWithResolvedFilterTrue(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with(
                Mockery::on(static fn($f) => isset($f['resolved']) && $f['resolved'] === true),
                1,
                25,
            )
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?resolved=1')
            ->assertOk();
    }

    public function testAlertHistoryWithResolvedFilterFalse(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with(
                Mockery::on(static fn($f) => isset($f['resolved']) && $f['resolved'] === false),
                1,
                25,
            )
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?resolved=0')
            ->assertOk();
    }

    // ---- Alert History with severity filter ----

    public function testAlertHistoryWithSeverityFilter(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with(
                Mockery::on(static fn($f) => ($f['severity'] ?? null) === 'critical'),
                1,
                25,
            )
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?severity=critical')
            ->assertOk();
    }

    // ---- Alert History with combined filters ----

    public function testAlertHistoryWithMultipleFilters(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with(
                Mockery::on(static fn($f) => ($f['type'] ?? null) === 'stuck_jobs'
                    && ($f['severity'] ?? null) === 'warning'
                    && isset($f['resolved'])),
                2,
                10,
            )
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?type=stuck_jobs&severity=warning&resolved=0&page=2&per_page=10')
            ->assertOk();
    }

    // ---- Store rule with metadata ----

    public function testStoreRuleWithMetadata(): void
    {
        $this->alertRepository->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(
                static fn(AlertRule $rule) => $rule->metadata === ['queue' => 'high', 'team' => 'backend'],
            ));

        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Rule With Metadata',
            'type' => 'queue_backup',
            'condition' => ['threshold' => 500],
            'channel_ids' => ['ch-1'],
            'metadata' => ['queue' => 'high', 'team' => 'backend'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Rule With Metadata');
    }

    // ---- Store rule with custom window and cooldown ----

    public function testStoreRuleWithCustomWindowAndCooldown(): void
    {
        $this->alertRepository->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(
                static fn(AlertRule $rule) => $rule->window === 600 && $rule->cooldown === 900,
            ));

        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Custom Timings',
            'type' => 'high_failure_rate',
            'condition' => ['threshold' => 20],
            'channel_ids' => ['ch-1'],
            'window' => 600,
            'cooldown' => 900,
        ])
            ->assertStatus(201);
    }

    // ---- Store channel with explicit enabled=false ----

    public function testStoreChannelWithEnabledFalse(): void
    {
        $this->channelRepository->shouldReceive('store')
            ->once()
            ->with(Mockery::on(static fn($ch) => $ch->enabled === false));

        $this->postJson('/station/api/alerts/channels', [
            'name' => 'Disabled Channel',
            'type' => 'webhook',
            'enabled' => false,
            'config' => ['url' => 'https://example.com/hook'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.enabled', false);
    }

    // ---- Update rule with multiple fields ----

    public function testUpdateRuleWithMultipleFields(): void
    {
        $existing = new AlertRule(
            id: 'rule-1',
            name: 'Old Name',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $updated = new AlertRule(
            id: 'rule-1',
            name: 'New Name',
            type: AlertType::HighFailureRate,
            enabled: false,
            condition: ['threshold' => 25],
            window: 600,
            channel_ids: ['ch-1', 'ch-2'],
            cooldown: 600,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->andReturn($existing, $updated);

        $this->alertRepository->shouldReceive('updateRule')
            ->with('rule-1', Mockery::on(static fn($data) => $data['name'] === 'New Name'
                && $data['enabled'] === false
                && $data['condition'] === ['threshold' => 25]
                && $data['window'] === 600
                && $data['cooldown'] === 600
                && $data['channel_ids'] === ['ch-1', 'ch-2']))
            ->once();

        $this->putJson('/station/api/alerts/rules/rule-1', [
            'name' => 'New Name',
            'enabled' => false,
            'condition' => ['threshold' => 25],
            'window' => 600,
            'cooldown' => 600,
            'channel_ids' => ['ch-1', 'ch-2'],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');
    }

    // ---- Alert History page clamping ----

    public function testAlertHistoryPageClampedToMin1(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with([], 1, 25)  // page clamped from -5 to 1
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?page=-5')
            ->assertOk();
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }
}
