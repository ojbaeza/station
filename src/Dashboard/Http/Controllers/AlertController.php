<?php

declare(strict_types=1);

namespace Station\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Station\Alerts\AlertManager;
use Station\Contracts\AlertChannelRepositoryInterface;
use Station\DTOs\AlertChannel;
use Station\DTOs\AlertRule;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertType;

final class AlertController extends Controller
{
    public function __construct(
        private readonly AlertManager $alertManager,
        private readonly AlertChannelRepositoryInterface $channelRepository,
    ) {}

    /**
     * Display the alerts list (history).
     */
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        $filters = array_filter([
            'type' => $request->get('type'),
            'severity' => $request->get('severity'),
            'resolved' => $request->has('resolved') ? (bool) $request->get('resolved') : null,
        ], static fn($v): bool => $v !== null);

        return Inertia::render('Station/AlertHistory', [
            'history' => $this->alertManager->getHistory($filters, $page, $perPage),
            'alertTypes' => AlertType::labels(),
            'filters' => $filters,
        ]);
    }

    /**
     * Display the alert rules page.
     */
    public function rulesPage(): Response
    {
        return Inertia::render('Station/Alerts', [
            'rules' => $this->alertManager->getRules(),
            'alertTypes' => AlertType::labels(),
            'channels' => $this->channelRepository->getAll(),
        ]);
    }

    /**
     * Display the alert channels management page.
     */
    public function channelsPage(): Response
    {
        return Inertia::render('Station/AlertChannels', [
            'channels' => $this->channelRepository->getAll(),
            'channelTypes' => AlertChannelType::labels(),
        ]);
    }

    // ---- Channel API endpoints ----

    /**
     * List all alert channels.
     */
    public function channels(): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->channelRepository->getAll(),
        ]);
    }

    /**
     * Create a new alert channel.
     */
    public function storeChannel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', AlertChannelType::values())],
            'enabled' => ['boolean'],
            'config' => ['required', 'array'],
        ], [
            'config.required' => 'Please fill in the channel settings (e.g. Webhook URL).',
        ]);

        $channel = new AlertChannel(
            id: (string) Str::uuid7(),
            name: $validated['name'],
            type: AlertChannelType::from($validated['type']),
            enabled: (bool) ($validated['enabled'] ?? true),
            config: $validated['config'],
        );

        $this->channelRepository->store($channel);

        return new JsonResponse(['data' => $channel], 201);
    }

    /**
     * Update an existing alert channel.
     */
    public function updateChannel(Request $request, string $id): JsonResponse
    {
        $existing = $this->channelRepository->find($id);

        if ($existing === null) {
            return new JsonResponse(['error' => 'Channel not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['string', 'max:255'],
            'type' => ['string', 'in:' . implode(',', AlertChannelType::values())],
            'enabled' => ['boolean'],
            'config' => ['array'],
        ]);

        $this->channelRepository->update($id, $validated);

        return new JsonResponse(['data' => $this->channelRepository->find($id)]);
    }

    /**
     * Delete an alert channel.
     */
    public function destroyChannel(string $id): JsonResponse
    {
        $existing = $this->channelRepository->find($id);

        if ($existing === null) {
            return new JsonResponse(['error' => 'Channel not found'], 404);
        }

        // Check if any rules reference this channel
        $rules = $this->alertManager->getRules();

        foreach ($rules as $rule) {
            if (\in_array($id, $rule->channel_ids, true)) {
                return new JsonResponse([
                    'error' => "Channel is referenced by rule \"{$rule->name}\". Remove it from the rule first.",
                ], 409);
            }
        }

        $this->channelRepository->delete($id);

        return new JsonResponse(null, 204);
    }

    /**
     * Send a test notification through a channel.
     */
    public function testChannel(string $id): JsonResponse
    {
        $success = $this->alertManager->testChannel($id);

        if (!$success) {
            return new JsonResponse(['error' => 'Channel not found'], 404);
        }

        return new JsonResponse(['message' => 'Test notification sent']);
    }

    // ---- Rule API endpoints ----

    /**
     * List all alert rules.
     */
    public function rules(): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->alertManager->getRules(),
        ]);
    }

    /**
     * Show a single alert rule.
     */
    public function showRule(string $id): JsonResponse
    {
        $rule = $this->alertManager->getRule($id);

        if ($rule === null) {
            return new JsonResponse(['error' => 'Rule not found'], 404);
        }

        return new JsonResponse(['data' => $rule]);
    }

    /**
     * Create a new alert rule.
     */
    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:' . implode(',', AlertType::values())],
            'condition' => ['present', 'array'],
            'channel_ids' => ['required', 'array'],
            'channel_ids.*' => ['string'],
            'window' => ['integer', 'min:60'],
            'cooldown' => ['integer', 'min:60'],
            'enabled' => ['boolean'],
            'metadata' => ['array'],
        ]);

        $rule = new AlertRule(
            id: (string) Str::uuid7(),
            name: $validated['name'],
            type: AlertType::from($validated['type']),
            enabled: (bool) ($validated['enabled'] ?? true),
            condition: $validated['condition'],
            window: (int) ($validated['window'] ?? 300),
            channel_ids: $validated['channel_ids'],
            cooldown: (int) ($validated['cooldown'] ?? 300),
            metadata: $validated['metadata'] ?? [],
            source: 'user',
        );

        $this->alertManager->createRule($rule);

        return new JsonResponse(['data' => $rule], 201);
    }

    /**
     * Update an existing alert rule.
     */
    public function updateRule(Request $request, string $id): JsonResponse
    {
        $existing = $this->alertManager->getRule($id);

        if ($existing === null) {
            return new JsonResponse(['error' => 'Rule not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['string', 'max:255'],
            'type' => ['string', 'in:' . implode(',', AlertType::values())],
            'condition' => ['array'],
            'channel_ids' => ['array'],
            'channel_ids.*' => ['string'],
            'window' => ['integer', 'min:60'],
            'cooldown' => ['integer', 'min:60'],
            'enabled' => ['boolean'],
            'metadata' => ['array'],
        ]);

        $this->alertManager->updateRule($id, $validated);

        return new JsonResponse(['data' => $this->alertManager->getRule($id)]);
    }

    /**
     * Delete an alert rule.
     */
    public function destroyRule(string $id): JsonResponse
    {
        $existing = $this->alertManager->getRule($id);

        if ($existing === null) {
            return new JsonResponse(['error' => 'Rule not found'], 404);
        }

        $this->alertManager->deleteRule($id);

        return new JsonResponse(null, 204);
    }

    /**
     * Toggle an alert rule's enabled status.
     */
    public function toggleRule(string $id): JsonResponse
    {
        $rule = $this->alertManager->toggleRule($id);

        if ($rule === null) {
            return new JsonResponse(['error' => 'Rule not found'], 404);
        }

        return new JsonResponse(['data' => $rule]);
    }

    /**
     * Send a test notification for a rule.
     */
    public function testRule(string $id): JsonResponse
    {
        $record = $this->alertManager->testRule($id);

        if ($record === null) {
            return new JsonResponse(['error' => 'Rule not found'], 404);
        }

        return new JsonResponse(['data' => $record]);
    }

    /**
     * Get paginated alert history.
     */
    public function alertHistory(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        $filters = array_filter([
            'type' => $request->get('type'),
            'severity' => $request->get('severity'),
            'resolved' => $request->has('resolved') ? (bool) $request->get('resolved') : null,
        ], static fn($v): bool => $v !== null);

        return new JsonResponse($this->alertManager->getHistory($filters, $page, $perPage));
    }

    /**
     * Resolve an alert.
     */
    public function resolveAlert(int $id): JsonResponse
    {
        $this->alertManager->resolveAlert($id);

        return new JsonResponse(['message' => 'Alert resolved']);
    }
}
