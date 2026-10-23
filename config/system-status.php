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
 * Other per-component keys: 'thresholds' => [...] (overrides the global thresholds),
 * 'depends_on' => ['database'] (when that component fails too, this one shows "Affected by
 * Database" and its alert is folded into the database's), 'sla' => 99.9 (monthly target).
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
        // A Gate ability for posting incidents, updates and postmortems and for scheduling
        // maintenance from the page (e.g. 'manageSystemStatus'). null = everyone who can open it.
        'manage_gate' => null,
    ],
    // A Blade layout of the application with @yield('title'), @yield('content') and the stacks
    // below, plus Font Awesome (5 or 6). No jQuery or Bootstrap is needed. 'standalone' (or null)
    // uses the package's own full page instead; so does a layout view that does not exist.
    'layout' => 'layouts.app',
    // Stack names the layout renders; the page pushes its CSS, modal and script into them.
    'stacks' => [
        'styles' => 'styles',
        'modals' => 'modals',
        'scripts' => 'scripts',
    ],

    // Assets of the standalone page. Point them at local copies for an offline server or a
    // strict Content-Security-Policy.
    'standalone' => [
        'css' => [
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
        ],
        'js' => [],
        // Locales shown right to left.
        'rtl_locales' => ['ar', 'ku', 'ckb', 'fa', 'he', 'ur'],
        // Name in the top bar and the browser title.
        'brand' => 'TechnoBase',
        // Where the back button goes when there is no previous page of the application
        // (null = the home page, '/').
        'back_url' => null,
    ],

    // The Tailwind CDN script that styles the page's dialogs, panels and forms (prefixed tw:*
    // classes, no global reset, so the host layout is not touched). Point it at a local copy for
    // an offline server or a strict Content-Security-Policy.
    'tailwind_cdn' => 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.3.3/dist/index.global.js',

    // Absolute path of a CSS file added after the package styles, for branding. The page uses
    // --sst-* variables on .sst (e.g. .sst { --sst-accent: #0f766e; }).
    'extra_css' => null,

    // Incidents shown in "Past incidents" at first and per "Show more" click (1 to 100).
    'incidents_per_page' => 10,

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

    // Global thresholds. A component can override min_requests, min_signals,
    // degraded_error_rate, outage_error_rate, latency_degraded_p95_ms, latency_outage_p95_ms and
    // confirm_runs with its own 'thresholds' => ['degraded_error_rate' => 2, ...].
    'thresholds' => [
        'window_minutes' => 15,
        'min_requests' => 20,
        'min_signals' => 3,
        'degraded_error_rate' => 5,
        'outage_error_rate' => 25,
        'slow_request_ms' => 2000,
        'slow_probe_ms' => 3000,
        // Degraded / major outage when the p95 response time of a component's routes over the
        // window is above this many ms (needs min_requests). null = slowness alone never counts.
        'latency_degraded_p95_ms' => null,
        'latency_outage_p95_ms' => null,
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

    // Status changes, incident updates and reminders are sent to the channels below. Nothing is
    // sent in `local` (the alert is logged). Every confirmed change also fires
    // Technobase\SystemStatus\Events\ComponentStatusChanged, so the application can react in code.
    // Check the setup with: php artisan status:test-alert
    'alerts' => [
        // SYSTEM_STATUS_ALERTS_ENABLED=false turns every alert off (the events still fire).
        'enabled' => (bool) env('SYSTEM_STATUS_ALERTS_ENABLED', true),
        // Any of: telegram, mail, slack, discord.
        'channels' => ['telegram'],
        // telegram: laravel-notification-channels/telegram, bot token in services.telegram-bot-api.token.
        'telegram_chat_id' => env('SYSTEM_STATUS_TELEGRAM_CHAT_ID'),
        // mail: the application's default mailer.
        'mail_to' => array_filter(explode(',', (string) env('SYSTEM_STATUS_ALERT_MAIL_TO', ''))),
        // slack / discord: incoming webhook URLs.
        'slack_webhook' => env('SYSTEM_STATUS_SLACK_WEBHOOK'),
        'discord_webhook' => env('SYSTEM_STATUS_DISCORD_WEBHOOK'),
        'prefix' => '['.env('APP_NAME', 'Laravel').' status]',
        // JSON POSTed on component.status_changed, incident.posted / .updated / .postmortem and
        // maintenance.started / .ended. With a secret, the body is signed in the
        // X-System-Status-Signature header (sha256=<hmac>). E.g. [['url' => '…', 'secret' => '…']].
        'webhooks' => [],
        // A component that changes status this many times within `minutes` sends one "flapping"
        // alert, then stays quiet until it is stable for `minutes`. null turns this off.
        'flap' => ['changes' => 4, 'minutes' => 30],
        // Repeat "still down" for a major outage open longer than this, every this many minutes.
        // null = no reminders.
        'reminder_minutes' => env('SYSTEM_STATUS_REMINDER_MINUTES'),
    ],

    // Monthly uptime targets. A component's own 'sla' => 99.9 wins; null = no target. The 90-day
    // view marks components below target, and `php artisan status:report` lists them.
    'sla' => [
        'default_target' => null,
    ],

    // Email last month's uptime report on the 1st of each month (08:00) to these addresses.
    'reports' => [
        'mail_to' => array_filter(explode(',', (string) env('SYSTEM_STATUS_REPORT_MAIL_TO', ''))),
        // csv, html or pdf (pdf needs barryvdh/laravel-dompdf).
        'format' => 'csv',
        // Brand colour of the PDF header (the web report follows the page's --sst-accent).
        'accent' => '#6F2E8F',
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
