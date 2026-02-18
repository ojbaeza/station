<?php

declare(strict_types=1);

namespace Station\Alerts\Channels;

use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Station\Contracts\AlertChannelInterface;
use Throwable;

final class StationEmailChannel implements AlertChannelInterface
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        /** @var string|array<int, string>|null $recipients */
        $recipients = $notifiable->routeNotificationFor('mail');

        $recipients = $this->resolveRecipients($recipients);

        if ($recipients === []) {
            Log::warning('Station: Email alert channel has no recipients configured');

            return;
        }

        /** @var MailMessage $mailMessage */
        $mailMessage = $notification->toMail($notifiable); // @phpstan-ignore method.notFound (Laravel notification channel pattern)

        try {
            Mail::send(
                [],
                [],
                function (Message $message) use ($recipients, $mailMessage): void {
                    $message->to($recipients);

                    $message->subject($mailMessage->subject !== '' ? $mailMessage->subject : 'Station Alert');

                    $message->html($this->buildHtml($mailMessage));
                },
            );
        } catch (Throwable $e) {
            Log::error('Station: Failed to send email alert', [
                'error' => $e->getMessage(),
                'recipients' => $recipients,
            ]);
        }
    }

    /**
     * Resolve recipients from the notifiable route value.
     *
     * Supports a single comma-separated string, an array of strings,
     * or null/empty (returns empty array).
     *
     * @param string|array<int, string>|null $recipients
     * @return array<int, string>
     */
    private function resolveRecipients(string|array|null $recipients): array
    {
        if ($recipients === null) {
            return [];
        }

        if (\is_string($recipients)) {
            $recipients = explode(',', $recipients);
        }

        return array_values(array_filter(
            array_map('trim', $recipients),
            static fn(string $email): bool => $email !== '',
        ));
    }

    /**
     * Build a simple HTML email body from the MailMessage.
     */
    private function buildHtml(MailMessage $mailMessage): string
    {
        $html = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">';

        // Greeting
        if ($mailMessage->greeting !== null && $mailMessage->greeting !== '') {
            $html .= '<h2 style="color: #1a202c; margin-bottom: 16px;">'
                . e($mailMessage->greeting) . '</h2>';
        }

        // Intro lines
        foreach ($mailMessage->introLines as $line) {
            $html .= '<p style="color: #4a5568; margin-bottom: 8px;">' . $this->formatLine($line) . '</p>';
        }

        // Action button
        if ($mailMessage->actionText !== null && $mailMessage->actionUrl !== null) {
            $html .= '<p style="margin: 24px 0;">'
                . '<a href="' . e($mailMessage->actionUrl) . '" '
                . 'style="background-color: #3b82f6; color: #ffffff; padding: 10px 20px; '
                . 'text-decoration: none; border-radius: 4px; display: inline-block;">'
                . e($mailMessage->actionText) . '</a></p>';
        }

        // Outro lines
        foreach ($mailMessage->outroLines as $line) {
            $html .= '<p style="color: #4a5568; margin-bottom: 8px;">' . $this->formatLine($line) . '</p>';
        }

        // Footer
        $html .= '<hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">';
        $html .= '<p style="color: #a0aec0; font-size: 12px;">Station Queue Monitor</p>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Format a line, converting basic markdown bold syntax to HTML.
     */
    private function formatLine(string $line): string
    {
        // Convert **bold** to <strong>bold</strong>
        $formatted = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($line));

        // Convert - list items to proper formatting
        if (str_starts_with(trim($line), '- ')) {
            $formatted = '&bull; ' . ltrim($formatted, '- ');
        }

        return $formatted;
    }
}
