<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use Technobase\SystemStatus\Notifications\StatusAlertNotification;
use Throwable;

/**
 * Sends System Status alerts to the channels in system-status.alerts.channels (telegram, mail,
 * slack, discord) and JSON events to system-status.alerts.webhooks. Status changes go through
 * change(): a component that flaps (changes status again and again) sends one "flapping" alert
 * and stays quiet until it is stable. Never throws: a failing channel is logged.
 */
class StatusAlerter
{
    public const CHANNELS = ['telegram', 'mail', 'slack', 'discord'];

    private const FLAPPING_LIST = 'status:alerts:flapping';

    private bool $batching = false;

    /** @var array<int, array{component: ?string, text: string, recovery: bool}> */
    private array $pending = [];

    /**
     * Holds status-change alerts until flush(), so one run sends them together.
     */
    public function begin(): void
    {
        $this->batching = true;
        $this->pending = [];
    }

    /**
     * Sends the held alerts. $affectedBy maps a component to the component it depends on that
     * is failing too: its alert is folded into that component's ("Also affected: …"), or dropped
     * when that component has no alert in this run (it was reported earlier).
     *
     * @param  array<string, string>  $affectedBy
     */
    public function flush(array $affectedBy = [], ?StatusRegistry $registry = null): void
    {
        $this->batching = false;
        $pending = $this->pending;
        $this->pending = [];

        $byComponent = [];
        foreach ($pending as $index => $alert) {
            if ($alert['component'] !== null) {
                $byComponent[$alert['component']] = $index;
            }
        }

        $also = [];
        foreach ($pending as $index => $alert) {
            $parent = $alert['component'] !== null ? ($affectedBy[$alert['component']] ?? null) : null;

            if ($parent === null) {
                continue;
            }

            if (isset($byComponent[$parent])) {
                $also[$byComponent[$parent]][] = $registry?->label($alert['component'], 'en') ?? $alert['component'];
            }

            unset($pending[$index]);
            Log::info('[system-status] (affected by '.$parent.') '.$alert['text']);
        }

        foreach ($pending as $index => $alert) {
            $text = $alert['text'];
            if (! empty($also[$index])) {
                $text .= ($alert['recovery'] ? ' Also recovered: ' : ' Also affected: ').implode(', ', $also[$index]).'.';
            }

            $this->send($text);
        }
    }

    /**
     * A confirmed status change of a component.
     */
    public function change(string $component, string $text, ?string $label = null, bool $recovery = false): void
    {
        if ($this->flapping($component, $label ?? $component)) {
            return;
        }

        if ($this->batching) {
            $this->pending[] = ['component' => $component, 'text' => $text, 'recovery' => $recovery];

            return;
        }

        $this->send($text);
    }

    /**
     * Sends a text alert to every configured channel now.
     *
     * @param  array<int, string>|null  $channels  null = system-status.alerts.channels
     * @return array<string, string> channel => "sent", "skipped: …" or "failed: …"
     */
    public function send(string $text, ?array $channels = null, bool $force = false): array
    {
        if (! $force && ! $this->enabled()) {
            Log::info('[system-status] '.$text);

            return array_fill_keys($channels ?? $this->channels(), 'skipped: alerts are off in this environment');
        }

        $message = $this->prefixed($text);
        $results = [];

        foreach ($channels ?? $this->channels() as $channel) {
            try {
                $results[$channel] = match ($channel) {
                    'telegram' => $this->telegram($message),
                    'mail' => $this->mail($message),
                    'slack' => $this->post(config('system-status.alerts.slack_webhook'), ['text' => $message]),
                    'discord' => $this->post(config('system-status.alerts.discord_webhook'), ['content' => mb_substr($message, 0, 2000)]),
                    default => 'skipped: unknown channel',
                };
            } catch (Throwable $e) {
                $results[$channel] = 'failed: '.class_basename($e);
                Log::warning('System status alert could not be sent.', ['channel' => $channel, 'exception' => $e::class]);
            }
        }

        if (! in_array('sent', $results, true)) {
            Log::info('[system-status] '.$text);
        }

        return $results;
    }

    /**
     * POSTs a JSON event to every URL in system-status.alerts.webhooks. With a secret, the body is
     * signed: X-System-Status-Signature: sha256=<hmac>.
     *
     * @return array<string, string> url => result
     */
    public function event(string $type, array $data, bool $force = false): array
    {
        $webhooks = (array) config('system-status.alerts.webhooks', []);

        if ($webhooks === [] || (! $force && ! $this->enabled())) {
            return [];
        }

        $body = json_encode([
            'event' => $type,
            'app' => config('app.name'),
            'sent_at' => now()->toIso8601String(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $results = [];

        foreach ($webhooks as $webhook) {
            $webhook = is_string($webhook) ? ['url' => $webhook] : (array) $webhook;
            $url = (string) ($webhook['url'] ?? '');

            if ($url === '') {
                continue;
            }

            try {
                $headers = ['User-Agent' => (string) config('system-status.probe_user_agent', 'SystemStatusProbe/1.0')];
                if (filled($webhook['secret'] ?? null)) {
                    $headers['X-System-Status-Signature'] = 'sha256='.hash_hmac('sha256', (string) $body, (string) $webhook['secret']);
                }

                $response = Http::timeout(5)->withHeaders($headers)->withBody((string) $body, 'application/json')->post($url);
                $results[$url] = $response->successful() ? 'sent' : 'failed: HTTP '.$response->status();
            } catch (Throwable $e) {
                $results[$url] = 'failed: '.class_basename($e);
                Log::warning('System status webhook could not be sent.', ['exception' => $e::class]);
            }
        }

        return $results;
    }

    /**
     * Sends "stable again" for components that stopped flapping.
     *
     * @param  callable(string): string  $describe  component => "Database is operational"
     */
    public function flappingEnded(callable $describe): void
    {
        [$changes, $minutes] = $this->flapSettings();
        if ($changes === 0) {
            return;
        }

        try {
            $list = (array) Cache::get(self::FLAPPING_LIST, []);
            $still = [];

            foreach ($list as $component) {
                $last = Cache::get($this->flapKey($component));

                if ($last && now()->timestamp - (int) $last < $minutes * 60) {
                    $still[] = $component;

                    continue;
                }

                Cache::forget($this->flapKey($component));
                $this->send('ℹ️ '.$describe($component).' — stable again, alerts resumed.');
            }

            Cache::put(self::FLAPPING_LIST, $still, now()->addDay());
        } catch (Throwable $e) {
            Log::debug('Status flapping state could not be read.', ['exception' => $e::class]);
        }
    }

    public function prefixed(string $text): string
    {
        $prefix = (string) config('system-status.alerts.prefix', '['.config('app.name').' status]');

        return trim($prefix.' '.$text);
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return array_values(array_unique((array) config('system-status.alerts.channels', ['telegram'])));
    }

    private function enabled(): bool
    {
        return config('system-status.alerts.enabled', true) && ! app()->environment('local');
    }

    /**
     * True while the component flaps: after `flap.changes` changes within `flap.minutes`, one
     * alert says so and later changes stay quiet until it has been stable for `flap.minutes`.
     */
    private function flapping(string $component, string $label): bool
    {
        [$changes, $minutes] = $this->flapSettings();
        if ($changes === 0) {
            return false;
        }

        try {
            $now = now()->timestamp;
            $historyKey = 'status:alerts:changes:'.$component;
            $history = array_values(array_filter(
                (array) Cache::get($historyKey, []),
                fn ($at) => (int) $at > $now - $minutes * 60,
            ));
            $history[] = $now;
            Cache::put($historyKey, $history, now()->addMinutes($minutes * 2));

            if (Cache::has($this->flapKey($component))) {
                Cache::put($this->flapKey($component), $now, now()->addMinutes($minutes * 2));
                Log::info("[system-status] {$label} is flapping; change alert held back.");

                return true;
            }

            if (count($history) < $changes) {
                return false;
            }

            Cache::put($this->flapKey($component), $now, now()->addMinutes($minutes * 2));
            Cache::put(self::FLAPPING_LIST, array_values(array_unique([...(array) Cache::get(self::FLAPPING_LIST, []), $component])), now()->addDay());
            $this->send("⚠️ {$label} is flapping: ".count($history)." status changes in {$minutes} min. Alerts for it are paused until it is stable for {$minutes} min.");

            return true;
        } catch (Throwable $e) {
            Log::debug('Status flapping state could not be stored.', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * @return array{0: int, 1: int} changes (0 = off), minutes
     */
    private function flapSettings(): array
    {
        $flap = config('system-status.alerts.flap', ['changes' => 4, 'minutes' => 30]);

        if (! is_array($flap) || empty($flap['changes'])) {
            return [0, 0];
        }

        return [max(2, (int) $flap['changes']), max(1, (int) ($flap['minutes'] ?? 30))];
    }

    private function flapKey(string $component): string
    {
        return 'status:alerts:flapping:'.$component;
    }

    private function telegram(string $message): string
    {
        $chatId = config('system-status.alerts.telegram_chat_id');

        if (blank($chatId) || blank(config('services.telegram-bot-api.token'))) {
            return 'skipped: bot token or chat id not set';
        }

        if (! class_exists(TelegramMessage::class)) {
            return 'skipped: laravel-notification-channels/telegram is not installed';
        }

        Notification::route('telegram', $chatId)->notifyNow(new StatusAlertNotification($message));

        return 'sent';
    }

    private function mail(string $message): string
    {
        $to = array_values(array_filter((array) config('system-status.alerts.mail_to', [])));

        if ($to === []) {
            return 'skipped: alerts.mail_to is empty';
        }

        $subject = mb_substr(strtok($message, "\n") ?: $message, 0, 150);
        Mail::raw($message, fn ($mail) => $mail->to($to)->subject($subject));

        return 'sent';
    }

    private function post(?string $url, array $payload): string
    {
        if (blank($url)) {
            return 'skipped: webhook URL not set';
        }

        $response = Http::timeout(5)->asJson()->post($url, $payload);

        return $response->successful() ? 'sent' : 'failed: HTTP '.$response->status();
    }
}
