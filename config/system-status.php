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
    // SYSTEM_STATUS_ENABLED=false turns the whole package off: no traffic counting, signals,
    // schedule, status:check runs or alerts, and the page and its endpoints answer 404 (route
    // names stay registered, so links to them keep working). Migrations, commands and
    // translations still load. true (the default) changes nothing.
    'enabled' => (bool) env('SYSTEM_STATUS_ENABLED', true),

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

    // With several servers running the scheduler, run the scheduled commands on one of them only
    // (onOneServer), so probes, incidents and alerts are not doubled. Needs a cache store that
    // supports locks (redis, memcached, database, dynamodb); skipped for one that does not. The
    // scheduler's locks use the default cache store: Schedule::useCache('database') in the
    // application keeps them working while Redis is down.
    'schedule_on_one_server' => true,

    // Cache store that keeps the outcome of the last status:check runs (null = the default
    // store), so the page can say "checks failing since 12:04" when a run fails. Shared between
    // servers, it should not be 'file' or 'array' on a multi-server setup.
    'heartbeat_store' => null,

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

    // Send the requests of the HTTP probes due in a run together (HttpEndpointProbe,
    // CdnStorageProbe, TelegramProbe and any ConcurrentProbe), so a run takes as long as the
    // slowest of them instead of their sum. false = one after another.
    'concurrent_probes' => true,

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

    // Deploy markers: `php artisan status:deploy 1.8.2` (or POST {path}/deploys from CI) records
    // a deploy. It shows as a line on the charts, and incidents that start soon after it say so.
    'deploys' => [
        // Bearer token for POST {path}/deploys. null = the endpoint is off (404).
        'token' => env('SYSTEM_STATUS_DEPLOY_TOKEN'),
        // An incident that starts within this many minutes after a deploy names it.
        'incident_window_minutes' => 30,
        // Also send "🚀 Deployed 1.8.2" to the alert channels.
        'announce' => false,
        // After `after_minutes`, compare each component's error rate and p95 with the same time
        // before the deploy, and send one alert when they got clearly worse. Off by default.
        'compare' => [
            'enabled' => false,
            'after_minutes' => 15,
            // Error rate rise in percentage points (0.3 % → 2.5 % is 2.2).
            'error_rate_increase' => 2,
            // p95 rise in percent (and at least 100 ms).
            'p95_increase_percent' => 50,
        ],
    ],

    // Suspicious activity: attack patterns in the traffic, login abuse, signal (SMS / OTP) cost
    // abuse and risky actions reported from code. Each finding is stored, shown in the page's
    // "Suspicious activity" panel and `php artisan status:security`, sent to the alert channels
    // and fires Technobase\SystemStatus\Events\SuspiciousActivityDetected. Off by default.
    // Request IPs come from $request->ip(): behind a load balancer or Cloudflare, configure
    // Laravel's trusted proxies, or every request looks like it comes from the proxy.
    'security' => [
        'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_ENABLED', false),
        // Cache store for the counters (null = the default store). Shared between servers, it
        // should not be 'file' or 'array' on a multi-server setup.
        'store' => null,
        // The same finding (rule + IP or account) alerts once per this many minutes; while it
        // goes on, its hit count keeps growing on the page.
        'cooldown_minutes' => 30,
        // Channels for security alerts (null = alerts.channels).
        'alert_channels' => null,
        // IPs and CIDR ranges never counted or blocked (office, monitoring, your own servers).
        'ignore_ips' => [],

        // Each check below has its own 'enabled' (on by default) to turn it off on its own, e.g.
        // SYSTEM_STATUS_SECURITY_NOT_FOUND=false in one environment. security.enabled still turns
        // everything off. Your own request, signal and action rules take 'enabled' => false too.

        // Auto-block: an IP with a finding of one of these types (or any critical finding) gets
        // 403 for `minutes`, from a middleware that runs before everything else. With a Cloudflare
        // API token (Zone > Firewall Services > Edit) and zone id, the IP is also blocked at
        // Cloudflare, so its requests never reach the server; the rule is removed when the block
        // ends. Unblock with `php artisan status:security --unblock=<ip>` or from the page.
        'block' => [
            'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_BLOCK', false),
            'minutes' => 60,
            'types' => ['scanner', 'injection', 'scanner_tools', 'honeypot', 'honeypot_field', 'canary'],
            'critical' => true,
            'cloudflare' => [
                'token' => env('SYSTEM_STATUS_CLOUDFLARE_TOKEN'),
                'zone_id' => env('SYSTEM_STATUS_CLOUDFLARE_ZONE_ID'),
            ],
        ],

        // Traps nobody but an attacker touches; one hit is a critical finding.
        'honeypot' => [
            'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_HONEYPOT', true),
            // Paths that no page links to, e.g. ones listed as Disallow in robots.txt.
            'paths' => [],
            // A hidden form field: add <x-system-status::honeypot /> inside a form. A bot that
            // fills every field fills this one too.
            'field' => 'website_url',
            // Fake secrets (an API key in an HTML comment, a password in robots.txt). Any request
            // that carries one in its URL, headers or body is someone using what they found.
            'canaries' => [],
        ],

        // Request rules, counted per IP. A request counts when every condition the rule sets
        // matches: `statuses` (HTTP status), `paths` (Str::is patterns on the path),
        // `contains` (text in the URL with its query string, decoded) and `user_agents` (text in
        // the User-Agent). All are case-insensitive; a rule without any counts every request.
        // More than `max` within `minutes` is a finding. Add your own rules the same way.
        'requests' => [
            // Denied requests: guessing tokens, IDs of other users, expired sessions in a loop.
            // `max_total`: the same rule summed over all IPs, for attacks spread over a botnet.
            'auth_errors' => ['enabled' => (bool) env('SYSTEM_STATUS_SECURITY_AUTH_ERRORS', true), 'statuses' => [401, 403, 419], 'max' => 30, 'max_total' => 500, 'minutes' => 5],
            // Clients that keep going after the rate limiter stopped them.
            'rate_limited' => ['enabled' => (bool) env('SYSTEM_STATUS_SECURITY_RATE_LIMITED', true), 'statuses' => [429], 'max' => 50, 'minutes' => 5],
            // Vulnerability scanners probing for files and tools a Laravel app never serves.
            'scanner' => [
                'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_SCANNER', true),
                'paths' => [
                    // Secrets and config files.
                    '.env', '.env.*', '*/.env', '*/.env.*', 'env', '*.env', '.git', '.git/*', '*/.git/*',
                    '.gitlab-ci.yml', '.svn*', '.hg*', '.bzr*', '.ds_store', '*/.ds_store', '.htaccess',
                    '.htpasswd', 'web.config', '.npmrc', '.dockerenv', 'docker-compose.y*ml', 'dockerfile',
                    '.aws/*', '.ssh/*', 'id_rsa*', '.bash_history', '.kube/*', '.s3cfg', '.vscode/*',
                    '.idea/*', 'composer.json', 'composer.lock', 'package.json', 'config.php', 'config.y*ml',
                    'config/database.php', 'storage/*.key', 'oauth-private.key', 'secrets.*', '.git-credentials',
                    // Laravel internals: logs, vendor, the Ignition RCE (CVE-2021-3129).
                    'storage/logs/*', '*/laravel.log', 'laravel.log', 'vendor/*', '_ignition/*',
                    'telescope/telescope-api/*', 'artisan', 'server.php', 'bootstrap/cache/*',
                    // Backups and database dumps.
                    '*.sql', '*.sql.*', '*.bak', '*.backup', '*.old', '*.orig', '*.swp', '*~', 'dump.*', 'db.*',
                    'database.*', '*.tar', '*.tar.gz', '*.tgz', '*.7z',
                    'www.zip', 'site.zip', 'backup.zip', 'html.zip', 'public.zip',
                    // WordPress and other CMSs.
                    'wp-admin*', 'wp-login.php', 'wp-content/*', 'wp-includes/*', 'wp-json/*', 'wp-config*',
                    '*/wp-admin/*', '*/wp-includes/*', '*/wp-login.php', 'wordpress/*', 'wp/*', 'xmlrpc.php',
                    // Database and admin tools.
                    'phpmyadmin*', '*/phpmyadmin*', 'pma', 'pma/*', 'myadmin*', 'mysqladmin*', 'dbadmin*',
                    'adminer*', 'sqlitemanager*', 'phppgadmin*', 'webdav*',
                    // Web shells, PHP probes and known exploit paths.
                    'phpinfo*', 'info.php', 'test.php', 'shell*.php', 'cmd.php', 'c99*.php', 'r57*.php',
                    'wso*.php', 'alfa*.php', 'eval-stdin.php', '*/eval-stdin.php', 'vendor/phpunit/*',
                    '*.asp', '*.aspx', '*.jsp', '*.cgi', '*.pl', 'cgi-bin/*', 'boaform/*', 'gponform/*',
                    'hnap1*', 'setup.cgi', 'manager/html*', 'jmx-console*',
                    'invoker/*', 'solr/*', 'struts*', 'actuator*', 'druid/*', 'nacos/*', 'geoserver/*',
                    'owa/*', 'ecp/*', 'autodiscover/*', 'remote/login*', 'dana-na/*', 'global-protect/*',
                    '_profiler*', 'elmah.axd', 'trace.axd', 'server-status', 'server-info',
                    'debug/default/view*', 'latest/meta-data*', 'computemetadata/*',
                ],
                'max' => 3,
                'max_total' => 300,
                'minutes' => 10,
                // Critical: one of these paths was served (2xx, not an HTML page), so a secret
                // file or tool may be exposed. HTML is skipped because a catch-all route answers
                // every path with the app's own page.
                'critical' => ['statuses' => [200, 206], 'not_html' => true],
            ],
            // Attack payloads in the URL: SQL injection, XSS, path traversal, file inclusion,
            // command injection, template injection, Log4Shell and cloud-metadata SSRF.
            'injection' => [
                'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_INJECTION', true),
                'contains' => [
                    'union select', 'union all select', 'union/**/select', '/**/', ' or 1=1', "' or '1'='1",
                    '" or "1"="1', "' or 1=1", 'sleep(', 'benchmark(', 'waitfor delay', 'pg_sleep',
                    'information_schema', 'load_file(', 'into outfile', 'xp_cmdshell', '@@version',
                    '<script', '</script', 'javascript:', 'onerror=', 'onload=', 'onmouseover=',
                    '<svg', '<iframe', 'document.cookie', 'alert(', 'prompt(',
                    '../', '..\\', '/etc/passwd', '/etc/shadow', 'win.ini', 'boot.ini', '/proc/self/',
                    'php://', 'file://', 'data://text', 'expect://', 'phar://', 'zip://', 'gopher://', 'dict://',
                    'base64_decode(', 'eval(', 'assert(', 'system(', 'shell_exec(', 'passthru(', 'popen(',
                    'proc_open(', 'cmd.exe', '/bin/sh', '/bin/bash', ';wget ', '|wget ', ';curl ', '|curl ',
                    '$(', '`id`', ';id;', '|id|', '${jndi:', '${${', '{{7*7}}', '${7*7}', '<%= 7*7', '#{7*7}',
                    '<?php', "\0", '169.254.169.254', 'metadata.google.internal', '100.100.100.200',
                ],
                'max' => 2,
                'max_total' => 100,
                'minutes' => 10,
                // Critical: the payload made the app fail, a sign the input reaches a query or
                // a command unchecked.
                'critical' => ['statuses' => [500]],
            ],
            // Known attack and scanning tools by their User-Agent.
            'scanner_tools' => [
                'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_SCANNER_TOOLS', true),
                'user_agents' => [
                    'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'nuclei', 'wpscan', 'dirbuster', 'gobuster',
                    'feroxbuster', 'ffuf', 'wfuzz', 'dirsearch', 'acunetix', 'nessus', 'openvas', 'netsparker',
                    'burpcollaborator', 'arachni', 'w3af', 'skipfish', 'whatweb', 'jaeles', 'xsstrike',
                    'commix', 'hydra', 'zmeu', 'morfeus', 'censysinspect', 'l9explore', 'fuzz faster',
                    'nimbostratus',
                ],
                'max' => 1,
                'minutes' => 10,
            ],
            // Crawling or guessing URLs.
            'not_found' => ['enabled' => (bool) env('SYSTEM_STATUS_SECURITY_NOT_FOUND', true), 'statuses' => [404], 'max' => 100, 'max_total' => 5000, 'minutes' => 5],
        ],

        // Reading many different records: one signed-in user opening more than `max` different
        // URLs of the same route (orders/1, orders/2, …) within `minutes`. Catches scraping and
        // IDOR (changing the id in the URL to read other people's data). Counts successful GET
        // requests of routes with parameters; skip routes with `ignore_routes` (names, Str::is).
        'records' => [
            'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_RECORDS', true),
            'max' => 200,
            'minutes' => 10,
            'only_authenticated' => true,
            'ignore_routes' => [],
        ],

        // Logins, from Laravel's Illuminate\Auth\Events (Breeze, Fortify, Jetstream, Filament and
        // Auth::attempt() fire them). Passwords are never read; account names are masked.
        'logins' => [
            // All login checks below (failed, new country / IP, travel, session country).
            'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_LOGINS', true),
            // Failed logins per IP, and per account name (one account attacked from many IPs).
            'failed' => ['max' => 10, 'max_total' => 200, 'minutes' => 10],
            'failed_per_account' => ['max' => 5, 'minutes' => 10],
            // A user signs in from a country they never used before. The country comes from a
            // request header set by the proxy (Cloudflare: CF-IPCountry); null turns it off.
            'new_country' => true,
            'country_header' => 'CF-IPCountry',
            // A user signs in from an IP they never used before. Noisy for mobile users, useful
            // for a small backoffice team.
            'new_ip' => false,
            // Critical: a user signs in from another country within this many minutes of a
            // login from a different one (too fast to travel). null = off.
            'impossible_travel_minutes' => 120,
            // Critical: a signed-in session is suddenly used from another country (a stolen
            // session cookie). Needs the country header.
            'session_country_change' => true,
            // Which users get new-country / new-IP checks: users passing this Gate ability, else
            // users with one of these roles (hasRole), else everybody.
            'watch_gate' => null,
            'watch_roles' => [],
        ],

        // Signal volume per component (StatusSignal::success() / failure()): more than `max`
        // calls within `minutes`, or more than `max_per_ip` from one IP, is a finding. Catches
        // bots that burn an SMS / OTP budget. E.g. 'otp' => ['max' => 500, 'max_per_ip' => 10, 'minutes' => 60].
        'signals' => [],

        // Risky actions reported from code: StatusSecurity::action('roles.changed', $user).
        // 'alert' => true alerts every time; 'max' + 'minutes' alerts when one user does it more
        // often than that. Actions not listed here only alert in quiet hours.
        'actions' => [
            'roles.changed' => ['alert' => true],
            'export' => ['max' => 10, 'minutes' => 60],
            'bulk_delete' => ['max' => 3, 'minutes' => 60],
        ],
        // Report these changes as risky actions by themselves: roles or permissions given or taken
        // (spatie/laravel-permission with permission.events_enabled) and new API tokens (Laravel
        // Sanctum). tokens is off by default because many apps give customers a token at every
        // login: list the staff models whose new tokens matter (e.g. [App\Models\Admin::class]),
        // or true for the users logins.watch_gate / watch_roles watch (everybody when neither is set).
        'watch_changes' => [
            'tokens' => false,
            'roles' => true,
        ],

        // Code files for SecurityFilesProbe: their hashes are saved at every status:deploy (or
        // `php artisan status:security --baseline`); a PHP file added or changed outside a deploy
        // is a major outage. Web shells in upload folders are reported by WebShellProbe.
        'files' => [
            // bootstrap/cache is left out: php artisan optimize rewrites it outside deploys.
            'paths' => ['app', 'bootstrap/app.php', 'bootstrap/providers.php', 'config', 'database', 'public', 'resources/views', 'routes', 'lang'],
            'extensions' => ['php', 'phtml', 'phar', 'php5', 'php7', 'phps', 'htaccess', 'user.ini'],
            'baseline' => storage_path('app/system-status/files.json'),
            'baseline_on_deploy' => true,
        ],
        // Any reported action within these hours (app timezone) alerts, e.g.
        // ['from' => '23:00', 'to' => '06:00']. null = off.
        'quiet_hours' => null,
    ],

    'retention_days' => [
        'checks' => 7,
        'route_buckets' => 7,
        'daily' => 90,
        'incidents' => 90,
        // Suspicious activity findings and the IPs / countries users signed in from.
        'security' => 90,
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
