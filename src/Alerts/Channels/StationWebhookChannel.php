<?php

declare(strict_types=1);

namespace Station\Alerts\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Station\Contracts\AlertChannelInterface;
use Throwable;

final class StationWebhookChannel implements AlertChannelInterface
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        /** @var array{url: ?string, secret: ?string}|null $route */
        $route = $notifiable->routeNotificationFor('station-webhook');

        $url = $route['url'] ?? null;

        if ($url === null || $url === '') {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toWebhook($notifiable); // @phpstan-ignore method.notFound (Laravel notification channel pattern)

        try {
            $request = Http::asJson();

            $secret = $route['secret'] ?? null;

            if ($secret !== null && $secret !== '') {
                $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
                $request = $request->withHeaders([
                    'X-Station-Signature' => $signature,
                ]);
            }

            $request->post($url, $payload);
        } catch (Throwable $e) {
            Log::error('Station: Failed to send webhook alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
