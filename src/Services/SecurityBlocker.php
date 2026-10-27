<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Technobase\SystemStatus\Models\StatusSecurityEvent;
use Throwable;

/**
 * Auto-block (security.block): IPs with a serious finding get 403 for a while, optionally also at
 * Cloudflare. The block lives in status_security_blocks; the middleware reads a cache key per IP
 * so a request costs one cache read. Never throws.
 */
class SecurityBlocker
{
    private const KEY = 'status:security:blocked:';

    public function enabled(): bool
    {
        return (bool) config('system-status.enabled', true)
            && (bool) config('system-status.security.enabled', false)
            && (bool) config('system-status.security.block.enabled', false);
    }

    /**
     * True when this finding should block its IP.
     */
    public function shouldBlock(StatusSecurityEvent $event): bool
    {
        if (! $this->enabled() || $event->ip === null || $this->protected($event->ip)) {
            return false;
        }

        $block = (array) config('system-status.security.block', []);

        return in_array($event->type, (array) ($block['types'] ?? []), true)
            || ($event->severity === StatusSecurityEvent::SEVERITY_CRITICAL && ($block['critical'] ?? true));
    }

    /**
     * Blocks the finding's IP (or extends its block). Returns when the block ends.
     */
    public function block(StatusSecurityEvent $event): ?CarbonInterface
    {
        try {
            $until = now()->addMinutes(max(1, (int) config('system-status.security.block.minutes', 60)));
            $existing = DB::table('status_security_blocks')->where('ip', $event->ip)->first();

            if ($existing) {
                DB::table('status_security_blocks')->where('id', $existing->id)->update([
                    'blocked_until' => $until,
                    'event_id' => $event->id,
                    'reason' => Str::limit($event->type, 191, ''),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('status_security_blocks')->insert([
                    'ip' => $event->ip,
                    'event_id' => $event->id,
                    'reason' => Str::limit($event->type, 191, ''),
                    'blocked_until' => $until,
                    'cloudflare_rule_id' => $this->cloudflareBlock($event),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->store()->put(self::KEY.$event->ip, $until->getTimestamp(), $until);

            return $until;
        } catch (Throwable $e) {
            Log::warning('An IP could not be blocked.', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * Unix time when the IP's block ends, or null. One cache read; used on every request.
     */
    public function blockedUntil(string $ip): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $until = $this->store()->get(self::KEY.$ip);

            return $until && (int) $until > now()->getTimestamp() ? (int) $until : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function unblock(string $ip): bool
    {
        try {
            $this->store()->forget(self::KEY.$ip);
            $row = DB::table('status_security_blocks')->where('ip', $ip)->first();

            if (! $row) {
                return false;
            }

            $this->cloudflareUnblock($row->cloudflare_rule_id);
            DB::table('status_security_blocks')->where('id', $row->id)->delete();

            return true;
        } catch (Throwable $e) {
            Log::warning('An IP could not be unblocked.', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * Removes ended blocks (and their Cloudflare rules). Returns how many.
     */
    public function expire(): int
    {
        $ended = DB::table('status_security_blocks')->where('blocked_until', '<=', now())->get();

        foreach ($ended as $row) {
            $this->unblock($row->ip);
        }

        return $ended->count();
    }

    /**
     * @return Collection<int, object>
     */
    public function active(): Collection
    {
        return DB::table('status_security_blocks')
            ->where('blocked_until', '>', now())
            ->orderByDesc('blocked_until')
            ->get();
    }

    /**
     * The block of an IP from the database (for the page), or null.
     */
    public function find(string $ip): ?object
    {
        try {
            return DB::table('status_security_blocks')->where('ip', $ip)->where('blocked_until', '>', now())->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Never blocked: ignore_ips, loopback and private addresses (a proxy or load balancer: when
     * trusted proxies are not set, every visitor seems to come from it), and an IP that Cloudflare
     * says is not the client (CF-Connecting-IP differs: trusted proxies are not set).
     */
    public function protected(string $ip): bool
    {
        $ignore = array_values(array_filter((array) config('system-status.security.ignore_ips', [])));

        if ($ignore !== [] && IpUtils::checkIp($ip, $ignore)) {
            return true;
        }

        if (IpUtils::checkIp($ip, ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7', 'fe80::/10'])) {
            return true;
        }

        try {
            $client = app()->bound('request') ? request()->header('CF-Connecting-IP') : null;
        } catch (Throwable) {
            $client = null;
        }

        return filled($client) && $client !== $ip;
    }

    private function cloudflareBlock(StatusSecurityEvent $event): ?string
    {
        [$token, $zone] = $this->cloudflare();

        if ($token === null) {
            return null;
        }

        try {
            $response = Http::timeout(5)->withToken($token)->post("https://api.cloudflare.com/client/v4/zones/{$zone}/firewall/access_rules/rules", [
                'mode' => 'block',
                'configuration' => ['target' => str_contains((string) $event->ip, ':') ? 'ip6' : 'ip', 'value' => $event->ip],
                'notes' => Str::limit('System Status: '.$event->describe('en'), 400),
            ]);

            return $response->successful() ? (string) $response->json('result.id') : null;
        } catch (Throwable $e) {
            Log::warning('Cloudflare block failed.', ['exception' => $e::class]);

            return null;
        }
    }

    private function cloudflareUnblock(?string $ruleId): void
    {
        [$token, $zone] = $this->cloudflare();

        if ($token === null || blank($ruleId)) {
            return;
        }

        try {
            Http::timeout(5)->withToken($token)->delete("https://api.cloudflare.com/client/v4/zones/{$zone}/firewall/access_rules/rules/{$ruleId}");
        } catch (Throwable $e) {
            Log::warning('Cloudflare unblock failed.', ['exception' => $e::class]);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function cloudflare(): array
    {
        $token = config('system-status.security.block.cloudflare.token');
        $zone = config('system-status.security.block.cloudflare.zone_id');

        return filled($token) && filled($zone) ? [(string) $token, (string) $zone] : [null, null];
    }

    private function store(): Repository
    {
        return Cache::store(config('system-status.security.store'));
    }
}
