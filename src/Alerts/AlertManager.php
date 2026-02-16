<?php

declare(strict_types=1);

namespace Station\Alerts;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Station\Alerts\Evaluators\AlertEvaluatorInterface;
use Station\Alerts\Notifications\StationAlertNotification;
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
use Throwable;

final class AlertManager
{
    /** @var array<string, AlertEvaluatorInterface> */
    private array $evaluators = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly AlertRepositoryInterface $repository,
        private readonly AlertChannelRepositoryInterface $channelRepository,
        private readonly Dispatcher $events,
        private readonly array $config = [],
    ) {}

    public function registerEvaluator(AlertType $type, AlertEvaluatorInterface $evaluator): void
    {
        $this->evaluators[$type->value] = $evaluator;
    }

    /**
     * Main evaluation tick: iterate enabled rules, skip cooldowns, fire alerts.
     *
     * @return array<int, AlertRecord>
     */
    public function evaluate(): array
    {
        if (!($this->config['enabled'] ?? false)) {
            return [];
        }

        $triggered = [];

        foreach ($this->repository->getEnabledRules() as $rule) {
            if ($rule->isInCooldown()) {
                continue;
            }

            $evaluator = $this->evaluators[$rule->type->value] ?? null;

            if ($evaluator === null) {
                continue;
            }

            try {
                $evaluation = $evaluator->evaluate($rule);

                if ($evaluation !== null) {
                    $triggered[] = $this->fire($rule, $evaluation);
                }
            } catch (Throwable $e) {
                logger()->debug('Station: Alert evaluation failed', [
                    'rule' => $rule->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $triggered;
    }

    /**
     * Evaluate rules for a single alert type (used by reactive event listeners).
     */
    public function evaluateType(AlertType $type): ?AlertRecord
    {
        if (!($this->config['enabled'] ?? false)) {
            return null;
        }

        $evaluator = $this->evaluators[$type->value] ?? null;

        if ($evaluator === null) {
            return null;
        }

        foreach ($this->repository->getRulesByType($type) as $rule) {
            if (!$rule->enabled || $rule->isInCooldown()) {
                continue;
            }

            try {
                $evaluation = $evaluator->evaluate($rule);

                if ($evaluation !== null) {
                    return $this->fire($rule, $evaluation);
                }
            } catch (Throwable $e) {
                logger()->debug('Station: Reactive alert evaluation failed', [
                    'type' => $type->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Fire an alert: persist record, send notification, dispatch event.
     */
    public function fire(AlertRule $rule, AlertEvaluation $evaluation): AlertRecord
    {
        $channels = $this->channelRepository->findMany($rule->channel_ids);
        $enabledChannels = array_filter($channels, static fn(AlertChannel $ch): bool => $ch->enabled);
        $channelNames = array_map(static fn(AlertChannel $ch): string => $ch->name, $enabledChannels);

        $record = new AlertRecord(
            id: null,
            rule_id: $rule->id,
            rule_name: $rule->name,
            type: $rule->type,
            severity: $evaluation->severity,
            message: $evaluation->message,
            context: $evaluation->context,
            channels_notified: array_values($channelNames),
        );

        $recordId = $this->repository->storeRecord($record);
        $this->repository->markTriggered($rule->id);

        $record = new AlertRecord(
            id: $recordId,
            rule_id: $record->rule_id,
            rule_name: $record->rule_name,
            type: $record->type,
            severity: $record->severity,
            message: $record->message,
            context: $record->context,
            channels_notified: $record->channels_notified,
            created_at: now()->toDateTimeString(),
        );

        $this->sendToChannels($enabledChannels, $record);

        $this->events->dispatch(new AlertTriggered($record));

        return $record;
    }

    /**
     * Send a test notification for a specific channel.
     */
    public function testChannel(string $channelId): bool
    {
        $channel = $this->channelRepository->find($channelId);

        if ($channel === null) {
            return false;
        }

        $record = new AlertRecord(
            id: null,
            rule_id: '',
            rule_name: 'Channel Test',
            type: AlertType::HighFailureRate,
            severity: AlertSeverity::Info,
            message: "Test notification for channel: {$channel->name}",
            context: ['test' => true],
            channels_notified: [$channel->name],
        );

        $this->sendToChannels([$channel], $record);

        return true;
    }

    /**
     * Seed alert channels and rules from config.
     */
    public function seedFromConfig(): int
    {
        $seeded = 0;

        // Seed channels first (upsert by name)
        $channelConfigs = $this->config['channels'] ?? [];
        $channelNameToId = [];

        foreach ($channelConfigs as $channelConfig) {
            $name = $channelConfig['name'] ?? '';

            if ($name === '') {
                continue;
            }

            $existing = $this->channelRepository->findByName($name);

            if ($existing !== null) {
                $channelNameToId[$name] = $existing->id;

                continue;
            }

            $type = AlertChannelType::tryFrom($channelConfig['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $id = (string) Str::uuid7();
            $channel = new AlertChannel(
                id: $id,
                name: $name,
                type: $type,
                enabled: true,
                config: $channelConfig['config'] ?? [],
            );

            $this->channelRepository->store($channel);
            $channelNameToId[$name] = $id;
        }

        // Seed rules (resolve channel names to IDs)
        $rules = $this->config['rules'] ?? [];

        foreach ($rules as $key => $ruleConfig) {
            $type = AlertType::tryFrom($key);

            if ($type === null) {
                continue;
            }

            // Resolve channel names to IDs
            $ruleChannelNames = $ruleConfig['channels'] ?? [];
            $resolvedIds = [];

            foreach ($ruleChannelNames as $channelName) {
                if (isset($channelNameToId[$channelName])) {
                    $resolvedIds[] = $channelNameToId[$channelName];
                }
            }

            $existing = $this->repository->getRulesByType($type);

            if ($existing !== []) {
                // Update existing rules' channel_ids to resolved UUIDs
                foreach ($existing as $existingRule) {
                    if ($existingRule->source === 'config') {
                        $this->repository->updateRule($existingRule->id, ['channel_ids' => $resolvedIds]);
                    }
                }

                continue;
            }

            $condition = $this->parseConditionString($ruleConfig['condition'] ?? '', $key);

            $rule = new AlertRule(
                id: (string) Str::uuid7(),
                name: $type->label(),
                type: $type,
                enabled: (bool) ($ruleConfig['enabled'] ?? true),
                condition: $condition,
                window: (int) ($ruleConfig['window'] ?? 300),
                channel_ids: $resolvedIds,
                cooldown: (int) ($ruleConfig['cooldown'] ?? 300),
                metadata: [],
                source: 'config',
            );

            $this->repository->storeRule($rule);
            $seeded++;
        }

        return $seeded;
    }

    // ---- CRUD proxies ----

    public function createRule(AlertRule $rule): void
    {
        $this->repository->storeRule($rule);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRule(string $id, array $data): void
    {
        $this->repository->updateRule($id, $data);
    }

    public function deleteRule(string $id): void
    {
        $this->repository->deleteRule($id);
    }

    /**
     * @return array<int, AlertRule>
     */
    public function getRules(): array
    {
        return $this->repository->getAllRules();
    }

    public function getRule(string $id): ?AlertRule
    {
        return $this->repository->findRule($id);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function getHistory(array $filters = [], int $page = 1, int $perPage = 25): PaginatedResult
    {
        return $this->repository->paginateHistory($filters, $page, $perPage);
    }

    public function resolveAlert(int $id): void
    {
        $this->repository->resolveRecord($id);
    }

    public function toggleRule(string $id): ?AlertRule
    {
        $rule = $this->repository->findRule($id);

        if ($rule === null) {
            return null;
        }

        $this->repository->updateRule($id, ['enabled' => !$rule->enabled]);

        return $this->repository->findRule($id);
    }

    /**
     * Send a test notification for a rule.
     */
    public function testRule(string $id): ?AlertRecord
    {
        $rule = $this->repository->findRule($id);

        if ($rule === null) {
            return null;
        }

        $evaluation = new AlertEvaluation(
            message: "Test alert for rule: {$rule->name}",
            severity: $rule->type === AlertType::WorkerDown
                ? AlertSeverity::Warning
                : AlertSeverity::Info,
            context: ['test' => true],
        );

        return $this->fire($rule, $evaluation);
    }

    public function pruneHistory(int $days = 30): int
    {
        return $this->repository->pruneHistory($days);
    }

    /**
     * Send notifications to each channel individually.
     *
     * Each channel gets its own notification to avoid the Notification::routes()
     * key-collision problem when multiple channels share the same driver type
     * (e.g., two Slack webhooks).
     *
     * @param array<int, AlertChannel> $channels
     */
    private function sendToChannels(array $channels, AlertRecord $record): void
    {
        foreach ($channels as $channel) {
            try {
                $route = $this->channelToRoute($channel);

                if ($route === []) {
                    continue;
                }

                $notifiable = Notification::routes($route);
                $notifiable->notify(new StationAlertNotification($record, $channel->type));
            } catch (Throwable $e) {
                logger()->debug('Station: Failed to send alert', [
                    'channel' => $channel->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Map an AlertChannel to the notification route array.
     *
     * @return array<string, mixed>
     */
    private function channelToRoute(AlertChannel $channel): array
    {
        return match ($channel->type) {
            AlertChannelType::Slack => ['slack' => $channel->config['webhook_url'] ?? ''],
            AlertChannelType::Discord => ['discord' => $channel->config['webhook_url'] ?? ''],
            AlertChannelType::Teams => ['teams' => $channel->config['webhook_url'] ?? ''],
            AlertChannelType::GoogleChat => ['google-chat' => $channel->config['webhook_url'] ?? ''],
            AlertChannelType::Webhook => ['station-webhook' => [
                'url' => $channel->config['url'] ?? '',
                'secret' => $channel->config['secret'] ?? null,
            ]],
            AlertChannelType::Email => ['mail' => $channel->config['recipients'] ?? []],
            AlertChannelType::Log => ['station-log' => $channel->config['channel'] ?? 'station-alerts'],
        };
    }

    /**
     * Parse config condition strings like "failure_rate > 10" into structured arrays.
     *
     * @return array<string, mixed>
     */
    private function parseConditionString(string $condition, string $ruleKey): array
    {
        return match ($ruleKey) {
            'high_failure_rate' => $this->parseThresholdCondition($condition, 'threshold', 10),
            'queue_backup' => $this->parseThresholdCondition($condition, 'threshold', 10000),
            'stuck_jobs' => $this->parseThresholdCondition($condition, 'threshold', 1),
            'worker_down' => $this->parseMinWorkersCondition($condition),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseThresholdCondition(string $condition, string $key, int|float $default): array
    {
        if (preg_match('/>\s*([\d.]+)/', $condition, $matches)) {
            return [$key => (float) $matches[1]];
        }

        return [$key => $default];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMinWorkersCondition(string $condition): array
    {
        if (preg_match('/<\s*(\d+)/', $condition, $matches)) {
            return ['min_workers' => (int) $matches[1]];
        }

        return ['min_workers' => 1];
    }
}
