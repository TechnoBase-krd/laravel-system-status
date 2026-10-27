<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Technobase\SystemStatus\Models\StatusSecurityEvent;

/**
 * Lists recent suspicious activity findings.
 */
class SecurityCommand extends Command
{
    protected $signature = 'status:security
        {--hours=24 : How far back to look}
        {--type= : Only this rule (auth_errors, scanner, failed_logins, new_login_country, signal_volume, action, …)}
        {--ip= : Only this IP}
        {--limit=50 : At most this many rows}';

    protected $description = 'List suspicious activity: attack patterns, login abuse, signal volume and risky actions.';

    public function handle(): int
    {
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
            ['Detected', 'Last seen', 'Rule', 'What happened', 'Hits', 'IP'],
            $events->map(fn (StatusSecurityEvent $event) => [
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
