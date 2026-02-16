<?php

declare(strict_types=1);

namespace Station\Alerts\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Station\Contracts\AlertChannelInterface;
use Throwable;

final class StationGoogleChatChannel implements AlertChannelInterface
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        /** @var string|null $webhookUrl */
        $webhookUrl = $notifiable->routeNotificationFor('google-chat');

        if ($webhookUrl === null || $webhookUrl === '') {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toGoogleChat($notifiable); // @phpstan-ignore method.notFound (Laravel notification channel pattern)

        try {
            Http::post($webhookUrl, $payload);
        } catch (Throwable $e) {
            Log::error('Station: Failed to send Google Chat alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
