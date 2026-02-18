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
use Station\Alerts\AlertManager;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\Contracts\AlertRepositoryInterface;
use Station\DTOs\AlertChannel;
use Station\DTOs\AlertRule;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertType;

/**
 * Tests for AlertManager::seedFromConfig() which handles seeding alert
 * channels and rules from configuration arrays.
 */
class AlertManagerSeedTest extends TestCase
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

        $container = new Container();
        $container->instance('log', new NullLogger());
        $container->instance('config', new class {
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

    public function testSeedFromConfigWithNoChannelsOrRulesReturnsZero(): void
    {
        $manager = $this->makeManager([]);

        $result = $manager->seedFromConfig();

        $this->assertSame(0, $result);
    }

    public function testSeedFromConfigCreatesNewChannels(): void
    {
        $config = [
            'channels' => [
                ['name' => 'slack-main', 'type' => 'slack', 'config' => ['webhook_url' => 'https://hooks.slack.com/test']],
            ],
            'rules' => [],
        ];

        $this->channelRepository
            ->shouldReceive('findByName')
            ->with('slack-main')
            ->once()
            ->andReturnNull();

        $this->channelRepository
            ->shouldReceive('store')
            ->once()
            ->with(Mockery::on(static fn(AlertChannel $ch): bool => $ch->name === 'slack-main'
                && $ch->type === AlertChannelType::Slack
                && $ch->enabled === true
                && $ch->config === ['webhook_url' => 'https://hooks.slack.com/test']));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(0, $result); // No rules seeded
    }

    public function testSeedFromConfigSkipsExistingChannels(): void
    {
        $existing = new AlertChannel(
            id: 'existing-id',
            name: 'slack-main',
            type: AlertChannelType::Slack,
            enabled: true,
            config: ['webhook_url' => 'https://hooks.slack.com/old'],
        );

        $config = [
            'channels' => [
                ['name' => 'slack-main', 'type' => 'slack', 'config' => ['webhook_url' => 'https://hooks.slack.com/test']],
            ],
            'rules' => [],
        ];

        $this->channelRepository
            ->shouldReceive('findByName')
            ->with('slack-main')
            ->once()
            ->andReturn($existing);

        // store() should NOT be called since channel already exists
        $this->channelRepository
            ->shouldNotReceive('store');

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(0, $result);
    }

    public function testSeedFromConfigSkipsChannelsWithEmptyName(): void
    {
        $config = [
            'channels' => [
                ['name' => '', 'type' => 'slack', 'config' => []],
            ],
            'rules' => [],
        ];

        $this->channelRepository->shouldNotReceive('findByName');
        $this->channelRepository->shouldNotReceive('store');

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(0, $result);
    }

    public function testSeedFromConfigSkipsChannelsWithInvalidType(): void
    {
        $config = [
            'channels' => [
                ['name' => 'invalid-channel', 'type' => 'invalid_type', 'config' => []],
            ],
            'rules' => [],
        ];

        $this->channelRepository
            ->shouldReceive('findByName')
            ->with('invalid-channel')
            ->once()
            ->andReturnNull();

        // store() should NOT be called since type is invalid
        $this->channelRepository->shouldNotReceive('store');

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(0, $result);
    }

    public function testSeedFromConfigCreatesNewRulesWithResolvedChannelIds(): void
    {
        $config = [
            'channels' => [
                ['name' => 'slack-main', 'type' => 'slack', 'config' => ['webhook_url' => 'https://hooks.slack.com/test']],
            ],
            'rules' => [
                'high_failure_rate' => [
                    'enabled' => true,
                    'condition' => 'failure_rate > 15',
                    'window' => 600,
                    'cooldown' => 300,
                    'channels' => ['slack-main'],
                ],
            ],
        ];

        // Channel seeding
        $this->channelRepository
            ->shouldReceive('findByName')
            ->with('slack-main')
            ->once()
            ->andReturnNull();

        $this->channelRepository
            ->shouldReceive('store')
            ->once();

        // Rule seeding - no existing rules
        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::HighFailureRate)
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->name === 'High Failure Rate'
                && $rule->type === AlertType::HighFailureRate
                && $rule->enabled === true
                && $rule->condition === ['threshold' => 15.0]
                && $rule->window === 600
                && $rule->cooldown === 300
                && $rule->source === 'config'));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(1, $result);
    }

    public function testSeedFromConfigUpdatesExistingConfigRulesChannelIds(): void
    {
        $existingRule = new AlertRule(
            id: 'existing-rule',
            name: 'High Failure Rate',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['old-channel-id'],
            cooldown: 300,
            source: 'config',
        );

        $config = [
            'channels' => [],
            'rules' => [
                'high_failure_rate' => [
                    'enabled' => true,
                    'condition' => 'failure_rate > 10',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::HighFailureRate)
            ->once()
            ->andReturn([$existingRule]);

        $this->repository
            ->shouldReceive('updateRule')
            ->once()
            ->with('existing-rule', ['channel_ids' => []]);

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        // No new rules seeded because existing rule was updated
        $this->assertSame(0, $result);
    }

    public function testSeedFromConfigSkipsExistingUserRules(): void
    {
        $existingRule = new AlertRule(
            id: 'user-rule',
            name: 'High Failure Rate',
            type: AlertType::HighFailureRate,
            enabled: true,
            condition: ['threshold' => 10],
            window: 300,
            channel_ids: ['ch-1'],
            cooldown: 300,
            source: 'user', // Not 'config' source
        );

        $config = [
            'channels' => [],
            'rules' => [
                'high_failure_rate' => [
                    'enabled' => true,
                    'condition' => 'failure_rate > 10',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::HighFailureRate)
            ->once()
            ->andReturn([$existingRule]);

        // updateRule should NOT be called for user-sourced rules
        $this->repository->shouldNotReceive('updateRule');

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(0, $result);
    }

    public function testSeedFromConfigSkipsInvalidRuleType(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'invalid_type_key' => [
                    'enabled' => true,
                    'condition' => 'something > 5',
                    'channels' => [],
                ],
            ],
        ];

        // getRulesByType should NOT be called for invalid types
        $this->repository->shouldNotReceive('getRulesByType');

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(0, $result);
    }

    public function testSeedFromConfigParsesQueueBackupCondition(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'queue_backup' => [
                    'condition' => 'queue_size > 5000',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::QueueBackup)
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->type === AlertType::QueueBackup
                && $rule->condition === ['threshold' => 5000.0]));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(1, $result);
    }

    public function testSeedFromConfigParsesStuckJobsCondition(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'stuck_jobs' => [
                    'condition' => 'stuck_count > 3',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::StuckJobs)
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->type === AlertType::StuckJobs
                && $rule->condition === ['threshold' => 3.0]));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(1, $result);
    }

    public function testSeedFromConfigParsesWorkerDownCondition(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'worker_down' => [
                    'condition' => 'workers < 2',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::WorkerDown)
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->type === AlertType::WorkerDown
                && $rule->condition === ['min_workers' => 2]));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(1, $result);
    }

    public function testSeedFromConfigUsesDefaultThresholdWhenConditionDoesNotMatch(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'high_failure_rate' => [
                    'condition' => 'no match here',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::HighFailureRate)
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->condition === ['threshold' => 10]));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(1, $result);
    }

    public function testSeedFromConfigUsesDefaultMinWorkersWhenConditionDoesNotMatch(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'worker_down' => [
                    'condition' => 'no match',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::WorkerDown)
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->condition === ['min_workers' => 1]));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(1, $result);
    }

    public function testSeedFromConfigResolvesChannelNamesToIds(): void
    {
        $existing = new AlertChannel(
            id: 'resolved-id-1',
            name: 'slack-main',
            type: AlertChannelType::Slack,
            enabled: true,
            config: [],
        );

        $config = [
            'channels' => [
                ['name' => 'slack-main', 'type' => 'slack', 'config' => []],
            ],
            'rules' => [
                'high_failure_rate' => [
                    'condition' => 'failure_rate > 10',
                    'channels' => ['slack-main'],
                ],
            ],
        ];

        // Channel already exists
        $this->channelRepository
            ->shouldReceive('findByName')
            ->with('slack-main')
            ->once()
            ->andReturn($existing);

        $this->repository
            ->shouldReceive('getRulesByType')
            ->with(AlertType::HighFailureRate)
            ->once()
            ->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->channel_ids === ['resolved-id-1']));

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(1, $result);
    }

    public function testSeedFromConfigSeedsMultipleRules(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'high_failure_rate' => [
                    'condition' => 'failure_rate > 10',
                    'channels' => [],
                ],
                'queue_backup' => [
                    'condition' => 'queue_size > 5000',
                    'channels' => [],
                ],
                'stuck_jobs' => [
                    'condition' => 'stuck_count > 1',
                    'channels' => [],
                ],
            ],
        ];

        $this->repository->shouldReceive('getRulesByType')->times(3)->andReturn([]);
        $this->repository->shouldReceive('storeRule')->times(3);

        $manager = $this->makeManager($config);
        $result = $manager->seedFromConfig();

        $this->assertSame(3, $result);
    }

    public function testSeedFromConfigDefaultsEnabledToTrue(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'high_failure_rate' => [
                    'condition' => 'failure_rate > 10',
                    'channels' => [],
                    // no 'enabled' key - should default to true
                ],
            ],
        ];

        $this->repository->shouldReceive('getRulesByType')->once()->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->enabled === true));

        $manager = $this->makeManager($config);
        $manager->seedFromConfig();
    }

    public function testSeedFromConfigDefaultsWindowAndCooldown(): void
    {
        $config = [
            'channels' => [],
            'rules' => [
                'high_failure_rate' => [
                    'condition' => 'failure_rate > 10',
                    'channels' => [],
                    // no 'window' or 'cooldown' keys
                ],
            ],
        ];

        $this->repository->shouldReceive('getRulesByType')->once()->andReturn([]);

        $this->repository
            ->shouldReceive('storeRule')
            ->once()
            ->with(Mockery::on(static fn(AlertRule $rule): bool => $rule->window === 300 && $rule->cooldown === 300));

        $manager = $this->makeManager($config);
        $manager->seedFromConfig();
    }

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
}
