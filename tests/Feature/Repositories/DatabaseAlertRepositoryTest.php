<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Repositories;

use Carbon\CarbonImmutable;
use Orchestra\Testbench\TestCase;
use Station\DTOs\AlertChannel;
use Station\DTOs\AlertRecord;
use Station\DTOs\AlertRule;
use Station\DTOs\PaginatedResult;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;
use Station\Repositories\DatabaseAlertChannelRepository;
use Station\Repositories\DatabaseAlertRepository;
use Station\StationServiceProvider;

class DatabaseAlertRepositoryTest extends TestCase
{
    private DatabaseAlertRepository $alertRepo;

    private DatabaseAlertChannelRepository $channelRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->app['db'];

        $db->statement('CREATE TABLE IF NOT EXISTS station_alert_rules (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            enabled BOOLEAN DEFAULT 1,
            condition TEXT NOT NULL DEFAULT "{}",
            window INTEGER DEFAULT 300,
            channel_ids TEXT NOT NULL DEFAULT "[]",
            cooldown INTEGER DEFAULT 300,
            metadata TEXT NOT NULL DEFAULT "{}",
            source VARCHAR(50) DEFAULT "config",
            last_triggered_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_alert_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rule_id VARCHAR(36) NOT NULL,
            rule_name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context TEXT NOT NULL DEFAULT "{}",
            channels_notified TEXT NOT NULL DEFAULT "[]",
            resolved BOOLEAN DEFAULT 0,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $db->statement('CREATE TABLE IF NOT EXISTS station_alert_channels (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            enabled BOOLEAN DEFAULT 1,
            config TEXT NOT NULL DEFAULT "{}",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $connection = $db->connection();

        $this->alertRepo = new DatabaseAlertRepository($connection);
        $this->channelRepo = new DatabaseAlertChannelRepository($connection);
    }

    // ---------------------------------------------------------------
    // DatabaseAlertRepository - Rule CRUD
    // ---------------------------------------------------------------

    public function testAlertRepoStoreRuleInsertsRecord(): void
    {
        $rule = $this->makeRule('rule-1', 'High Failure Rate Alert');

        $this->alertRepo->storeRule($rule);

        $this->assertDatabaseHas('station_alert_rules', [
            'id' => 'rule-1',
            'name' => 'High Failure Rate Alert',
            'type' => 'high_failure_rate',
            'enabled' => 1,
            'source' => 'config',
        ]);
    }

    public function testAlertRepoFindRuleReturnsRuleWhenExists(): void
    {
        $rule = $this->makeRule('rule-2', 'Queue Backup Alert', AlertType::QueueBackup);
        $this->alertRepo->storeRule($rule);

        $found = $this->alertRepo->findRule('rule-2');

        $this->assertNotNull($found);
        $this->assertSame('rule-2', $found->id);
        $this->assertSame('Queue Backup Alert', $found->name);
        $this->assertSame(AlertType::QueueBackup, $found->type);
        $this->assertTrue($found->enabled);
        $this->assertSame(['threshold' => 100], $found->condition);
        $this->assertSame(['chan-1'], $found->channel_ids);
        $this->assertSame(300, $found->cooldown);
    }

    public function testAlertRepoFindRuleReturnsNullWhenNotFound(): void
    {
        $result = $this->alertRepo->findRule('nonexistent');

        $this->assertNull($result);
    }

    public function testAlertRepoUpdateRuleUpdatesScalarFields(): void
    {
        $this->alertRepo->storeRule($this->makeRule('rule-3', 'Original Name'));

        $this->alertRepo->updateRule('rule-3', [
            'name' => 'Updated Name',
            'enabled' => false,
            'window' => 600,
            'cooldown' => 900,
            'source' => 'dashboard',
        ]);

        $updated = $this->alertRepo->findRule('rule-3');

        $this->assertSame('Updated Name', $updated->name);
        $this->assertFalse($updated->enabled);
        $this->assertSame(600, $updated->window);
        $this->assertSame(900, $updated->cooldown);
        $this->assertSame('dashboard', $updated->source);
    }

    public function testAlertRepoUpdateRuleJsonEncodesArrayFields(): void
    {
        $this->alertRepo->storeRule($this->makeRule('rule-4', 'JSON Test'));

        $this->alertRepo->updateRule('rule-4', [
            'condition' => ['threshold' => 50, 'operator' => '>='],
            'channel_ids' => ['chan-a', 'chan-b'],
            'metadata' => ['description' => 'updated'],
        ]);

        $updated = $this->alertRepo->findRule('rule-4');

        $this->assertSame(['threshold' => 50, 'operator' => '>='], $updated->condition);
        $this->assertSame(['chan-a', 'chan-b'], $updated->channel_ids);
        $this->assertSame(['description' => 'updated'], $updated->metadata);
    }

    public function testAlertRepoUpdateRuleHandlesAlertTypeEnum(): void
    {
        $this->alertRepo->storeRule($this->makeRule('rule-5', 'Type Test'));

        $this->alertRepo->updateRule('rule-5', [
            'type' => AlertType::WorkerDown,
        ]);

        $updated = $this->alertRepo->findRule('rule-5');

        $this->assertSame(AlertType::WorkerDown, $updated->type);
    }

    public function testAlertRepoDeleteRuleRemovesRecord(): void
    {
        $this->alertRepo->storeRule($this->makeRule('rule-6', 'To Delete'));

        $this->alertRepo->deleteRule('rule-6');

        $this->assertNull($this->alertRepo->findRule('rule-6'));
        $this->assertDatabaseMissing('station_alert_rules', ['id' => 'rule-6']);
    }

    public function testAlertRepoGetEnabledRulesReturnsOnlyEnabled(): void
    {
        $this->alertRepo->storeRule($this->makeRule('enabled-1', 'Enabled One', enabled: true));
        $this->alertRepo->storeRule($this->makeRule('disabled-1', 'Disabled One', enabled: false));
        $this->alertRepo->storeRule($this->makeRule('enabled-2', 'Enabled Two', enabled: true));

        $enabled = $this->alertRepo->getEnabledRules();

        $this->assertCount(2, $enabled);

        $ids = array_map(static fn(AlertRule $r): string => $r->id, $enabled);
        $this->assertContains('enabled-1', $ids);
        $this->assertContains('enabled-2', $ids);
        $this->assertNotContains('disabled-1', $ids);
    }

    public function testAlertRepoGetAllRulesOrdersByCreatedAtDesc(): void
    {
        // Insert with controlled timestamps to verify ordering
        $db = $this->app['db'];
        $db->table('station_alert_rules')->insert([
            'id' => 'oldest',
            'name' => 'Oldest Rule',
            'type' => 'stuck_jobs',
            'enabled' => 1,
            'condition' => '{}',
            'channel_ids' => '[]',
            'metadata' => '{}',
            'source' => 'config',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ]);
        $db->table('station_alert_rules')->insert([
            'id' => 'newest',
            'name' => 'Newest Rule',
            'type' => 'stuck_jobs',
            'enabled' => 1,
            'condition' => '{}',
            'channel_ids' => '[]',
            'metadata' => '{}',
            'source' => 'config',
            'created_at' => '2025-06-01 00:00:00',
            'updated_at' => '2025-06-01 00:00:00',
        ]);

        $all = $this->alertRepo->getAllRules();

        $this->assertCount(2, $all);
        $this->assertSame('newest', $all[0]->id);
        $this->assertSame('oldest', $all[1]->id);
    }

    public function testAlertRepoMarkTriggeredSetsTimestamp(): void
    {
        $this->alertRepo->storeRule($this->makeRule('rule-trigger', 'Trigger Test'));

        $before = $this->alertRepo->findRule('rule-trigger');
        $this->assertNull($before->last_triggered_at);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-06-15 12:30:00'));
        $this->alertRepo->markTriggered('rule-trigger');
        CarbonImmutable::setTestNow();

        $after = $this->alertRepo->findRule('rule-trigger');
        $this->assertNotNull($after->last_triggered_at);
        $this->assertSame('2025-06-15 12:30:00', $after->last_triggered_at);
    }

    public function testAlertRepoGetRulesByTypeReturnsEnabledRulesOfType(): void
    {
        $this->alertRepo->storeRule($this->makeRule('r-hfr-1', 'HFR Enabled', AlertType::HighFailureRate, true));
        $this->alertRepo->storeRule($this->makeRule('r-hfr-2', 'HFR Disabled', AlertType::HighFailureRate, false));
        $this->alertRepo->storeRule($this->makeRule('r-qb-1', 'QB Enabled', AlertType::QueueBackup, true));

        $rules = $this->alertRepo->getRulesByType(AlertType::HighFailureRate);

        $this->assertCount(1, $rules);
        $this->assertSame('r-hfr-1', $rules[0]->id);
    }

    // ---------------------------------------------------------------
    // DatabaseAlertRepository - Record (History) CRUD
    // ---------------------------------------------------------------

    public function testAlertRepoStoreRecordInsertsAndReturnsId(): void
    {
        $record = $this->makeRecord('rule-x', 'Test Rule');

        $id = $this->alertRepo->storeRecord($record);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $this->assertDatabaseHas('station_alert_history', [
            'id' => $id,
            'rule_id' => 'rule-x',
            'rule_name' => 'Test Rule',
            'type' => 'high_failure_rate',
            'severity' => 'critical',
            'resolved' => 0,
        ]);
    }

    public function testAlertRepoFindRecordReturnsRecordWhenExists(): void
    {
        $record = $this->makeRecord('rule-y', 'Find Test', AlertType::StuckJobs, AlertSeverity::Warning);
        $id = $this->alertRepo->storeRecord($record);

        $found = $this->alertRepo->findRecord($id);

        $this->assertNotNull($found);
        $this->assertSame($id, $found->id);
        $this->assertSame('rule-y', $found->rule_id);
        $this->assertSame('Find Test', $found->rule_name);
        $this->assertSame(AlertType::StuckJobs, $found->type);
        $this->assertSame(AlertSeverity::Warning, $found->severity);
        $this->assertSame('Alert triggered', $found->message);
        $this->assertFalse($found->resolved);
        $this->assertNull($found->resolved_at);
    }

    public function testAlertRepoFindRecordReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->alertRepo->findRecord(99999));
    }

    public function testAlertRepoResolveRecordSetsResolvedAndTimestamp(): void
    {
        $id = $this->alertRepo->storeRecord($this->makeRecord('rule-z', 'Resolve Test'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-08-01 10:00:00'));
        $this->alertRepo->resolveRecord($id);
        CarbonImmutable::setTestNow();

        $resolved = $this->alertRepo->findRecord($id);

        $this->assertTrue($resolved->resolved);
        $this->assertSame('2025-08-01 10:00:00', $resolved->resolved_at);
    }

    public function testAlertRepoPaginateHistoryReturnsCorrectStructure(): void
    {
        // Insert 5 records
        for ($i = 1; $i <= 5; $i++) {
            $this->alertRepo->storeRecord($this->makeRecord("rule-{$i}", "Rule {$i}"));
        }

        $result = $this->alertRepo->paginateHistory([], 1, 3);

        $this->assertInstanceOf(PaginatedResult::class, $result);
        $this->assertSame(5, $result->total);
        $this->assertSame(3, $result->per_page);
        $this->assertSame(1, $result->current_page);
        $this->assertSame(2, $result->last_page);
        $this->assertSame(1, $result->from);
        $this->assertSame(3, $result->to);
        $this->assertCount(3, $result->data);
    }

    public function testAlertRepoPaginateHistoryFiltersByType(): void
    {
        $this->alertRepo->storeRecord($this->makeRecord('r1', 'R1', AlertType::HighFailureRate));
        $this->alertRepo->storeRecord($this->makeRecord('r2', 'R2', AlertType::QueueBackup));
        $this->alertRepo->storeRecord($this->makeRecord('r3', 'R3', AlertType::HighFailureRate));

        $result = $this->alertRepo->paginateHistory(['type' => 'high_failure_rate']);

        $this->assertSame(2, $result->total);
    }

    public function testAlertRepoPaginateHistoryFiltersBySeverity(): void
    {
        $this->alertRepo->storeRecord($this->makeRecord('r1', 'R1', severity: AlertSeverity::Critical));
        $this->alertRepo->storeRecord($this->makeRecord('r2', 'R2', severity: AlertSeverity::Info));

        $result = $this->alertRepo->paginateHistory(['severity' => 'critical']);

        $this->assertSame(1, $result->total);
    }

    public function testAlertRepoPaginateHistoryFiltersByResolved(): void
    {
        $id1 = $this->alertRepo->storeRecord($this->makeRecord('r1', 'R1'));
        $this->alertRepo->storeRecord($this->makeRecord('r2', 'R2'));
        $this->alertRepo->resolveRecord($id1);

        $unresolvedResult = $this->alertRepo->paginateHistory(['resolved' => false]);
        $resolvedResult = $this->alertRepo->paginateHistory(['resolved' => true]);

        $this->assertSame(1, $unresolvedResult->total);
        $this->assertSame(1, $resolvedResult->total);
    }

    public function testAlertRepoPaginateHistoryFiltersByRuleId(): void
    {
        $this->alertRepo->storeRecord($this->makeRecord('rule-a', 'Rule A'));
        $this->alertRepo->storeRecord($this->makeRecord('rule-b', 'Rule B'));
        $this->alertRepo->storeRecord($this->makeRecord('rule-a', 'Rule A'));

        $result = $this->alertRepo->paginateHistory(['rule_id' => 'rule-a']);

        $this->assertSame(2, $result->total);
    }

    public function testAlertRepoPaginateHistoryEmptyResultSetsNullFromTo(): void
    {
        $result = $this->alertRepo->paginateHistory(['type' => 'stuck_jobs']);

        $this->assertSame(0, $result->total);
        $this->assertSame(1, $result->last_page);
        $this->assertNull($result->from);
        $this->assertNull($result->to);
    }

    public function testAlertRepoPruneHistoryDeletesOldRecords(): void
    {
        $db = $this->app['db'];

        // Insert an old record directly
        $db->table('station_alert_history')->insert([
            'rule_id' => 'old-rule',
            'rule_name' => 'Old Rule',
            'type' => 'stuck_jobs',
            'severity' => 'info',
            'message' => 'Old alert',
            'context' => '{}',
            'channels_notified' => '[]',
            'resolved' => 0,
            'created_at' => CarbonImmutable::now()->subDays(60)->toDateTimeString(),
        ]);

        // Insert a recent record
        $this->alertRepo->storeRecord($this->makeRecord('recent-rule', 'Recent'));

        $deleted = $this->alertRepo->pruneHistory(30);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('station_alert_history', ['rule_id' => 'old-rule']);
        $this->assertDatabaseHas('station_alert_history', ['rule_id' => 'recent-rule']);
    }

    public function testAlertRepoPruneHistoryReturnsZeroWhenNothingToDelete(): void
    {
        $this->alertRepo->storeRecord($this->makeRecord('recent', 'Recent'));

        $deleted = $this->alertRepo->pruneHistory(30);

        $this->assertSame(0, $deleted);
    }

    // ---------------------------------------------------------------
    // DatabaseAlertChannelRepository
    // ---------------------------------------------------------------

    public function testChannelRepoStoreInsertsChannel(): void
    {
        $channel = $this->makeChannel('chan-1', 'Slack Alerts', AlertChannelType::Slack);

        $this->channelRepo->store($channel);

        $this->assertDatabaseHas('station_alert_channels', [
            'id' => 'chan-1',
            'name' => 'Slack Alerts',
            'type' => 'slack',
            'enabled' => 1,
        ]);
    }

    public function testChannelRepoFindReturnsChannelWhenExists(): void
    {
        $channel = $this->makeChannel('chan-2', 'Email Channel', AlertChannelType::Email, config: ['to' => 'admin@example.com']);
        $this->channelRepo->store($channel);

        $found = $this->channelRepo->find('chan-2');

        $this->assertNotNull($found);
        $this->assertSame('chan-2', $found->id);
        $this->assertSame('Email Channel', $found->name);
        $this->assertSame(AlertChannelType::Email, $found->type);
        $this->assertTrue($found->enabled);
        $this->assertSame(['to' => 'admin@example.com'], $found->config);
    }

    public function testChannelRepoFindReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->channelRepo->find('nonexistent'));
    }

    public function testChannelRepoFindManyReturnsMatchingChannels(): void
    {
        $this->channelRepo->store($this->makeChannel('ch-a', 'Channel A'));
        $this->channelRepo->store($this->makeChannel('ch-b', 'Channel B'));
        $this->channelRepo->store($this->makeChannel('ch-c', 'Channel C'));

        $found = $this->channelRepo->findMany(['ch-a', 'ch-c']);

        $this->assertCount(2, $found);

        $ids = array_map(static fn(AlertChannel $c): string => $c->id, $found);
        $this->assertContains('ch-a', $ids);
        $this->assertContains('ch-c', $ids);
    }

    public function testChannelRepoFindManyReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->channelRepo->findMany([]));
    }

    public function testChannelRepoGetAllReturnsAllOrderedByCreatedAtDesc(): void
    {
        $db = $this->app['db'];

        $db->table('station_alert_channels')->insert([
            'id' => 'oldest-ch',
            'name' => 'Oldest',
            'type' => 'log',
            'enabled' => 1,
            'config' => '{}',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ]);
        $db->table('station_alert_channels')->insert([
            'id' => 'newest-ch',
            'name' => 'Newest',
            'type' => 'slack',
            'enabled' => 1,
            'config' => '{}',
            'created_at' => '2025-06-01 00:00:00',
            'updated_at' => '2025-06-01 00:00:00',
        ]);

        $all = $this->channelRepo->getAll();

        $this->assertCount(2, $all);
        $this->assertSame('newest-ch', $all[0]->id);
        $this->assertSame('oldest-ch', $all[1]->id);
    }

    public function testChannelRepoUpdateModifiesFields(): void
    {
        $this->channelRepo->store($this->makeChannel('ch-upd', 'Original', AlertChannelType::Slack));

        $this->channelRepo->update('ch-upd', [
            'name' => 'Updated Channel',
            'enabled' => false,
            'type' => AlertChannelType::Discord,
            'config' => ['webhook_url' => 'https://discord.com/webhook'],
        ]);

        $updated = $this->channelRepo->find('ch-upd');

        $this->assertSame('Updated Channel', $updated->name);
        $this->assertFalse($updated->enabled);
        $this->assertSame(AlertChannelType::Discord, $updated->type);
        $this->assertSame(['webhook_url' => 'https://discord.com/webhook'], $updated->config);
    }

    public function testChannelRepoUpdateHandlesStringType(): void
    {
        $this->channelRepo->store($this->makeChannel('ch-str', 'String Type Test'));

        $this->channelRepo->update('ch-str', [
            'type' => 'webhook',
        ]);

        $updated = $this->channelRepo->find('ch-str');

        $this->assertSame(AlertChannelType::Webhook, $updated->type);
    }

    public function testChannelRepoDeleteRemovesChannel(): void
    {
        $this->channelRepo->store($this->makeChannel('ch-del', 'To Delete'));

        $this->channelRepo->delete('ch-del');

        $this->assertNull($this->channelRepo->find('ch-del'));
        $this->assertDatabaseMissing('station_alert_channels', ['id' => 'ch-del']);
    }

    public function testChannelRepoExistsByIdReturnsTrueWhenExists(): void
    {
        $this->channelRepo->store($this->makeChannel('ch-exists', 'Exists'));

        $this->assertTrue($this->channelRepo->existsById('ch-exists'));
    }

    public function testChannelRepoExistsByIdReturnsFalseWhenMissing(): void
    {
        $this->assertFalse($this->channelRepo->existsById('nonexistent'));
    }

    public function testChannelRepoFindByNameReturnsChannelWhenExists(): void
    {
        $this->channelRepo->store($this->makeChannel('ch-name', 'Unique Channel Name'));

        $found = $this->channelRepo->findByName('Unique Channel Name');

        $this->assertNotNull($found);
        $this->assertSame('ch-name', $found->id);
        $this->assertSame('Unique Channel Name', $found->name);
    }

    public function testChannelRepoFindByNameReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->channelRepo->findByName('No Such Channel'));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [StationServiceProvider::class];
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    private function makeRule(
        string $id = 'rule-1',
        string $name = 'Test Rule',
        AlertType $type = AlertType::HighFailureRate,
        bool $enabled = true,
    ): AlertRule {
        return new AlertRule(
            id: $id,
            name: $name,
            type: $type,
            enabled: $enabled,
            condition: ['threshold' => 100],
            window: 300,
            channel_ids: ['chan-1'],
            cooldown: 300,
            metadata: ['description' => 'test rule'],
            source: 'config',
        );
    }

    private function makeRecord(
        string $ruleId = 'rule-1',
        string $ruleName = 'Test Rule',
        AlertType $type = AlertType::HighFailureRate,
        AlertSeverity $severity = AlertSeverity::Critical,
    ): AlertRecord {
        return new AlertRecord(
            id: null,
            rule_id: $ruleId,
            rule_name: $ruleName,
            type: $type,
            severity: $severity,
            message: 'Alert triggered',
            context: ['queue' => 'default', 'rate' => 0.15],
            channels_notified: ['chan-1'],
        );
    }

    private function makeChannel(
        string $id = 'chan-1',
        string $name = 'Test Channel',
        AlertChannelType $type = AlertChannelType::Slack,
        bool $enabled = true,
        array $config = [],
    ): AlertChannel {
        return new AlertChannel(
            id: $id,
            name: $name,
            type: $type,
            enabled: $enabled,
            config: $config ?: ['webhook_url' => 'https://hooks.slack.com/test'],
        );
    }
}
