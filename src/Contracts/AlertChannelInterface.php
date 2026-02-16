<?php

declare(strict_types=1);

namespace Station\Contracts;

use Illuminate\Notifications\Notification;

interface AlertChannelInterface
{
    public function send(mixed $notifiable, Notification $notification): void;
}
