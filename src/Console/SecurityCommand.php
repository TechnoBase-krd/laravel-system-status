<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Technobase\SystemStatus\Models\StatusSecurityEvent;
use Technobase\SystemStatus\Services\SecurityBlocker;
use Technobase\SystemStatus\Support\FileManifest;

/**
 * Lists recent suspicious activity findings.
 */
class SecurityCommand extends Command
{
    protected $signature = 'status:security
        {--hours=24 : How far back to look}
        {--type= : Only this rule (auth_errors, scanner, failed_logins, new_login_country, signal_volume, action, …)}
        {--ip= : Only this IP}
        {--limit=50 : At most this many rows}
        {--blocked : List the blocked IPs}
        {--unblock= : Unblock this IP (also at Cloudflare)}
        {--expire : End blocks whose time is up (scheduled every 5 minutes)}
        {--baseline : Save the current code files as the baseline of SecurityFilesProbe}';

    protected $description = 'List suspicious activity, manage blocked IPs and save the code file baseline.';

    public function handle(SecurityBlocker $blocker): int
    {
        if ($this->option('baseline')) {
            $count = FileManifest::saveBaseline();
            $this->components->info("Baseline saved: {$count} code files (".FileManifest::baselinePath().').');

            return self::SUCCESS;
        }

        if ($this->option('expire')) {
            $this->line($blocker->expire().' block(s) ended.');

            return self::SUCCESS;
        }

        if (filled($ip = $this->option('unblock'))) {
            $blocker->unblock((string) $ip)
                ? $this->components->info("{$ip} unblocked.")
                : $this->components->warn("{$ip} was not blocked.");

            return self::SUCCESS;
        }

        if ($this->option('blocked')) {
            $blocks = $blocker->active();

            if ($blocks->isEmpty()) {
                $this->components->info('No IP is blocked.');

                return self::SUCCESS;
            }

            $this->table(['IP', 'Blocked until', 'Reason', 'Cloudflare'], $blocks->map(fn ($block) => [
                $block->ip,
                (string) $block->blocked_until,
                $block->reason,
                $block->cloudflare_rule_id ? 'yes' : '',
            ])->all());

            return self::SUCCESS;
        }

        if (! config('system-status.security.enabled', false)) {
            $this->components->warn('Suspicious activity monitoring is off (SYSTEM_STATUS_SECURITY_ENABLED=false). Showing stored findings only.');
        }

        $hours = max(1, (int) $this->option('hours'));

        $events = StatusSecurityEvent::query()
            ->where('last_seen_at', '>=', now()->subHours($hours))
            ->when($this->option('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($this->option('ip'), fn ($query, $ip) => $query->where('ip', $ip))
            ->latest('detected_at')
            ->latest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($events->isEmpty()) {
            $this->components->info("No suspicious activity in the last {$hours} h.");

            return self::SUCCESS;
        }

        $this->table(
            ['', 'Detected', 'Last seen', 'Rule', 'What happened', 'Hits', 'IP'],
            $events->map(fn (StatusSecurityEvent $event) => [
                $event->isCritical() ? '<error>CRITICAL</error>' : '',
                $event->detected_at->format('Y-m-d H:i'),
                $event->last_seen_at->format('H:i'),
                $event->type.($event->rule && $event->rule !== $event->type ? ':'.$event->rule : ''),
                $event->describe('en'),
                $event->hits,
                $event->ip ?? '',
            ])->all(),
        );

        $ips = $events->whereNotNull('ip')->countBy('ip')->sortDesc()->take(5);
        if ($ips->count() > 1) {
            $this->line('Most findings: '.$ips->map(fn ($count, $ip) => "{$ip} ({$count})")->implode(', '));
        }

        return self::SUCCESS;
    }
}
