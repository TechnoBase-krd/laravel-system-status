<?php

namespace Technobase\SystemStatus\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * A System Status change sent to the error chat as plain text (no Markdown, so route paths
 * with underscores cannot break the message).
 */
class StatusAlertNotification extends Notification
{
    public function __construct(private readonly string $content) {}

    public function via($notifiable): array
    {
        return ['telegram'];
    }

    public function toTelegram($notifiable): TelegramMessage
    {
        return TelegramMessage::create()->content($this->content)->normal();
    }
}
