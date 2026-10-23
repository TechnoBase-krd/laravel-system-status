<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use Technobase\SystemStatus\Notifications\StatusAlertNotification;
use Throwable;

/**
 * Sends System Status alerts. Never throws: a failing channel is logged.
 */
class StatusAlerter
{
    /**
     * Sends a plain-text alert to the Telegram chat in system-status.alerts.telegram_chat_id.
     * Nothing is sent in `local`, while the bot or chat is not configured, or without the
     * laravel-notification-channels/telegram package; the alert is logged instead.
     */
    public function send(string $text): void
    {
        $chatId = config('system-status.alerts.telegram_chat_id');

        if (! config('system-status.alerts.enabled', true)
            || app()->environment('local')
            || blank($chatId)
            || blank(config('services.telegram-bot-api.token'))
            || ! class_exists(TelegramMessage::class)) {
            Log::info('[system-status] '.$text);

            return;
        }

        try {
            Notification::route('telegram', $chatId)->notifyNow(new StatusAlertNotification($this->prefixed($text)));
        } catch (Throwable $e) {
            Log::warning('System status alert could not be sent.', ['exception' => $e::class]);
        }
    }

    public function prefixed(string $text): string
    {
        $prefix = (string) config('system-status.alerts.prefix', '['.config('app.name').' status]');

        return trim($prefix.' '.$text);
    }
}
