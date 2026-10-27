<?php

namespace Technobase\SystemStatus\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Technobase\SystemStatus\Events\SuspiciousActivityDetected;
use Technobase\SystemStatus\Models\StatusSecurityEvent;
use Technobase\SystemStatus\Support\StatusAlerter;
use Throwable;

/**
 * Detects suspicious activity (security.*): request patterns per IP, failed logins, logins from
 * a new country or IP, signal volume and risky actions. Counters live in a cache store; a rule
 * that is crossed becomes a StatusSecurityEvent, one alert, one webhook event and one
 * SuspiciousActivityDetected event, then stays quiet for security.cooldown_minutes while its hit
 * count keeps growing. Never throws.
 */
class SecurityMonitor
{
    private const PREFIX = 'status:security:';

    /** Seconds between two hit-count updates of a finding that goes on. */
    private const TOUCH_SECONDS = 60;

    public function __construct(private readonly StatusAlerter $alerter) {}

    public function enabled(): bool
    {
        return (bool) config('system-status.enabled', true) && (bool) config('system-status.security.enabled', false);
    }

    /**
     * A finished request: checks it against every request rule.
     */
    public function request(Request $request, int $status): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $ip = $request->ip();
            if ($ip === null || $this->ignored($ip)) {
                return;
            }

            $path = ltrim($request->path(), '/');
            // Scanners mix cases (/WP-Admin, /.ENV) and encode payloads (%27, %3Cscript, +).
            $lowerPath = mb_strtolower($path);
            $url = null;
            $agent = null;

            foreach ((array) config('system-status.security.requests', []) as $name => $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $statuses = array_map('intval', (array) ($rule['statuses'] ?? []));
                $paths = array_map(fn ($pattern) => mb_strtolower((string) $pattern), (array) ($rule['paths'] ?? []));
                $contains = (array) ($rule['contains'] ?? []);
                $agents = (array) ($rule['user_agents'] ?? []);

                if (($statuses && ! in_array($status, $statuses, true)) || ($paths && ! Str::is($paths, $lowerPath))) {
                    continue;
                }

                $subject = $paths ? '/'.$path : null;

                if ($contains) {
                    $url ??= mb_strtolower(urldecode(urldecode($request->getRequestUri())));
                    if (($needle = self::firstIn($url, $contains)) === null) {
                        continue;
                    }
                    $subject = $needle;
                }

                if ($agents) {
                    $agent ??= mb_strtolower((string) $request->userAgent());
                    if (($needle = self::firstIn($agent, $agents)) === null) {
                        continue;
                    }
                    $subject = $needle;
                }

                $this->hit(StatusSecurityEvent::CATEGORY_REQUEST, (string) $name, (string) $name, $ip, $rule, [
                    'ip' => $ip,
                    'subject' => $subject !== null ? Str::limit($subject, 180) : null,
                    'details' => ['status' => $status, 'method' => $request->method(), 'path' => Str::limit('/'.$path, 180)],
                ]);
            }
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    /**
     * A failed login. Only the account name is used (masked), never the password.
     */
    public function failedLogin(?string $account, ?string $ip): void
    {
        if (! $this->enabled() || ($ip !== null && $this->ignored($ip))) {
            return;
        }

        try {
            $logins = (array) config('system-status.security.logins', []);

            if ($ip !== null) {
                $this->hit(StatusSecurityEvent::CATEGORY_LOGIN, 'failed_logins', null, $ip, (array) ($logins['failed'] ?? []), [
                    'ip' => $ip,
                ]);
            }

            if (filled($account)) {
                $this->hit(StatusSecurityEvent::CATEGORY_LOGIN, 'failed_logins_account', null, mb_strtolower(trim((string) $account)), (array) ($logins['failed_per_account'] ?? []), [
                    'ip' => $ip,
                    'subject' => self::mask((string) $account),
                ]);
            }
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    /**
     * A successful login: remembers the IP and country and reports a new one.
     */
    public function login(Authenticatable $user, ?string $ip, ?string $country): void
    {
        $logins = (array) config('system-status.security.logins', []);
        $checkCountry = (bool) ($logins['new_country'] ?? true);
        $checkIp = (bool) ($logins['new_ip'] ?? false);

        if (! $this->enabled() || $ip === null || $this->ignored($ip) || (! $checkCountry && ! $checkIp)) {
            return;
        }

        try {
            if (! $this->watches($user, $logins)) {
                return;
            }

            $userType = $user instanceof Model ? $user->getMorphClass() : $user::class;
            $userId = (string) $user->getAuthIdentifier();
            $country = self::country($country);
            $subject = self::userLabel($user);

            $known = DB::table('status_security_logins')
                ->where('user_type', $userType)
                ->where('user_id', $userId)
                ->get(['ip', 'country']);

            if ($known->isNotEmpty()) {
                if ($checkIp && ! $known->contains('ip', $ip)) {
                    $this->report(StatusSecurityEvent::CATEGORY_LOGIN, 'new_login_ip', null, "{$userType}:{$userId}:{$ip}", [
                        'ip' => $ip, 'subject' => $subject, 'country' => $country,
                    ]);
                }

                $countries = $known->pluck('country')->filter()->unique();
                if ($checkCountry && $country !== null && $countries->isNotEmpty() && ! $countries->contains($country)) {
                    $this->report(StatusSecurityEvent::CATEGORY_LOGIN, 'new_login_country', null, "{$userType}:{$userId}:{$country}", [
                        'ip' => $ip, 'subject' => $subject, 'country' => $country,
                        'details' => ['known_countries' => $countries->values()->all()],
                    ]);
                }
            }

            $now = now();
            $updated = DB::table('status_security_logins')
                ->where('user_type', $userType)
                ->where('user_id', $userId)
                ->where('ip', $ip)
                ->update(array_filter(['last_seen_at' => $now, 'country' => $country]));

            if ($updated === 0) {
                DB::table('status_security_logins')->insert([
                    'user_type' => $userType,
                    'user_id' => $userId,
                    'ip' => $ip,
                    'country' => $country,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    /**
     * A StatusSignal call: checks the component's volume limits (security.signals).
     */
    public function signal(string $component, ?string $ip): void
    {
        // Not config('….signals.'.$component): a component or action name may contain a dot.
        $rule = ((array) config('system-status.security.signals', []))[$component] ?? null;

        if (! $this->enabled() || ! is_array($rule)) {
            return;
        }

        try {
            $minutes = (int) ($rule['minutes'] ?? 60);

            $this->hit(StatusSecurityEvent::CATEGORY_SIGNAL, 'signal_volume', $component, $component, [
                'max' => $rule['max'] ?? 0, 'minutes' => $minutes,
            ], []);

            if ($ip !== null && ! $this->ignored($ip)) {
                $this->hit(StatusSecurityEvent::CATEGORY_SIGNAL, 'signal_volume_ip', $component, $component.':'.$ip, [
                    'max' => $rule['max_per_ip'] ?? 0, 'minutes' => $minutes,
                ], ['ip' => $ip]);
            }
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    /**
     * A risky action reported from code (StatusSecurity::action()).
     *
     * @param  array<string, scalar|null>  $details  no secrets or personal data
     */
    public function action(string $action, ?Authenticatable $actor = null, array $details = [], ?string $ip = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $rule = ((array) config('system-status.security.actions', []))[$action] ?? null;
            $rule = is_array($rule) ? $rule : [];
            $actorKey = $actor ? ($actor instanceof Model ? $actor->getMorphClass() : $actor::class).':'.$actor->getAuthIdentifier() : 'system';
            $attributes = [
                'ip' => $ip,
                'subject' => $actor ? self::userLabel($actor) : 'system',
                'details' => self::details($details),
            ];

            if (! empty($rule['alert'])) {
                // Every occurrence is its own finding.
                $this->report(StatusSecurityEvent::CATEGORY_ACTION, 'action', $action, $actorKey.':'.Str::uuid(), $attributes);

                return;
            }

            if (! empty($rule['max']) && $this->hit(StatusSecurityEvent::CATEGORY_ACTION, 'action_volume', $action, $actorKey, $rule, $attributes)) {
                return;
            }

            if ($this->inQuietHours()) {
                $this->report(StatusSecurityEvent::CATEGORY_ACTION, 'action_quiet', $action, $actorKey, $attributes);
            }
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    /**
     * The first of $needles found in $haystack (both compared in lower case), or null.
     *
     * @param  array<int, mixed>  $needles
     */
    private static function firstIn(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            $needle = mb_strtolower((string) $needle);

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $needle;
            }
        }

        return null;
    }

    /**
     * "sa***@example.com", "ah***ad": enough to recognise an account, not to read it.
     */
    public static function mask(string $account): string
    {
        $account = mb_strtolower(trim($account));

        if (str_contains($account, '@')) {
            [$local, $domain] = explode('@', $account, 2);

            return mb_substr($local, 0, 2).'***@'.Str::limit($domain, 60, '');
        }

        return mb_strlen($account) > 6
            ? mb_substr($account, 0, 2).'***'.mb_substr($account, -2)
            : mb_substr($account, 0, 1).'***';
    }

    /**
     * Counts one hit; more than $rule['max'] within $rule['minutes'] reports a finding.
     *
     * @param  array<string, mixed>  $settings  max and minutes
     * @param  array<string, mixed>  $attributes
     */
    private function hit(string $category, string $type, ?string $rule, string $key, array $settings, array $attributes): ?StatusSecurityEvent
    {
        $max = (int) ($settings['max'] ?? 0);
        $minutes = (int) ($settings['minutes'] ?? 0);

        if ($max <= 0 || $minutes <= 0) {
            return null;
        }

        $store = $this->store();
        $counter = self::PREFIX.'count:'.sha1($type."\x1f".$rule."\x1f".$key).':'.intdiv(now()->getTimestamp(), $minutes * 60);
        $store->add($counter, 0, $minutes * 60 * 2);
        $count = (int) $store->increment($counter);

        if ($count <= $max) {
            return null;
        }

        return $this->report($category, $type, $rule, $key, $attributes + [
            'hits' => $count,
            'window_minutes' => $minutes,
            'max' => $max,
        ]);
    }

    /**
     * Stores and announces a finding, or adds a hit to the same finding inside the cooldown.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function report(string $category, string $type, ?string $rule, string $key, array $attributes): ?StatusSecurityEvent
    {
        $store = $this->store();
        $finding = self::PREFIX.'finding:'.sha1($type."\x1f".$rule."\x1f".$key);
        $cooldown = max(1, (int) config('system-status.security.cooldown_minutes', 30)) * 60;

        if (! $store->add($finding, 0, $cooldown)) {
            $this->touch($finding);

            return null;
        }

        $now = now();
        $event = StatusSecurityEvent::query()->create([
            'category' => $category,
            'type' => $type,
            'rule' => $rule !== null ? Str::limit($rule, 100, '') : null,
            'ip' => $attributes['ip'] ?? null,
            'subject' => isset($attributes['subject']) ? Str::limit((string) $attributes['subject'], 191, '') : null,
            'country' => $attributes['country'] ?? null,
            'hits' => (int) ($attributes['hits'] ?? 1),
            'window_minutes' => $attributes['window_minutes'] ?? null,
            'max' => $attributes['max'] ?? null,
            'details' => ($attributes['details'] ?? null) ?: null,
            'detected_at' => $now,
            'last_seen_at' => $now,
        ]);

        $store->put($finding, $event->id, $cooldown);
        $this->announce($event);

        return $event;
    }

    /**
     * One more hit on a finding that goes on, written at most once per TOUCH_SECONDS.
     */
    private function touch(string $finding): void
    {
        $store = $this->store();
        $store->add($finding.':extra', 0, 3600);
        $store->increment($finding.':extra');

        if (! $store->add($finding.':touch', 1, self::TOUCH_SECONDS)) {
            return;
        }

        $id = (int) $store->get($finding);
        $extra = (int) $store->pull($finding.':extra', 0);

        if ($id > 0 && $extra > 0) {
            StatusSecurityEvent::query()->whereKey($id)->increment('hits', $extra, ['last_seen_at' => now()]);
        }
    }

    private function announce(StatusSecurityEvent $event): void
    {
        $text = '🛡️ Suspicious activity: '.$event->describe('en').'.';

        if ($event->category === StatusSecurityEvent::CATEGORY_REQUEST && $event->window_minutes) {
            $text .= ' Rule: '.$event->rule.' (more than '.$event->max.' in '.$event->window_minutes.' min).';
        }

        $channels = config('system-status.security.alert_channels');
        $this->alerter->send($text, is_array($channels) && $channels !== [] ? array_values($channels) : null);
        $this->alerter->event('security.suspicious_activity', [
            'id' => $event->id,
            'category' => $event->category,
            'type' => $event->type,
            'rule' => $event->rule,
            'ip' => $event->ip,
            'subject' => $event->subject,
            'country' => $event->country,
            'hits' => $event->hits,
            'window_minutes' => $event->window_minutes,
            'max' => $event->max,
            'details' => $event->details,
            'text' => $event->describe('en'),
            'detected_at' => $event->detected_at->toIso8601String(),
        ]);

        try {
            event(new SuspiciousActivityDetected($event));
        } catch (Throwable $e) {
            Log::warning('A SuspiciousActivityDetected listener failed.', ['exception' => $e::class]);
        }
    }

    /**
     * @param  array<string, mixed>  $logins
     */
    private function watches(Authenticatable $user, array $logins): bool
    {
        if (filled($logins['watch_gate'] ?? null)) {
            return Gate::forUser($user)->allows($logins['watch_gate']);
        }

        $roles = array_values(array_filter((array) ($logins['watch_roles'] ?? [])));
        if ($roles !== []) {
            return method_exists($user, 'hasRole') && $user->hasRole($roles);
        }

        return true;
    }

    private function inQuietHours(): bool
    {
        $quiet = config('system-status.security.quiet_hours');

        if (! is_array($quiet) || blank($quiet['from'] ?? null) || blank($quiet['to'] ?? null)) {
            return false;
        }

        $now = Carbon::now(config('app.timezone'))->format('H:i');
        $from = substr((string) $quiet['from'], 0, 5);
        $to = substr((string) $quiet['to'], 0, 5);

        return $from <= $to
            ? $now >= $from && $now < $to
            : $now >= $from || $now < $to;
    }

    private function ignored(string $ip): bool
    {
        $ignore = array_values(array_filter((array) config('system-status.security.ignore_ips', [])));

        return $ignore !== [] && IpUtils::checkIp($ip, $ignore);
    }

    private function store(): Repository
    {
        return Cache::store(config('system-status.security.store'));
    }

    private function failed(Throwable $e): void
    {
        Log::debug('Suspicious activity could not be recorded.', ['exception' => $e::class]);
    }

    /**
     * A two-letter country code from a proxy header, or null (Cloudflare uses XX and T1 for
     * unknown and Tor).
     */
    private static function country(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $value) && ! in_array($value, ['XX', 'T1'], true) ? $value : null;
    }

    private static function userLabel(Authenticatable $user): string
    {
        $name = $user instanceof Model ? $user->getAttribute('name') : null;

        return Str::limit(trim((is_string($name) && $name !== '' ? $name.' ' : '').'#'.$user->getAuthIdentifier()), 191, '');
    }

    /**
     * Scalars only, at most 10 keys, short strings.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, scalar|null>
     */
    private static function details(array $details): array
    {
        $clean = [];

        foreach (array_slice($details, 0, 10, true) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[Str::limit((string) $key, 40, '')] = is_string($value) ? Str::limit($value, 200) : $value;
            }
        }

        return $clean;
    }
}
