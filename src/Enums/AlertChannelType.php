<?php

declare(strict_types=1);

namespace Station\Enums;

enum AlertChannelType: string
{
    case Slack = 'slack';
    case Email = 'email';
    case Log = 'log';
    case Discord = 'discord';
    case Teams = 'teams';
    case GoogleChat = 'google_chat';
    case Webhook = 'webhook';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(static fn(self $case): string => $case->label(), self::cases()),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Email => 'Email',
            self::Log => 'Log',
            self::Discord => 'Discord',
            self::Teams => 'Teams',
            self::GoogleChat => 'Google Chat',
            self::Webhook => 'Webhook',
        };
    }
}
