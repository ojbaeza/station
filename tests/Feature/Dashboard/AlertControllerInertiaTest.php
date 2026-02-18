<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Dashboard;

use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Mockery\MockInterface;
use Station\Alerts\AlertManager;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\Dashboard\Http\Controllers\AlertController;
use Station\DTOs\AlertChannel;
use Station\DTOs\AlertRule;
use Station\DTOs\PaginatedResult;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertType;
use Station\Tests\TestCase;

/**
 * Feature tests for AlertController's Inertia page methods:
 * - index() - alert history page
 * - rulesPage() - alert rules page
 * - channelsPage() - alert channels page
 *
 * These cover the uncovered Inertia::render() calls in AlertController.
 */
class AlertControllerInertiaTest extends TestCase
{
    private AlertRepositoryInterface&MockInterface $alertRepository;

    private AlertChannelRepositoryInterface&MockInterface $channelRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alertRepository = Mockery::mock(AlertRepositoryInterface::class);
        $this->channelRepository = Mockery::mock(AlertChannelRepositoryInterface::class);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->byDefault();

        $alertManager = new AlertManager(
            $this->alertRepository,
            $this->channelRepository,
            $events,
            ['enabled' => true],
        );

        $controller = new AlertController($alertManager, $this->channelRepository);
        $this->app->instance(AlertController::class, $controller);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- index() - Alert History Inertia page ----

    public function testIndexReturnsInertiaAlertHistoryPage(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->with([], 1, 25)
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/AlertHistory');
    }

    public function testIndexPassesHistoryAndAlertTypesAsProps(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('history', $data);
        $this->assertArrayHasKey('alertTypes', $data);
        $this->assertArrayHasKey('filters', $data);
    }

    public function testIndexWithTypeFilter(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->with(
                Mockery::on(static fn($f) => ($f['type'] ?? null) === 'stuck_jobs'),
                1,
                25,
            )
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts?type=stuck_jobs', $this->inertiaHeaders());

        $response->assertOk();
        $data = $this->inertiaProps($response);
        $this->assertSame('stuck_jobs', $data['filters']['type']);
    }

    public function testIndexWithSeverityFilter(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->with(
                Mockery::on(static fn($f) => ($f['severity'] ?? null) === 'critical'),
                1,
                25,
            )
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts?severity=critical', $this->inertiaHeaders());

        $response->assertOk();
        $data = $this->inertiaProps($response);
        $this->assertSame('critical', $data['filters']['severity']);
    }

    public function testIndexWithResolvedFilter(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->with(
                Mockery::on(static fn($f) => isset($f['resolved']) && $f['resolved'] === true),
                1,
                25,
            )
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts?resolved=1', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testIndexWithPagination(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->with(Mockery::any(), 3, 10)
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts?page=3&per_page=10', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testIndexPerPageClampedToMax100(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->with(Mockery::any(), 1, 100)
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts?per_page=500', $this->inertiaHeaders());

        $response->assertOk();
    }

    public function testIndexPerPageClampedToMin1(): void
    {
        $this->alertRepository->shouldReceive('paginateHistory')
            ->once()
            ->with(Mockery::any(), 1, 1)
            ->andReturn(PaginatedResult::empty());

        $response = $this->get('/station/alerts?per_page=-5', $this->inertiaHeaders());

        $response->assertOk();
    }

    // ---- rulesPage() - Alert Rules Inertia page ----

    public function testRulesPageReturnsInertiaAlertsPage(): void
    {
        $rules = [
            new AlertRule(
                id: 'rule-1',
                name: 'Test Rule',
                type: AlertType::HighFailureRate,
                enabled: true,
                condition: ['threshold' => 10],
                window: 300,
                channel_ids: ['ch-1'],
                cooldown: 300,
            ),
        ];

        $this->alertRepository->shouldReceive('getAllRules')
            ->once()
            ->andReturn($rules);

        $channels = [
            new AlertChannel(
                id: 'ch-1',
                name: 'Slack',
                type: AlertChannelType::Slack,
                enabled: true,
                config: ['url' => 'https://hooks.slack.com/test'],
            ),
        ];

        $this->channelRepository->shouldReceive('getAll')
            ->once()
            ->andReturn($channels);

        $response = $this->get('/station/alerts/rules', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/Alerts');
    }

    public function testRulesPagePassesRulesAndChannelsAsProps(): void
    {
        $this->alertRepository->shouldReceive('getAllRules')
            ->once()
            ->andReturn([]);

        $this->channelRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $response = $this->get('/station/alerts/rules', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('rules', $data);
        $this->assertArrayHasKey('alertTypes', $data);
        $this->assertArrayHasKey('channels', $data);
    }

    // ---- channelsPage() - Alert Channels Inertia page ----

    public function testChannelsPageReturnsInertiaAlertChannelsPage(): void
    {
        $this->channelRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $response = $this->get('/station/alerts/channels', $this->inertiaHeaders());

        $response->assertOk();
        $this->assertInertiaComponent($response, 'Station/AlertChannels');
    }

    public function testChannelsPagePassesChannelsAndChannelTypesAsProps(): void
    {
        $channels = [
            new AlertChannel(
                id: 'ch-1',
                name: 'Webhook',
                type: AlertChannelType::Webhook,
                enabled: true,
                config: ['url' => 'https://example.com/hook'],
            ),
        ];

        $this->channelRepository->shouldReceive('getAll')
            ->once()
            ->andReturn($channels);

        $response = $this->get('/station/alerts/channels', $this->inertiaHeaders());
        $data = $this->inertiaProps($response);

        $this->assertArrayHasKey('channels', $data);
        $this->assertArrayHasKey('channelTypes', $data);
        $this->assertCount(1, $data['channels']);
    }

    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('station.dashboard.enabled', true);
        $app['config']->set('station.dashboard.middleware', []);
        $app['config']->set('station.dashboard.path', 'station');
        $app['config']->set('station.api.auth', 'none');
        $app['config']->set('station.api.middleware', []);
        $app['config']->set('queue.connections', ['sync' => ['driver' => 'sync']]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('station.tracking.enabled', false);
    }

    /**
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '',
        ];
    }

    private function assertInertiaComponent(mixed $response, string $expected): void
    {
        $data = $response->json();
        $this->assertSame($expected, $data['component'] ?? null, "Expected Inertia component [{$expected}].");
    }

    /**
     * @return array<string, mixed>
     */
    private function inertiaProps(mixed $response): array
    {
        $data = $response->json();

        return $data['props'] ?? [];
    }
}
