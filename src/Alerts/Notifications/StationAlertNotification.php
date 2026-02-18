<?php

declare(strict_types=1);

namespace Station\Alerts\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Station\Alerts\Channels\StationDiscordChannel;
use Station\Alerts\Channels\StationEmailChannel;
use Station\Alerts\Channels\StationGoogleChatChannel;
use Station\Alerts\Channels\StationLogChannel;
use Station\Alerts\Channels\StationSlackChannel;
use Station\Alerts\Channels\StationTeamsChannel;
use Station\Alerts\Channels\StationWebhookChannel;
use Station\DTOs\AlertRecord;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertSeverity;

final class StationAlertNotification extends Notification
{
    public function __construct(
        public readonly AlertRecord $alert,
        public readonly AlertChannelType $channelType,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [match ($this->channelType) {
            AlertChannelType::Email => StationEmailChannel::class,
            AlertChannelType::Slack => StationSlackChannel::class,
            AlertChannelType::Log => StationLogChannel::class,
            AlertChannelType::Discord => StationDiscordChannel::class,
            AlertChannelType::Teams => StationTeamsChannel::class,
            AlertChannelType::GoogleChat => StationGoogleChatChannel::class,
            AlertChannelType::Webhook => StationWebhookChannel::class,
        }];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject("[Station] {$this->alert->severity->label()}: {$this->alert->rule_name}")
            ->greeting("Station Alert: {$this->alert->severity->label()}")
            ->line($this->alert->message)
            ->line("**Type:** {$this->alert->type->label()}")
            ->line("**Severity:** {$this->alert->severity->label()}");

        if ($this->alert->context !== []) {
            $mail->line('**Context:**');

            foreach ($this->alert->context as $key => $value) {
                $display = \is_scalar($value) ? (string) $value : json_encode($value);
                $mail->line("- {$key}: {$display}");
            }
        }

        $dashboardPath = config('station.dashboard.path', 'station');
        $dashboardUrl = rtrim(config('app.url', ''), '/') . '/' . $dashboardPath;
        $mail->action('View Dashboard', $dashboardUrl . '/alerts/history');

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSlack(mixed $notifiable): array
    {
        $emoji = match ($this->alert->severity) {
            AlertSeverity::Critical => ':rotating_light:',
            AlertSeverity::Warning => ':warning:',
            default => ':information_source:',
        };

        $color = match ($this->alert->severity) {
            AlertSeverity::Critical => '#dc2626',
            AlertSeverity::Warning => '#f59e0b',
            default => '#3b82f6',
        };

        return [
            'text' => "{$emoji} Station Alert: {$this->alert->rule_name}",
            'attachments' => [
                [
                    'color' => $color,
                    'title' => $this->alert->rule_name,
                    'text' => $this->alert->message,
                    'fields' => [
                        ['title' => 'Type', 'value' => $this->alert->type->label(), 'short' => true],
                        ['title' => 'Severity', 'value' => $this->alert->severity->label(), 'short' => true],
                    ],
                    'footer' => 'Station Queue Monitor',
                    'ts' => time(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLog(mixed $notifiable): array
    {
        return [
            'message' => "[Station Alert] {$this->alert->severity->label()}: {$this->alert->message}",
            'context' => [
                'rule_name' => $this->alert->rule_name,
                'type' => $this->alert->type->value,
                'severity' => $this->alert->severity->value,
                'alert_context' => $this->alert->context,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDiscord(mixed $notifiable): array
    {
        $color = match ($this->alert->severity) {
            AlertSeverity::Critical => 0xdc2626,
            AlertSeverity::Warning => 0xf59e0b,
            default => 0x3b82f6,
        };

        return [
            'content' => "Station Alert: {$this->alert->rule_name}",
            'embeds' => [
                [
                    'title' => $this->alert->rule_name,
                    'description' => $this->alert->message,
                    'color' => $color,
                    'fields' => [
                        ['name' => 'Type', 'value' => $this->alert->type->label(), 'inline' => true],
                        ['name' => 'Severity', 'value' => $this->alert->severity->label(), 'inline' => true],
                    ],
                    'footer' => ['text' => 'Station Queue Monitor'],
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toTeams(mixed $notifiable): array
    {
        return [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => match ($this->alert->severity) {
                AlertSeverity::Critical => 'dc2626',
                AlertSeverity::Warning => 'f59e0b',
                default => '3b82f6',
            },
            'summary' => "Station Alert: {$this->alert->rule_name}",
            'sections' => [
                [
                    'activityTitle' => "Station Alert: {$this->alert->rule_name}",
                    'activitySubtitle' => $this->alert->severity->label(),
                    'text' => $this->alert->message,
                    'facts' => [
                        ['name' => 'Type', 'value' => $this->alert->type->label()],
                        ['name' => 'Severity', 'value' => $this->alert->severity->label()],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toGoogleChat(mixed $notifiable): array
    {
        return [
            'text' => "Station Alert: {$this->alert->rule_name}",
            'cards' => [
                [
                    'header' => [
                        'title' => "Station Alert: {$this->alert->severity->label()}",
                        'subtitle' => $this->alert->rule_name,
                    ],
                    'sections' => [
                        [
                            'widgets' => [
                                ['textParagraph' => ['text' => $this->alert->message]],
                                ['keyValue' => ['topLabel' => 'Type', 'content' => $this->alert->type->label()]],
                                ['keyValue' => ['topLabel' => 'Severity', 'content' => $this->alert->severity->label()]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebhook(mixed $notifiable): array
    {
        return [
            'event' => 'station.alert',
            'rule_id' => $this->alert->rule_id,
            'rule_name' => $this->alert->rule_name,
            'type' => $this->alert->type->value,
            'severity' => $this->alert->severity->value,
            'message' => $this->alert->message,
            'context' => $this->alert->context,
            'channels' => $this->alert->channels_notified,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
