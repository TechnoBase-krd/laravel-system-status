<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Calls the free, read-only Bot API getMe. Messages never include the request URL, because
 * it contains the bot token.
 */
class TelegramProbe implements ConcurrentProbe
{
    use SendsHttpRequest;

    public function name(): string
    {
        return 'Telegram getMe';
    }

    public function precheck(): ?ProbeResult
    {
        return blank(config('services.telegram-bot-api.token')) ? ProbeResult::skipped('Telegram is not configured') : null;
    }

    public function send(PendingRequest $request): mixed
    {
        return $request->timeout((int) config('system-status.thresholds.probe_timeout_seconds', 10))
            ->get('https://api.telegram.org/bot'.config('services.telegram-bot-api.token').'/getMe');
    }

    public function result(Response|Throwable $response): ProbeResult
    {
        if ($response instanceof Throwable) {
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
