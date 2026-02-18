<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Alerts\Notifications;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Station\Alerts\Channels\StationDiscordChannel;
use Station\Alerts\Channels\StationEmailChannel;
use Station\Alerts\Channels\StationGoogleChatChannel;
use Station\Alerts\Channels\StationLogChannel;
use Station\Alerts\Channels\StationSlackChannel;
use Station\Alerts\Channels\StationTeamsChannel;
use Station\Alerts\Channels\StationWebhookChannel;
use Station\Alerts\Notifications\StationAlertNotification;
use Station\DTOs\AlertRecord;
use Station\Enums\AlertChannelType;
use Station\Enums\AlertSeverity;
use Station\Enums\AlertType;

class StationAlertNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up a minimal Container so config() and now() work
        $container = new Container();
        $container->instance('log', new NullLogger());
        $container->instance('config', new class {
            /**
             */
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        });
        Container::setInstance($container);

        // Freeze time so toDiscord/toWebhook timestamps are predictable
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::setInstance(null);
        parent::tearDown();
    }

    // ---- via() tests ----

    public function testViaReturnsEmailChannelClassForEmail(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Email);

        $this->assertSame([StationEmailChannel::class], $notification->via(null));
    }

    public function testViaReturnsSlackChannelClassForSlack(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Slack);

        $this->assertSame([StationSlackChannel::class], $notification->via(null));
    }

    public function testViaReturnsLogChannelClassForLog(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Log);

        $this->assertSame([StationLogChannel::class], $notification->via(null));
    }

    public function testViaReturnsDiscordChannelClassForDiscord(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Discord);

        $this->assertSame([StationDiscordChannel::class], $notification->via(null));
    }

    public function testViaReturnsTeamsChannelClassForTeams(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Teams);

        $this->assertSame([StationTeamsChannel::class], $notification->via(null));
    }

    public function testViaReturnsGoogleChatChannelClassForGoogleChat(): void
    {
        $notification = $this->makeNotification(AlertChannelType::GoogleChat);

        $this->assertSame([StationGoogleChatChannel::class], $notification->via(null));
    }

    public function testViaReturnsWebhookChannelClassForWebhook(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Webhook);

        $this->assertSame([StationWebhookChannel::class], $notification->via(null));
    }

    // ---- toSlack() tests ----

    public function testToSlackReturnsArrayWithCorrectStructure(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Slack, AlertSeverity::Warning);

        $result = $notification->toSlack(null);

        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('attachments', $result);
        $this->assertCount(1, $result['attachments']);
        $this->assertArrayHasKey('color', $result['attachments'][0]);
        $this->assertArrayHasKey('title', $result['attachments'][0]);
        $this->assertArrayHasKey('text', $result['attachments'][0]);
        $this->assertArrayHasKey('fields', $result['attachments'][0]);
        $this->assertArrayHasKey('footer', $result['attachments'][0]);
        $this->assertSame('Station Queue Monitor', $result['attachments'][0]['footer']);
    }

    public function testToSlackColorForCritical(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Slack, AlertSeverity::Critical);

        $result = $notification->toSlack(null);

        $this->assertSame('#dc2626', $result['attachments'][0]['color']);
        $this->assertStringContainsString(':rotating_light:', $result['text']);
    }

    public function testToSlackColorForWarning(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Slack, AlertSeverity::Warning);

        $result = $notification->toSlack(null);

        $this->assertSame('#f59e0b', $result['attachments'][0]['color']);
        $this->assertStringContainsString(':warning:', $result['text']);
    }

    public function testToSlackColorForInfo(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Slack, AlertSeverity::Info);

        $result = $notification->toSlack(null);

        $this->assertSame('#3b82f6', $result['attachments'][0]['color']);
        $this->assertStringContainsString(':information_source:', $result['text']);
    }

    // ---- toMail() tests ----

    public function testToMailReturnsMailMessage(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Email, AlertSeverity::Warning);

        $result = $notification->toMail(null);

        $this->assertInstanceOf(MailMessage::class, $result);
    }

    public function testToMailSubjectContainsSeverityAndRuleName(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Email, AlertSeverity::Critical);

        $result = $notification->toMail(null);

        $this->assertSame('[Station] Critical: Test Rule', $result->subject);
    }

    public function testToMailGreetingContainsSeverity(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Email, AlertSeverity::Warning);

        $result = $notification->toMail(null);

        $this->assertSame('Station Alert: Warning', $result->greeting);
    }

    // ---- toLog() tests ----

    public function testToLogReturnsArrayWithMessageAndContext(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Log, AlertSeverity::Warning);

        $result = $notification->toLog(null);

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('context', $result);
        $this->assertStringContainsString('[Station Alert]', $result['message']);
        $this->assertStringContainsString('Warning', $result['message']);
        $this->assertStringContainsString('Test message', $result['message']);
    }

    public function testToLogContextHasCorrectKeys(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Log, AlertSeverity::Info);

        $result = $notification->toLog(null);

        $this->assertArrayHasKey('rule_name', $result['context']);
        $this->assertArrayHasKey('type', $result['context']);
        $this->assertArrayHasKey('severity', $result['context']);
        $this->assertArrayHasKey('alert_context', $result['context']);
        $this->assertSame('Test Rule', $result['context']['rule_name']);
        $this->assertSame('high_failure_rate', $result['context']['type']);
        $this->assertSame('info', $result['context']['severity']);
    }

    // ---- toDiscord() tests ----

    public function testToDiscordReturnsArrayWithEmbedsStructure(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Discord, AlertSeverity::Warning);

        $result = $notification->toDiscord(null);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('embeds', $result);
        $this->assertCount(1, $result['embeds']);
        $this->assertArrayHasKey('title', $result['embeds'][0]);
        $this->assertArrayHasKey('description', $result['embeds'][0]);
        $this->assertArrayHasKey('color', $result['embeds'][0]);
        $this->assertArrayHasKey('fields', $result['embeds'][0]);
        $this->assertArrayHasKey('footer', $result['embeds'][0]);
        $this->assertArrayHasKey('timestamp', $result['embeds'][0]);
    }

    public function testToDiscordColorForCritical(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Discord, AlertSeverity::Critical);

        $result = $notification->toDiscord(null);

        $this->assertSame(0xdc2626, $result['embeds'][0]['color']);
    }

    public function testToDiscordColorForWarning(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Discord, AlertSeverity::Warning);

        $result = $notification->toDiscord(null);

        $this->assertSame(0xf59e0b, $result['embeds'][0]['color']);
    }

    public function testToDiscordColorForInfo(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Discord, AlertSeverity::Info);

        $result = $notification->toDiscord(null);

        $this->assertSame(0x3b82f6, $result['embeds'][0]['color']);
    }

    // ---- toTeams() tests ----

    public function testToTeamsReturnsMessageCardStructure(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Teams, AlertSeverity::Warning);

        $result = $notification->toTeams(null);

        $this->assertSame('MessageCard', $result['@type']);
        $this->assertSame('http://schema.org/extensions', $result['@context']);
        $this->assertArrayHasKey('themeColor', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('sections', $result);
    }

    public function testToTeamsThemeColorForCritical(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Teams, AlertSeverity::Critical);

        $result = $notification->toTeams(null);

        $this->assertSame('dc2626', $result['themeColor']);
    }

    public function testToTeamsThemeColorForWarning(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Teams, AlertSeverity::Warning);

        $result = $notification->toTeams(null);

        $this->assertSame('f59e0b', $result['themeColor']);
    }

    public function testToTeamsThemeColorForInfo(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Teams, AlertSeverity::Info);

        $result = $notification->toTeams(null);

        $this->assertSame('3b82f6', $result['themeColor']);
    }

    // ---- toGoogleChat() tests ----

    public function testToGoogleChatReturnsArrayWithCardsStructure(): void
    {
        $notification = $this->makeNotification(AlertChannelType::GoogleChat, AlertSeverity::Warning);

        $result = $notification->toGoogleChat(null);

        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('cards', $result);
        $this->assertCount(1, $result['cards']);
        $this->assertArrayHasKey('header', $result['cards'][0]);
        $this->assertArrayHasKey('sections', $result['cards'][0]);
        $this->assertSame('Station Alert: Warning', $result['cards'][0]['header']['title']);
        $this->assertSame('Test Rule', $result['cards'][0]['header']['subtitle']);
    }

    public function testToGoogleChatWidgetsContainMessage(): void
    {
        $notification = $this->makeNotification(AlertChannelType::GoogleChat, AlertSeverity::Info);

        $result = $notification->toGoogleChat(null);

        $widgets = $result['cards'][0]['sections'][0]['widgets'];
        $this->assertSame('Test message', $widgets[0]['textParagraph']['text']);
    }

    // ---- toWebhook() tests ----

    public function testToWebhookReturnsArrayWithCorrectKeys(): void
    {
        $notification = $this->makeNotification(AlertChannelType::Webhook, AlertSeverity::Warning);

        $result = $notification->toWebhook(null);

        $this->assertSame('station.alert', $result['event']);
        $this->assertSame('rule-1', $result['rule_id']);
        $this->assertSame('Test Rule', $result['rule_name']);
        $this->assertSame('high_failure_rate', $result['type']);
        $this->assertSame('warning', $result['severity']);
        $this->assertSame('Test message', $result['message']);
        $this->assertSame(['key' => 'value'], $result['context']);
        $this->assertSame(['slack'], $result['channels']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    // ---- helpers ----

    private function makeNotification(
        AlertChannelType $channelType,
        AlertSeverity $severity = AlertSeverity::Warning,
    ): StationAlertNotification {
        $record = new AlertRecord(
            id: 1,
            rule_id: 'rule-1',
            rule_name: 'Test Rule',
            type: AlertType::HighFailureRate,
            severity: $severity,
            message: 'Test message',
            context: ['key' => 'value'],
            channels_notified: ['slack'],
        );

        return new StationAlertNotification($record, $channelType);
    }
}
