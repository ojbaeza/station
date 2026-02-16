<?php

declare(strict_types=1);

namespace Station\Alerts\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Station\Contracts\AlertChannelInterface;
use Throwable;

final class StationDiscordChannel implements AlertChannelInterface
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        /** @var string|null $webhookUrl */
        $webhookUrl = $notifiable->routeNotificationFor('discord');

        if ($webhookUrl === null || $webhookUrl === '') {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toDiscord($notifiable); // @phpstan-ignore method.notFound (Laravel notification channel pattern)

        try {
            Http::post($webhookUrl, $payload);
        } catch (Throwable $e) {
            Log::error('Station: Failed to send Discord alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
