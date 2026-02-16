<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Alerts\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Station\Alerts\Channels\StationDiscordChannel;
use Station\Alerts\Channels\StationGoogleChatChannel;
use Station\Alerts\Channels\StationLogChannel;
use Station\Alerts\Channels\StationSlackChannel;
use Station\Alerts\Channels\StationTeamsChannel;
use Station\Alerts\Channels\StationWebhookChannel;
use Station\Contracts\AlertChannelInterface;
use Station\Enums\AlertSeverity;

final class AlertChannelsTest extends TestCase
{
    // ---------------------------------------------------------------
    // StationSlackChannel
    // ---------------------------------------------------------------

    public function testSlackChannelImplementsInterface(): void
    {
        $this->assertInstanceOf(AlertChannelInterface::class, new StationSlackChannel());
    }

    public function testSlackChannelSendsPayloadToWebhookUrl(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $notifiable = $this->notifiable('slack', 'https://hooks.slack.com/services/test');
        $payload = ['text' => 'Job failed'];
        $notification = $this->notification('toSlack', $payload);

        $channel = new StationSlackChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://hooks.slack.com/services/test'
                && $request['text'] === 'Job failed');
    }

    public function testSlackChannelSkipsSendWhenUrlIsNull(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('slack', null);
        $notification = $this->notification('toSlack', ['text' => 'test']);

        $channel = new StationSlackChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testSlackChannelSkipsSendWhenUrlIsEmptyString(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('slack', '');
        $notification = $this->notification('toSlack', ['text' => 'test']);

        $channel = new StationSlackChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testSlackChannelLogsErrorOnHttpException(): void
    {
        Http::fake(static function (): void {
            throw new RuntimeException('Connection refused');
        });
        Log::spy();

        $notifiable = $this->notifiable('slack', 'https://hooks.slack.com/services/test');
        $notification = $this->notification('toSlack', ['text' => 'test']);

        $channel = new StationSlackChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('error')
            ->with('Station: Failed to send Slack alert', Mockery::on(static fn(array $context) => $context['error'] === 'Connection refused'))
            ->once();
    }

    // ---------------------------------------------------------------
    // StationDiscordChannel
    // ---------------------------------------------------------------

    public function testDiscordChannelImplementsInterface(): void
    {
        $this->assertInstanceOf(AlertChannelInterface::class, new StationDiscordChannel());
    }

    public function testDiscordChannelSendsPayloadToWebhookUrl(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $notifiable = $this->notifiable('discord', 'https://discord.com/api/webhooks/test');
        $payload = ['content' => 'Alert triggered'];
        $notification = $this->notification('toDiscord', $payload);

        $channel = new StationDiscordChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://discord.com/api/webhooks/test'
                && $request['content'] === 'Alert triggered');
    }

    public function testDiscordChannelSkipsSendWhenUrlIsNull(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('discord', null);
        $notification = $this->notification('toDiscord', ['content' => 'test']);

        $channel = new StationDiscordChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testDiscordChannelSkipsSendWhenUrlIsEmptyString(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('discord', '');
        $notification = $this->notification('toDiscord', ['content' => 'test']);

        $channel = new StationDiscordChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testDiscordChannelLogsErrorOnHttpException(): void
    {
        Http::fake(static function (): void {
            throw new RuntimeException('Timeout');
        });
        Log::spy();

        $notifiable = $this->notifiable('discord', 'https://discord.com/api/webhooks/test');
        $notification = $this->notification('toDiscord', ['content' => 'test']);

        $channel = new StationDiscordChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('error')
            ->with('Station: Failed to send Discord alert', Mockery::on(static fn(array $context) => $context['error'] === 'Timeout'))
            ->once();
    }

    // ---------------------------------------------------------------
    // StationTeamsChannel
    // ---------------------------------------------------------------

    public function testTeamsChannelImplementsInterface(): void
    {
        $this->assertInstanceOf(AlertChannelInterface::class, new StationTeamsChannel());
    }

    public function testTeamsChannelSendsPayloadToWebhookUrl(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $notifiable = $this->notifiable('teams', 'https://outlook.office.com/webhook/test');
        $payload = ['text' => 'Teams alert'];
        $notification = $this->notification('toTeams', $payload);

        $channel = new StationTeamsChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://outlook.office.com/webhook/test'
                && $request['text'] === 'Teams alert');
    }

    public function testTeamsChannelSkipsSendWhenUrlIsNull(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('teams', null);
        $notification = $this->notification('toTeams', ['text' => 'test']);

        $channel = new StationTeamsChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testTeamsChannelSkipsSendWhenUrlIsEmptyString(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('teams', '');
        $notification = $this->notification('toTeams', ['text' => 'test']);

        $channel = new StationTeamsChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testTeamsChannelLogsErrorOnHttpException(): void
    {
        Http::fake(static function (): void {
            throw new RuntimeException('DNS resolution failed');
        });
        Log::spy();

        $notifiable = $this->notifiable('teams', 'https://outlook.office.com/webhook/test');
        $notification = $this->notification('toTeams', ['text' => 'test']);

        $channel = new StationTeamsChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('error')
            ->with('Station: Failed to send Teams alert', Mockery::on(static fn(array $context) => $context['error'] === 'DNS resolution failed'))
            ->once();
    }

    // ---------------------------------------------------------------
    // StationGoogleChatChannel
    // ---------------------------------------------------------------

    public function testGoogleChatChannelImplementsInterface(): void
    {
        $this->assertInstanceOf(AlertChannelInterface::class, new StationGoogleChatChannel());
    }

    public function testGoogleChatChannelSendsPayloadToWebhookUrl(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $notifiable = $this->notifiable('google-chat', 'https://chat.googleapis.com/v1/spaces/test');
        $payload = ['text' => 'Google Chat alert'];
        $notification = $this->notification('toGoogleChat', $payload);

        $channel = new StationGoogleChatChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://chat.googleapis.com/v1/spaces/test'
                && $request['text'] === 'Google Chat alert');
    }

    public function testGoogleChatChannelSkipsSendWhenUrlIsNull(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('google-chat', null);
        $notification = $this->notification('toGoogleChat', ['text' => 'test']);

        $channel = new StationGoogleChatChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testGoogleChatChannelSkipsSendWhenUrlIsEmptyString(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('google-chat', '');
        $notification = $this->notification('toGoogleChat', ['text' => 'test']);

        $channel = new StationGoogleChatChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testGoogleChatChannelLogsErrorOnHttpException(): void
    {
        Http::fake(static function (): void {
            throw new RuntimeException('Service unavailable');
        });
        Log::spy();

        $notifiable = $this->notifiable('google-chat', 'https://chat.googleapis.com/v1/spaces/test');
        $notification = $this->notification('toGoogleChat', ['text' => 'test']);

        $channel = new StationGoogleChatChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('error')
            ->with('Station: Failed to send Google Chat alert', Mockery::on(static fn(array $context) => $context['error'] === 'Service unavailable'))
            ->once();
    }

    // ---------------------------------------------------------------
    // StationLogChannel
    // ---------------------------------------------------------------

    public function testLogChannelImplementsInterface(): void
    {
        $this->assertInstanceOf(AlertChannelInterface::class, new StationLogChannel());
    }

    public function testLogChannelLogsCriticalLevelForCriticalSeverity(): void
    {
        Log::spy();

        $notifiable = $this->notifiable('station-log', null);
        $payload = [
            'message' => 'Queue stuck',
            'context' => [
                'severity' => AlertSeverity::Critical->value,
                'queue' => 'default',
            ],
        ];
        $notification = $this->notification('toLog', $payload);

        $channel = new StationLogChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('log')
            ->with('critical', 'Queue stuck', Mockery::on(static fn(array $context) => $context['severity'] === 'critical'
                    && $context['queue'] === 'default'))
            ->once();
    }

    public function testLogChannelLogsWarningLevelForWarningSeverity(): void
    {
        Log::spy();

        $notifiable = $this->notifiable('station-log', null);
        $payload = [
            'message' => 'High failure rate',
            'context' => [
                'severity' => AlertSeverity::Warning->value,
            ],
        ];
        $notification = $this->notification('toLog', $payload);

        $channel = new StationLogChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('log')
            ->with('warning', 'High failure rate', Mockery::on(static fn(array $context) => $context['severity'] === 'warning'))
            ->once();
    }

    public function testLogChannelLogsInfoLevelForInfoSeverity(): void
    {
        Log::spy();

        $notifiable = $this->notifiable('station-log', null);
        $payload = [
            'message' => 'Queue recovered',
            'context' => [
                'severity' => AlertSeverity::Info->value,
            ],
        ];
        $notification = $this->notification('toLog', $payload);

        $channel = new StationLogChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('log')
            ->with('info', 'Queue recovered', Mockery::on(static fn(array $context) => $context['severity'] === 'info'))
            ->once();
    }

    public function testLogChannelDefaultsToInfoLevelForUnknownSeverity(): void
    {
        Log::spy();

        $notifiable = $this->notifiable('station-log', null);
        $payload = [
            'message' => 'Something happened',
            'context' => [
                'severity' => 'unknown-level',
            ],
        ];
        $notification = $this->notification('toLog', $payload);

        $channel = new StationLogChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('log')
            ->with('info', 'Something happened', Mockery::on(static fn(array $context) => $context['severity'] === 'unknown-level'))
            ->once();
    }

    public function testLogChannelUsesSpecificLogChannelWhenProvided(): void
    {
        // Configure a logging channel so the code takes the Log::channel() path
        config(['logging.channels.station' => ['driver' => 'single', 'path' => storage_path('logs/station.log')]]);

        $loggerSpy = Mockery::spy('Psr\Log\LoggerInterface');
        Log::shouldReceive('channel')
            ->with('station')
            ->once()
            ->andReturn($loggerSpy);

        $notifiable = $this->notifiable('station-log', 'station');
        $payload = [
            'message' => 'Custom channel alert',
            'context' => [
                'severity' => AlertSeverity::Warning->value,
            ],
        ];
        $notification = $this->notification('toLog', $payload);

        $channel = new StationLogChannel();
        $channel->send($notifiable, $notification);

        $loggerSpy->shouldHaveReceived('log')
            ->with('warning', 'Custom channel alert', Mockery::on(static fn(array $context) => $context['severity'] === 'warning'))
            ->once();
    }

    public function testLogChannelDefaultsToWarningWhenSeverityKeyIsMissing(): void
    {
        Log::spy();

        $notifiable = $this->notifiable('station-log', null);
        $payload = [
            'message' => 'No severity provided',
            'context' => [],
        ];
        $notification = $this->notification('toLog', $payload);

        $channel = new StationLogChannel();
        $channel->send($notifiable, $notification);

        // When 'severity' key is absent, the channel defaults to 'warning' via the null coalesce.
        Log::shouldHaveReceived('log')
            ->with('warning', 'No severity provided', [])
            ->once();
    }

    // ---------------------------------------------------------------
    // StationWebhookChannel
    // ---------------------------------------------------------------

    public function testWebhookChannelImplementsInterface(): void
    {
        $this->assertInstanceOf(AlertChannelInterface::class, new StationWebhookChannel());
    }

    public function testWebhookChannelSendsPayloadToUrl(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $notifiable = $this->notifiable('station-webhook', [
            'url' => 'https://example.com/webhook',
            'secret' => null,
        ]);
        $payload = ['event' => 'job.failed', 'job_id' => 'abc-123'];
        $notification = $this->notification('toWebhook', $payload);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://example.com/webhook'
                && $request['event'] === 'job.failed'
                && $request['job_id'] === 'abc-123');
    }

    public function testWebhookChannelSkipsSendWhenUrlIsNull(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('station-webhook', [
            'url' => null,
            'secret' => null,
        ]);
        $notification = $this->notification('toWebhook', ['event' => 'test']);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testWebhookChannelSkipsSendWhenUrlIsEmptyString(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('station-webhook', [
            'url' => '',
            'secret' => null,
        ]);
        $notification = $this->notification('toWebhook', ['event' => 'test']);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testWebhookChannelSkipsSendWhenRouteIsNull(): void
    {
        Http::fake();

        $notifiable = $this->notifiable('station-webhook', null);
        $notification = $this->notification('toWebhook', ['event' => 'test']);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Http::assertNothingSent();
    }

    public function testWebhookChannelAddsHmacSignatureWhenSecretProvided(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $secret = 'my-webhook-secret';
        $notifiable = $this->notifiable('station-webhook', [
            'url' => 'https://example.com/webhook',
            'secret' => $secret,
        ]);
        $payload = ['event' => 'job.failed', 'job_id' => 'xyz-789'];
        $notification = $this->notification('toWebhook', $payload);

        $expectedSignature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://example.com/webhook'
                && $request->hasHeader('X-Station-Signature', $expectedSignature));
    }

    public function testWebhookChannelOmitsSignatureWhenSecretIsNull(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $notifiable = $this->notifiable('station-webhook', [
            'url' => 'https://example.com/webhook',
            'secret' => null,
        ]);
        $notification = $this->notification('toWebhook', ['event' => 'test']);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://example.com/webhook'
                && !$request->hasHeader('X-Station-Signature'));
    }

    public function testWebhookChannelOmitsSignatureWhenSecretIsEmptyString(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $notifiable = $this->notifiable('station-webhook', [
            'url' => 'https://example.com/webhook',
            'secret' => '',
        ]);
        $notification = $this->notification('toWebhook', ['event' => 'test']);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Http::assertSent(static fn($request) => $request->url() === 'https://example.com/webhook'
                && !$request->hasHeader('X-Station-Signature'));
    }

    public function testWebhookChannelLogsErrorOnHttpException(): void
    {
        Http::fake(static function (): void {
            throw new RuntimeException('Connection reset');
        });
        Log::spy();

        $notifiable = $this->notifiable('station-webhook', [
            'url' => 'https://example.com/webhook',
            'secret' => null,
        ]);
        $notification = $this->notification('toWebhook', ['event' => 'test']);

        $channel = new StationWebhookChannel();
        $channel->send($notifiable, $notification);

        Log::shouldHaveReceived('error')
            ->with('Station: Failed to send webhook alert', Mockery::on(static fn(array $context) => $context['error'] === 'Connection reset'))
            ->once();
    }
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Build a notifiable that returns the given value for routeNotificationFor($channel).
     */
    private function notifiable(string $channel, mixed $returnValue): object
    {
        return new class($channel, $returnValue) {
            public function __construct(
                private readonly string $channel,
                private readonly mixed $returnValue,
            ) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return $channel === $this->channel ? $this->returnValue : null;
            }
        };
    }

    /**
     * Build a Notification mock that returns $payload from the given to* method.
     */
    private function notification(string $method, mixed $payload): Notification
    {
        // Notification subclasses define to* methods dynamically; use __call or add method via anonymous class.
        // We return an anonymous subclass with the method defined.
        return new class($method, $payload) extends Notification {
            public function __construct(
                private readonly string $method,
                private readonly mixed $payload,
            ) {}

            public function __call(string $name, array $arguments): mixed
            {
                if ($name === $this->method) {
                    return $this->payload;
                }

                return null;
            }
        };
    }
}
