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
use Station\DTOs\AlertChannel;
use Station\DTOs\AlertRule;
use Station\DTOs\PaginatedResult;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertType;
use Station\StationServiceProvider;

/**
 * Feature tests for AlertController JSON API endpoints.
 *
 * AlertManager and AlertController are both final, so we construct them
 * manually with mocked repository interfaces and bind the controller
 * into the container.
 */
class AlertControllerTest extends TestCase
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

    // ======================================================================
    // Channel API endpoints
    // ======================================================================

    // ---- List channels ----

    public function testChannelsListReturnsAllChannels(): void
    {
        $channel = new AlertChannel(
            id: 'ch-1',
            name: 'Slack Alerts',
            type: AlertChannelType::Slack,
            enabled: true,
            config: ['webhook_url' => 'https://hooks.slack.com/test'],
        );

        $this->channelRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([$channel]);

        $this->getJson('/station/api/alerts/channels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'ch-1')
            ->assertJsonPath('data.0.name', 'Slack Alerts')
            ->assertJsonPath('data.0.type', 'slack');
    }

    public function testChannelsListReturnsEmptyArrayWhenNoChannels(): void
    {
        $this->channelRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $this->getJson('/station/api/alerts/channels')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ---- Create channel ----

    public function testStoreChannelWithValidDataReturns201(): void
    {
        $this->channelRepository->shouldReceive('store')
            ->once()
            ->with(Mockery::on(
                static fn(AlertChannel $ch) => $ch->name === 'New Slack' && $ch->type === AlertChannelType::Slack && $ch->enabled === true,
            ));

        $this->postJson('/station/api/alerts/channels', [
            'name' => 'New Slack',
            'type' => 'slack',
            'enabled' => true,
            'config' => ['webhook_url' => 'https://hooks.slack.com/test'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'New Slack')
            ->assertJsonPath('data.type', 'slack')
            ->assertJsonPath('data.enabled', true);
    }

    public function testStoreChannelDefaultsEnabledToTrue(): void
    {
        $this->channelRepository->shouldReceive('store')
            ->once()
            ->with(Mockery::on(static fn(AlertChannel $ch) => $ch->enabled === true));

        $this->postJson('/station/api/alerts/channels', [
            'name' => 'Webhook',
            'type' => 'webhook',
            'config' => ['url' => 'https://example.com/hook'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.enabled', true);
    }

    public function testStoreChannelWithMissingNameReturns422(): void
    {
        $this->postJson('/station/api/alerts/channels', [
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://hooks.slack.com/test'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function testStoreChannelWithMissingTypeReturns422(): void
    {
        $this->postJson('/station/api/alerts/channels', [
            'name' => 'My Channel',
            'config' => ['webhook_url' => 'https://hooks.slack.com/test'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function testStoreChannelWithInvalidTypeReturns422(): void
    {
        $this->postJson('/station/api/alerts/channels', [
            'name' => 'My Channel',
            'type' => 'invalid_type',
            'config' => ['key' => 'value'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function testStoreChannelWithMissingConfigReturns422(): void
    {
        $this->postJson('/station/api/alerts/channels', [
            'name' => 'My Channel',
            'type' => 'slack',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['config']);
    }

    public function testStoreChannelWithEmptyPayloadReturns422(): void
    {
        $this->postJson('/station/api/alerts/channels', [])
            ->assertStatus(422);
    }

    public function testStoreChannelWithAllValidChannelTypes(): void
    {
        foreach (AlertChannelType::values() as $type) {
            $this->channelRepository->shouldReceive('store')->once();

            $this->postJson('/station/api/alerts/channels', [
                'name' => "Channel {$type}",
                'type' => $type,
                'config' => ['key' => 'value'],
            ])
                ->assertStatus(201);
        }
    }

    // ---- Update channel ----

    public function testUpdateChannelWithValidDataReturnsOk(): void
    {
        $existing = new AlertChannel(
            id: 'ch-1',
            name: 'Old Name',
            type: AlertChannelType::Slack,
            enabled: true,
            config: ['webhook_url' => 'https://hooks.slack.com/old'],
        );

        $updated = new AlertChannel(
            id: 'ch-1',
            name: 'Updated Name',
            type: AlertChannelType::Slack,
            enabled: true,
            config: ['webhook_url' => 'https://hooks.slack.com/old'],
        );

        $this->channelRepository->shouldReceive('find')
            ->with('ch-1')
            ->andReturn($existing, $updated);

        $this->channelRepository->shouldReceive('update')
            ->with('ch-1', Mockery::on(static fn($data) => $data['name'] === 'Updated Name'))
            ->once();

        $this->putJson('/station/api/alerts/channels/ch-1', [
            'name' => 'Updated Name',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function testUpdateChannelThatDoesNotExistReturns404(): void
    {
        $this->channelRepository->shouldReceive('find')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->putJson('/station/api/alerts/channels/nonexistent', [
            'name' => 'Test',
        ])
            ->assertStatus(404)
            ->assertJsonPath('error', 'Channel not found');
    }

    public function testUpdateChannelWithInvalidTypeReturns422(): void
    {
        $existing = new AlertChannel(
            id: 'ch-1',
            name: 'Old Name',
            type: AlertChannelType::Slack,
            enabled: true,
            config: [],
        );

        $this->channelRepository->shouldReceive('find')
            ->with('ch-1')
            ->once()
            ->andReturn($existing);

        $this->putJson('/station/api/alerts/channels/ch-1', [
            'type' => 'bogus_type',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ---- Delete channel ----

    public function testDestroyChannelReturns204(): void
    {
        $existing = new AlertChannel(
            id: 'ch-1',
            name: 'Slack',
            type: AlertChannelType::Slack,
            enabled: true,
            config: [],
        );

        $this->channelRepository->shouldReceive('find')
            ->with('ch-1')
            ->once()
            ->andReturn($existing);

        // No rules reference this channel
        $this->alertRepository->shouldReceive('getAllRules')
            ->once()
            ->andReturn([]);

        $this->channelRepository->shouldReceive('delete')
            ->with('ch-1')
            ->once();

        $this->deleteJson('/station/api/alerts/channels/ch-1')
            ->assertStatus(204);
    }

    public function testDestroyChannelThatDoesNotExistReturns404(): void
    {
        $this->channelRepository->shouldReceive('find')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->deleteJson('/station/api/alerts/channels/nonexistent')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Channel not found');
    }

    public function testDestroyChannelReferencedByRuleReturns409(): void
    {
        $existing = new AlertChannel(
            id: 'ch-1',
            name: 'Slack',
            type: AlertChannelType::Slack,
            enabled: true,
            config: [],
        );

        $rule = new AlertRule(
            id: 'rule-1',
            name: 'High Failure Rate',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $this->channelRepository->shouldReceive('find')
            ->with('ch-1')
            ->once()
            ->andReturn($existing);

        $this->alertRepository->shouldReceive('getAllRules')
            ->once()
            ->andReturn([$rule]);

        $this->deleteJson('/station/api/alerts/channels/ch-1')
            ->assertStatus(409)
            ->assertJsonPath('error', 'Channel is referenced by rule "High Failure Rate". Remove it from the rule first.');
    }

    // ---- Test channel ----

    public function testTestChannelSendsTestNotification(): void
    {
        $channel = new AlertChannel(
            id: 'ch-1',
            name: 'Slack',
            type: AlertChannelType::Slack,
            enabled: true,
            config: ['webhook_url' => 'https://hooks.slack.com/test'],
        );

        $this->channelRepository->shouldReceive('find')
            ->with('ch-1')
            ->once()
            ->andReturn($channel);

        $this->postJson('/station/api/alerts/channels/ch-1/test')
            ->assertOk()
            ->assertJsonPath('message', 'Test notification sent');
    }

    public function testTestChannelThatDoesNotExistReturns404(): void
    {
        $this->channelRepository->shouldReceive('find')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->postJson('/station/api/alerts/channels/nonexistent/test')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Channel not found');
    }

    // ======================================================================
    // Rule API endpoints
    // ======================================================================

    // ---- List rules ----

    public function testRulesListReturnsAllRules(): void
    {
        $rule = new AlertRule(
            id: 'rule-1',
            name: 'High Failure Rate',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('getAllRules')
            ->once()
            ->andReturn([$rule]);

        $this->getJson('/station/api/alerts/rules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'rule-1')
            ->assertJsonPath('data.0.name', 'High Failure Rate')
            ->assertJsonPath('data.0.type', 'high_failure_rate');
    }

    // ---- Show rule ----

    public function testShowRuleReturnsRuleData(): void
    {
        $rule = new AlertRule(
            id: 'rule-1',
            name: 'Queue Backup',
            type: AlertType::QueueBackup,
            enabled: true,
            condition: ['threshold' => 1000],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->once()
            ->andReturn($rule);

        $this->getJson('/station/api/alerts/rules/rule-1')
            ->assertOk()
            ->assertJsonPath('data.id', 'rule-1')
            ->assertJsonPath('data.name', 'Queue Backup');
    }

    public function testShowRuleReturns404WhenNotFound(): void
    {
        $this->alertRepository->shouldReceive('findRule')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->getJson('/station/api/alerts/rules/nonexistent')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Rule not found');
    }

    // ---- Create rule ----

    public function testStoreRuleWithValidDataReturns201(): void
    {
        $this->alertRepository->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(
                static fn(AlertRule $rule) => $rule->name === 'New Rule'
                && $rule->type === AlertType::HighFailureRate
                && $rule->enabled === true
                && $rule->condition === ['threshold' => 15]
                && $rule->channel_ids === ['ch-1']
                && $rule->source === 'user',
            ));

        $this->postJson('/station/api/alerts/rules', [
            'name' => 'New Rule',
            'type' => 'high_failure_rate',
            'condition' => ['threshold' => 15],
            'channel_ids' => ['ch-1'],
            'window' => 300,
            'cooldown' => 300,
            'enabled' => true,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'New Rule')
            ->assertJsonPath('data.type', 'high_failure_rate')
            ->assertJsonPath('data.source', 'user');
    }

    public function testStoreRuleDefaultsWindowAndCooldown(): void
    {
        $this->alertRepository->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(
                static fn(AlertRule $rule) => $rule->window === 300 && $rule->cooldown === 300,
            ));

        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Defaults Rule',
            'type' => 'stuck_jobs',
            'condition' => ['threshold' => 1],
            'channel_ids' => ['ch-1'],
        ])
            ->assertStatus(201);
    }

    public function testStoreRuleWithMissingNameReturns422(): void
    {
        $this->postJson('/station/api/alerts/rules', [
            'type' => 'high_failure_rate',
            'condition' => [],
            'channel_ids' => ['ch-1'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function testStoreRuleWithMissingTypeReturns422(): void
    {
        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Test Rule',
            'condition' => [],
            'channel_ids' => ['ch-1'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function testStoreRuleWithInvalidTypeReturns422(): void
    {
        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Test Rule',
            'type' => 'bogus_type',
            'condition' => [],
            'channel_ids' => ['ch-1'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function testStoreRuleWithMissingChannelIdsReturns422(): void
    {
        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Test Rule',
            'type' => 'high_failure_rate',
            'condition' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel_ids']);
    }

    public function testStoreRuleWithWindowBelowMinimumReturns422(): void
    {
        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Test Rule',
            'type' => 'high_failure_rate',
            'condition' => [],
            'channel_ids' => ['ch-1'],
            'window' => 10, // min:60
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['window']);
    }

    public function testStoreRuleWithCooldownBelowMinimumReturns422(): void
    {
        $this->postJson('/station/api/alerts/rules', [
            'name' => 'Test Rule',
            'type' => 'high_failure_rate',
            'condition' => [],
            'channel_ids' => ['ch-1'],
            'cooldown' => 5, // min:60
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cooldown']);
    }

    // ---- Update rule ----

    public function testUpdateRuleWithValidDataReturnsOk(): void
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
            name: 'Updated Name',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->andReturn($existing, $updated);

        $this->alertRepository->shouldReceive('updateRule')
            ->with('rule-1', Mockery::on(static fn($data) => $data['name'] === 'Updated Name'))
            ->once();

        $this->putJson('/station/api/alerts/rules/rule-1', [
            'name' => 'Updated Name',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function testUpdateRuleReturns404WhenNotFound(): void
    {
        $this->alertRepository->shouldReceive('findRule')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->putJson('/station/api/alerts/rules/nonexistent', [
            'name' => 'Test',
        ])
            ->assertStatus(404)
            ->assertJsonPath('error', 'Rule not found');
    }

    public function testUpdateRuleWithInvalidTypeReturns422(): void
    {
        $existing = new AlertRule(
            id: 'rule-1',
            name: 'Test',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->once()
            ->andReturn($existing);

        $this->putJson('/station/api/alerts/rules/rule-1', [
            'type' => 'bogus_type',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ---- Delete rule ----

    public function testDestroyRuleReturns204(): void
    {
        $existing = new AlertRule(
            id: 'rule-1',
            name: 'Test',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->once()
            ->andReturn($existing);

        $this->alertRepository->shouldReceive('deleteRule')
            ->with('rule-1')
            ->once();

        $this->deleteJson('/station/api/alerts/rules/rule-1')
            ->assertStatus(204);
    }

    public function testDestroyRuleReturns404WhenNotFound(): void
    {
        $this->alertRepository->shouldReceive('findRule')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->deleteJson('/station/api/alerts/rules/nonexistent')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Rule not found');
    }

    // ---- Toggle rule ----

    public function testToggleRuleEnablesDisabledRule(): void
    {
        $original = new AlertRule(
            id: 'rule-1',
            name: 'Test',
            type: AlertType::HighFailureRate,
            enabled: false,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $toggled = new AlertRule(
            id: 'rule-1',
            name: 'Test',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->andReturn($original, $toggled);

        $this->alertRepository->shouldReceive('updateRule')
            ->with('rule-1', ['enabled' => true])
            ->once();

        $this->postJson('/station/api/alerts/rules/rule-1/toggle')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
    }

    public function testToggleRuleDisablesEnabledRule(): void
    {
        $original = new AlertRule(
            id: 'rule-1',
            name: 'Test',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $toggled = new AlertRule(
            id: 'rule-1',
            name: 'Test',
            type: AlertType::HighFailureRate,
            enabled: false,
            condition: [],
            window: 300,
            channel_ids: [],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->andReturn($original, $toggled);

        $this->alertRepository->shouldReceive('updateRule')
            ->with('rule-1', ['enabled' => false])
            ->once();

        $this->postJson('/station/api/alerts/rules/rule-1/toggle')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    public function testToggleRuleReturns404WhenNotFound(): void
    {
        $this->alertRepository->shouldReceive('findRule')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->postJson('/station/api/alerts/rules/nonexistent/toggle')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Rule not found');
    }

    // ---- Test rule ----

    public function testTestRuleSendsTestAlert(): void
    {
        $rule = new AlertRule(
            id: 'rule-1',
            name: 'Test Rule',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
        );

        $this->alertRepository->shouldReceive('findRule')
            ->with('rule-1')
            ->once()
            ->andReturn($rule);

        // fire() calls channelRepository.findMany() and alertRepository.storeRecord/markTriggered
        $this->channelRepository->shouldReceive('findMany')
            ->with(['ch-1'])
            ->once()
            ->andReturn([]);

        $this->alertRepository->shouldReceive('storeRecord')
            ->once()
            ->andReturn(42);

        $this->alertRepository->shouldReceive('markTriggered')
            ->with('rule-1')
            ->once();

        $this->postJson('/station/api/alerts/rules/rule-1/test')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function testTestRuleReturns404WhenNotFound(): void
    {
        $this->alertRepository->shouldReceive('findRule')
            ->with('nonexistent')
            ->once()
            ->andReturnNull();

        $this->postJson('/station/api/alerts/rules/nonexistent/test')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Rule not found');
    }

    // ---- Alert history ----

    public function testAlertHistoryReturnsPaginatedData(): void
    {
        $paginatedResult = new PaginatedResult(
            data: [['id' => 1, 'message' => 'Test alert']],
            total: 1,
            per_page: 25,
            current_page: 1,
            last_page: 1,
            from: 1,
            to: 1,
        );

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with([], 1, 25)
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('current_page', 1);
    }

    public function testAlertHistoryWithFilters(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with(
                Mockery::on(static fn($f) => ($f['type'] ?? null) === 'high_failure_rate'),
                1,
                25,
            )
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?type=high_failure_rate')
            ->assertOk();
    }

    public function testAlertHistoryWithPagination(): void
    {
        $paginatedResult = PaginatedResult::empty(50);

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with([], 2, 50)
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?page=2&per_page=50')
            ->assertOk();
    }

    public function testAlertHistoryPerPageClampedToMax100(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with([], 1, 100) // Clamped from 500 to 100
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?per_page=500')
            ->assertOk();
    }

    public function testAlertHistoryPerPageClampedToMin1(): void
    {
        $paginatedResult = PaginatedResult::empty();

        $this->alertRepository->shouldReceive('paginateHistory')
            ->with([], 1, 1) // Clamped from -5 to 1
            ->once()
            ->andReturn($paginatedResult);

        $this->getJson('/station/api/alerts/history?per_page=-5')
            ->assertOk();
    }

    // ---- Resolve alert ----

    public function testResolveAlertReturnsOk(): void
    {
        $this->alertRepository->shouldReceive('resolveRecord')
            ->with(42)
            ->once();

        $this->postJson('/station/api/alerts/history/42/resolve')
            ->assertOk()
            ->assertJsonPath('message', 'Alert resolved');
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
