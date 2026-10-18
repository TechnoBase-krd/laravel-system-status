<?php

use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Technobase\SystemStatus\Probes\QueueHeartbeatProbe;
use Technobase\SystemStatus\Probes\SpatieCheckProbe;

/*
 * technobase/laravel-system-status
 *
 * Publish this file (php artisan vendor:publish --tag=system-status-config) and describe the
 * application's components under `groups`.
 *
 * Each component gets its status from up to three sources, and the worst one wins:
 *  - probes: read-only checks run by `status:check` every `every` minutes;
 *  - routes: real traffic, matched by route name (`names`, first pass) or URI pattern (`uris`,
 *    second pass; the longest matching pattern wins). 5xx responses count as failures;
 *  - signal: success / failure reported from code with StatusSignal, for calls that cannot be
 *    probed without cost or side effects (OTP gateways, social login, paid AI APIs).
 * Display names are system-status::status.status_component_<key> and
 * system-status::status.status_group_<group>; add your own in
 * lang/vendor/system-status/<locale>/status.php.
 */
return [
    // The page: URL, middleware, who may open it and which layout it extends.
    'path' => 'system-status',
    'middleware' => ['web', 'auth'],
    'access' => [
        // Roles checked with $user->hasRole() (spatie/laravel-permission).
        'roles' => ['Super Admin'],
        // A Gate ability name (e.g. 'viewSystemStatus'). When set, it replaces the role check.
        'gate' => null,
    ],
    'layout' => 'layouts.app',
    // Stack names the layout renders; the page pushes its CSS, modal and script into them.
    'stacks' => [
        'styles' => 'styles',
        'modals' => 'modals',
        'scripts' => 'scripts',
    ],

    // Absolute path of a CSS file added after the package styles, for branding. The page uses
    // --sst-* variables on .sst (e.g. .sst { --sst-accent: #0f766e; }).
    'extra_css' => null,

    // Seconds between automatic refreshes of the page, per range; 'default' for the others.
    'refresh_seconds' => [
        '1h' => 20,
        'default' => 60,
    ],

    // Count every request per component (global middleware) and schedule status:check (every
    // minute) and status:prune (daily). Turn off to register them yourself.
    'record_traffic' => true,
    'schedule' => true,

    // Redis connection for the per-minute traffic counters (null = the default connection).
    'redis_connection' => null,

    'thresholds' => [
        'window_minutes' => 15,
        'min_requests' => 20,
        'min_signals' => 3,
        'degraded_error_rate' => 5,
        'outage_error_rate' => 25,
        'slow_request_ms' => 2000,
        'slow_probe_ms' => 3000,
        // A component changes status only after this many runs in a row agree.
        'confirm_runs' => 2,
        // Scheduler and queue heartbeats older than this are an outage.
        'heartbeat_stale_minutes' => 3,
        'probe_timeout_seconds' => 10,
    ],

    // Where the HTTP probes send their requests. Empty = APP_URL. Set it to reach the app
    // directly (e.g. http://nginx inside Docker); the request then carries APP_URL's host.
    'probe_base_url' => env('STATUS_PROBE_BASE_URL'),

    // false skips TLS verification for the HTTP probes. Only for a probe base URL whose
    // certificate does not match (an IP or an internal name).
    'probe_verify_ssl' => (bool) env('STATUS_PROBE_VERIFY_SSL', true),

    // User-Agent of the HTTP probes; allow it in a firewall (e.g. Cloudflare) in front of the app.
    'probe_user_agent' => 'SystemStatusProbe/1.0',

    // Status changes are sent to this Telegram chat (laravel-notification-channels/telegram,
    // bot token in services.telegram-bot-api.token). Empty = logged only. Never sent in `local`.
    // Every confirmed change also fires Technobase\SystemStatus\Events\ComponentStatusChanged,
    // so the application can send alerts anywhere else.
    'alerts' => [
        'enabled' => true,
        'telegram_chat_id' => env('SYSTEM_STATUS_TELEGRAM_CHAT_ID'),
        'prefix' => '['.env('APP_NAME', 'Laravel').' status]',
    ],

    'retention_days' => [
        'checks' => 7,
        'route_buckets' => 7,
        'daily' => 90,
        'incidents' => 90,
    ],

    // Queues that get a heartbeat job every minute. connection null = the default connection.
    'queues' => [
        ['connection' => null, 'queue' => 'default'],
    ],

    // Routes that are not counted at all (tools, health endpoints, this page's own polling).
    'ignore_uris' => [
        'horizon*', 'pulse*', 'telescope*', '_debugbar*', '_ignition*', 'up', 'sanctum/*', 'system-status*',
    ],

    // Used when no component rule matches: API routes, then everything else.
    'fallback' => [
        'api/*' => 'api_other',
        '*' => 'bo_other',
    ],

    'groups' => [
        'platform' => [
            'database' => [
                'probes' => [
                    ['class' => SpatieCheckProbe::class, 'with' => ['check' => DatabaseCheck::class], 'every' => 1],
                ],
            ],
            'redis' => [
                'probes' => [
                    ['class' => SpatieCheckProbe::class, 'with' => ['check' => RedisCheck::class], 'every' => 1],
                    ['class' => SpatieCheckProbe::class, 'with' => ['check' => CacheCheck::class], 'every' => 1],
                ],
            ],
            'queue' => [
                'probes' => [
                    ['class' => SpatieCheckProbe::class, 'with' => ['check' => HorizonCheck::class], 'every' => 1],
                    ['class' => QueueHeartbeatProbe::class, 'every' => 1],
                ],
            ],
            // Status comes from the heartbeat that `status:check` itself writes.
            'scheduler' => [],
        ],
        'mobile_api' => [
            'api_other' => [],
        ],
        'backoffice' => [
            'bo_other' => [],
        ],
    ],
];
