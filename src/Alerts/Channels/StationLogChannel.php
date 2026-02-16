<?php

declare(strict_types=1);

namespace Station\Alerts\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Station\Contracts\AlertChannelInterface;
use Station\Enums\AlertSeverity;

final class StationLogChannel implements AlertChannelInterface
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        /** @var string|null $logChannel */
        $logChannel = $notifiable->routeNotificationFor('station-log');

        /** @var array{message: string, context: array<string, mixed>} $data */
        $data = $notification->toLog($notifiable); // @phpstan-ignore method.notFound (Laravel notification channel pattern)

        $severity = $data['context']['severity'] ?? 'warning';
        $level = match ($severity) {
            AlertSeverity::Critical->value => 'critical',
            AlertSeverity::Warning->value => 'warning',
            default => 'info',
        };

        $logger = $logChannel !== null && $logChannel !== '' && config("logging.channels.{$logChannel}") !== null
            ? Log::channel($logChannel)
            : Log::getFacadeRoot();
        $logger->log($level, $data['message'], $data['context']);
    }
}
