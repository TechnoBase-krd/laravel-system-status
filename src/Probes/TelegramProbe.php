<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls the free, read-only Bot API getMe. Messages never include the request URL, because
 * it contains the bot token.
 */
class TelegramProbe implements Probe
{
    public function name(): string
    {
        return 'Telegram getMe';
    }

    public function run(): ProbeResult
    {
        $token = config('services.telegram-bot-api.token');

        if (blank($token)) {
            return ProbeResult::skipped('Telegram is not configured');
        }

        try {
            $response = Http::timeout((int) config('system-status.thresholds.probe_timeout_seconds', 10))
                ->get('https://api.telegram.org/bot'.$token.'/getMe');
        } catch (Throwable) {
            return ProbeResult::outage('Could not connect to the Telegram API');
        }

        if ($response->status() === 401) {
            return ProbeResult::outage('Telegram rejected the bot token');
        }

        return $response->successful() && $response->json('ok') === true
            ? ProbeResult::ok()
            : ProbeResult::outage('The Telegram API returned HTTP '.$response->status());
    }
}
