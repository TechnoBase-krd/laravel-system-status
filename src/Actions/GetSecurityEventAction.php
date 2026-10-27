<?php

namespace Technobase\SystemStatus\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Technobase\SystemStatus\Http\Middleware\AuthorizeSystemStatusManagement;
use Technobase\SystemStatus\Models\StatusSecurityEvent;
use Technobase\SystemStatus\Services\SecurityBlocker;

/**
 * One suspicious activity finding for the page's detail dialog: what happened, the rule, the
 * request facts, other findings from the same IP and what to do about it.
 */
class GetSecurityEventAction
{
    public const SAME_IP_DAYS = 7;

    public function execute(StatusSecurityEvent $event): array
    {
        $sameIp = $event->ip === null ? collect() : StatusSecurityEvent::query()
            ->where('ip', $event->ip)
            ->whereKeyNot($event->id)
            ->where('last_seen_at', '>=', now()->subDays(self::SAME_IP_DAYS))
            ->latest('last_seen_at')
            ->limit(10)
            ->get();

        $details = collect((array) $event->details)->except(['demo', 'blocked_until'])->all();
        $block = $event->ip !== null ? app(SecurityBlocker::class)->find($event->ip) : null;

        return [
            ...$this->row($event),
            'rule_label' => $this->ruleLabel($event),
            'limit' => $event->max !== null && $event->window_minutes
                ? __('system-status::status.status_security_limit', ['max' => $event->max, 'minutes' => $event->window_minutes])
                : null,
            'country' => $event->country,
            'subject' => $event->subject,
            'first_seen' => $event->detected_at->format('Y-m-d H:i:s'),
            'last_seen' => $event->last_seen_at->format('Y-m-d H:i:s'),
            'duration' => $event->detected_at->diffForHumans($event->last_seen_at, true),
            'request' => array_filter([
                'method' => $details['method'] ?? null,
                'status' => $details['status'] ?? null,
                'path' => $details['path'] ?? null,
            ]),
            'details' => collect($details)->except(['method', 'status', 'path'])
                ->map(fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value)
                ->all(),
            'same_ip' => $sameIp->map(fn (StatusSecurityEvent $other) => $this->row($other))->all(),
            'advice' => $this->advice($event),
            'blocked_until' => $block ? Carbon::parse($block->blocked_until)->format('Y-m-d H:i') : null,
            'can_unblock' => $block !== null && AuthorizeSystemStatusManagement::allows(request()->user()),
        ];
    }

    /**
     * The fields the panel list and the dialog share.
     */
    public function row(StatusSecurityEvent $event): array
    {
        return [
            'id' => $event->id,
            'category' => $event->category,
            'type' => $event->type,
            'severity' => $event->severity,
            'rule' => $event->rule,
            'icon' => $event->icon(),
            'blocked' => isset($event->details['blocked_until']),
            'text' => $event->describe(),
            'ip' => $event->ip,
            'hits' => $event->hits,
            'at' => $event->detected_at->format('Y-m-d H:i'),
            'ago' => $event->last_seen_at->diffForHumans(),
            'active' => $this->active($event),
        ];
    }

    /**
     * Still going on: seen within its rule's window (at least 10 minutes).
     */
    public function active(StatusSecurityEvent $event): bool
    {
        return $event->last_seen_at->gte(now()->subMinutes(max(10, (int) $event->window_minutes)));
    }

    private function ruleLabel(StatusSecurityEvent $event): string
    {
        $key = 'system-status::status.status_security_rule_'.$event->type;

        if (! Lang::has($key, null, false) && str_ends_with($event->type, '_critical')) {
            $key = 'system-status::status.status_security_rule_critical';
        }

        return Lang::has($key, null, false)
            ? __($key, ['rule' => (string) $event->rule])
            : str_replace('_', ' ', ucfirst((string) ($event->rule ?? $event->type)));
    }

    private function advice(StatusSecurityEvent $event): string
    {
        $key = 'system-status::status.status_security_advice_'.$event->type;

        if (! Lang::has($key, null, false) && str_ends_with($event->type, '_critical')) {
            $key = 'system-status::status.status_security_advice_critical';
        }

        if (! Lang::has($key, null, false)) {
            $key = 'system-status::status.status_security_advice_'.$event->category;
        }

        return __($key);
    }
}
