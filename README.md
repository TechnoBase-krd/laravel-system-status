# Laravel System Status

`technobase/laravel-system-status` adds a live status page to a Laravel backoffice, in the
style of githubstatus.com. It shows whether every part of the application works (database,
queues, APIs, backoffice screens, payment and delivery integrations), keeps 90 days of
history, opens incidents by itself and alerts you on Telegram.

![Overview](docs/screenshots/range-24h.png)

- **Probes**: small read-only checks that run every few minutes (database, Redis, Horizon,
  queues, HTTP endpoints, CDN, Telegram, and your own integrations).
- **Real traffic**: every request is counted per component and route; 5xx responses are failures.
- **Signals**: one line around calls that cost money or have side effects (SMS, OTP, social
  login, paid AI APIs) reports whether they worked.
- **No false alarms**: a status changes only after two checks in a row agree.
- **Incidents and alerts**: each change opens or closes an incident, sends one Telegram message
  and fires a Laravel event you can listen to.
- **History**: real time (1 hour), 24 hours, 7 days and 90 days.
- **Languages**: English, Arabic and Sorani Kurdish, right-to-left included.

Used in production by Khatun-Ecommerce and CloudNova-OnlineShopping.

---

## Contents

- [Screenshots](#screenshots)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Integrating it into an application](#integrating-it-into-an-application)
- [Configuration reference](#configuration-reference)
- [Probes](#probes)
- [Signals](#signals)
- [Reacting to status changes](#reacting-to-status-changes)
- [Access](#access)
- [Look and feel](#look-and-feel)
- [Translations](#translations)
- [Commands and schedule](#commands-and-schedule)
- [Database tables](#database-tables)
- [Claude Code skill](#claude-code-skill)
- [Deployment](#deployment)
- [Troubleshooting](#troubleshooting)
- [Upgrading and releases](#upgrading-and-releases)

---

## Screenshots

**The whole page** (90 days): banner, key numbers, every component with its history bars, and
past incidents.

![Full page](docs/screenshots/overview.png)

**Time ranges**: real time (1 hour, one bar per minute), 24 hours and 7 days.

| Real time (1 hour) | 7 days |
| --- | --- |
| ![1 hour](docs/screenshots/range-1h.png) | ![7 days](docs/screenshots/range-7d.png) |

**Component details**: requests and errors per hour, the routes with the most failures and the
latest checks. Click any component name.

| Healthy component | Component with an ongoing problem |
| --- | --- |
| ![Details](docs/screenshots/detail.png) | ![Degraded](docs/screenshots/detail-degraded.png) |

**Arabic (right to left)** and **phone**:

| Arabic | Phone |
| --- | --- |
| ![Arabic](docs/screenshots/arabic-rtl.png) | ![Phone](docs/screenshots/mobile.png) |

The screenshots come from a demo shop with sample data.

---

## How it works

```
                 every minute                              every request
  scheduler ──▶ status:check ◀── Redis counters ◀── RecordRouteHealth middleware
                    │   ▲
                    │   └── StatusSignal::success() / failure()   (your code)
                    ▼
        probes ──▶ worst source wins ──▶ 2 runs agree? ──▶ status changes
                                                            │
                         incidents · daily history · Telegram alert · ComponentStatusChanged event
                                                            │
                               page /system-status ◀────────┘  (refreshes itself)
```

1. You describe your application as **components** in groups (for example *Platform*,
   *Mobile API*, *Backoffice*, *Integrations*) in `config/system-status.php`.
2. Each component gets its status from up to three sources, and **the worst one wins**:
   - its **probes**, run every `every` minutes by `status:check`;
   - its **routes**: real requests matched by route name or URI. Over the last 15 minutes an
     error rate of 5 % or more is *degraded*, 25 % or more is a *major outage*, as long as there
     were at least 20 requests;
   - its **signal**: success and failure reports from your code (at least 3 calls).
3. A new status is **confirmed** only when two runs in a row agree, so one slow answer never
   pages anyone.
4. A confirmed change opens, upgrades or resolves an **incident**, sends one **Telegram**
   message and fires `ComponentStatusChanged`.
5. The scheduler cannot report its own outage: the first run after a gap records a resolved
   scheduler incident for the gap, and the page shows the scheduler red while runs are late.

A component with no data yet shows **No data**, never a fake *Operational*. A probe that is not
configured (missing API key, integration turned off) shows **Not configured** and does not
count as a failure.

---

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer |
| Laravel | 12 or 13 |
| Database | **PostgreSQL** (the detail popup and the daily rollup use PostgreSQL SQL) |
| Redis | for the per-minute traffic counters (any connection; see `redis_connection`) |
| Scheduler | `php artisan schedule:work` or a cron entry running `schedule:run` every minute |
| `spatie/laravel-health` | installed automatically; used for the database, Redis, cache and Horizon checks |
| Backoffice layout | a Blade layout with `@yield('title')`, `@yield('content')` and stacks for styles, modals and scripts |
| Front end | jQuery, the Bootstrap 4 modal plugin and Font Awesome 5 or 6 on that layout (the Limitless theme has all three) |

Optional:

- `spatie/laravel-permission`, for the default role check (or use a Gate, see [Access](#access));
- `laravel-notification-channels/telegram`, for Telegram alerts.

---

## Installation

The package is private. Add the repository and tell Composer to clone it over SSH (without the
GitHub API, which would need a token for a private repository):

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:TechnoBase-krd/laravel-system-status.git", "no-api": true }
    ],
    "config": {
        "preferred-install": { "technobase/*": "source", "*": "dist" }
    }
}
```

```bash
composer require technobase/laravel-system-status
php artisan vendor:publish --tag=system-status-config
php artisan migrate
```

The service provider is discovered automatically. Out of the box it:

- registers the page at `/system-status` (route names `system_status.index`,
  `system_status.summary`, `system_status.component`) behind `web` + `auth` and the access check;
- adds the traffic middleware to every request;
- schedules `status:check` every minute and `status:prune` every day at 03:30;
- registers the commands `status:check`, `status:prune` and `status:routes`.

Open `/system-status` as a Super Admin. Until the first `status:check` run the banner says
*Waiting for the first status check*.

---

## Integrating it into an application

A complete integration takes five steps.

### 1. Describe your components

In `config/system-status.php`, list groups and components. A component needs at least one
source: probes, routes (`names` / `uris`) or a signal.

```php
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Technobase\SystemStatus\Probes\CdnStorageProbe;
use Technobase\SystemStatus\Probes\HttpEndpointProbe;
use Technobase\SystemStatus\Probes\QueueHeartbeatProbe;
use Technobase\SystemStatus\Probes\SpatieCheckProbe;

'groups' => [
    'platform' => [
        'database' => ['probes' => [['class' => SpatieCheckProbe::class, 'with' => ['check' => DatabaseCheck::class], 'every' => 1]]],
        'redis'    => ['probes' => [['class' => SpatieCheckProbe::class, 'with' => ['check' => RedisCheck::class], 'every' => 1]]],
        'queue'    => ['probes' => [
            ['class' => SpatieCheckProbe::class, 'with' => ['check' => HorizonCheck::class], 'every' => 1],
            ['class' => QueueHeartbeatProbe::class, 'every' => 1],
        ]],
        'scheduler' => [],   // decided by status:check itself
        'storage'  => ['probes' => [['class' => CdnStorageProbe::class, 'with' => ['disk' => 's3'], 'every' => 5]]],
    ],

    'mobile_api' => [
        'api_catalogue' => [
            'probes' => [['class' => HttpEndpointProbe::class, 'with' => ['path' => '/api/v1/categories'], 'every' => 2]],
            'uris' => ['api/v1/categories*', 'api/v1/products*'],
        ],
        'api_auth' => ['uris' => ['api/v1/auth*']],
        'api_social_login' => ['uris' => ['api/v1/auth/social*'], 'signal' => true],
        'api_other' => [],
    ],

    'backoffice' => [
        'bo_orders' => ['uris' => ['order', 'order/*', 'orders/*']],
        'bo_other' => [],
    ],

    'integrations' => [
        'payments' => [
            'probes' => [['class' => App\Probes\PaymentGatewayProbe::class, 'every' => 10]],
            'uris' => ['api/v1/webhooks/payments*'],
        ],
        'sms_otp' => ['signal' => true],
    ],
],
```

How routes are matched:

- `names` (route-name patterns, e.g. `website.*`) are tried first, then `uris` (e.g.
  `api/v1/orders*`). `*` matches anything.
- **The longest matching pattern wins**, so `api/v1/auth/social*` beats `api/v1/auth*`.
- Unmatched routes go to `fallback`: `api/*` → `api_other`, everything else → `bo_other`.
- `ignore_uris` are not counted at all (Horizon, debug tools, health checks, this page).

Check the result:

```bash
php artisan status:routes --fallback          # routes that matched no component
php artisan status:routes --component=api_auth
```

### 2. Add probes for your integrations

For every external service that has a **free, read-only** call (a "verify key" endpoint, a
list of cities, an account lookup), write a probe. See [Probes](#probes).

### 3. Add signals where a call costs money or has side effects

SMS and OTP gateways, social login, paid AI APIs: wrap the existing call. See [Signals](#signals).

### 4. Name your components

Add labels for components the package does not know in every locale. See
[Translations](#translations).

```php
// lang/vendor/system-status/en/status.php
return [
    'status_component_payments' => 'Payment gateway',
    'status_component_sms_otp' => 'SMS / WhatsApp OTP',
];
```

### 5. Link it from the sidebar

```blade
@if(auth()->user()?->hasRole('Super Admin'))
    <li class="nav-item">
        <a href="{{ route('system_status.index') }}" class="nav-link {{ request()->is('system-status*') ? 'active' : '' }}">
            <i class="fa-solid fa-heart-pulse"></i>
            <span>@lang('system-status::status.system_status')</span>
        </a>
    </li>
@endif
```

Done. Run `php artisan status:check` once, open the page, and make sure the scheduler runs.

---

## Configuration reference

All options live in `config/system-status.php`.

| Key | Default | What it does |
| --- | --- | --- |
| `path` | `system-status` | URL of the page; the data endpoints are `<path>/summary` and `<path>/components/{key}` |
| `middleware` | `['web', 'auth']` | middleware of the page routes (the access check is always added) |
| `access.roles` | `['Super Admin']` | roles allowed in, checked with `$user->hasRole()` |
| `access.gate` | `null` | a Gate ability name; when set, it replaces the role check |
| `layout` | `layouts.app` | Blade layout the page extends |
| `stacks.styles` / `.modals` / `.scripts` | `styles` / `modals` / `scripts` | stack names in that layout |
| `extra_css` | `null` | absolute path of a CSS file added after the package styles |
| `refresh_seconds` | `['1h' => 20, 'default' => 60]` | seconds between automatic refreshes, per range |
| `record_traffic` | `true` | count every request (global middleware) |
| `schedule` | `true` | schedule `status:check` and `status:prune` |
| `redis_connection` | `null` | Redis connection of the traffic counters (`null` = default) |
| `thresholds.window_minutes` | `15` | window for traffic and signal error rates |
| `thresholds.min_requests` | `20` | fewer requests in the window = operational |
| `thresholds.min_signals` | `3` | fewer signal calls in the window = operational |
| `thresholds.degraded_error_rate` | `5` | % of failures for *degraded* |
| `thresholds.outage_error_rate` | `25` | % of failures for *major outage* |
| `thresholds.slow_request_ms` | `2000` | a request slower than this counts as slow |
| `thresholds.slow_probe_ms` | `3000` | an `HttpEndpointProbe` slower than this is *degraded* |
| `thresholds.confirm_runs` | `2` | runs in a row that must agree before a status changes |
| `thresholds.heartbeat_stale_minutes` | `3` | scheduler and queue heartbeats older than this are an outage |
| `thresholds.probe_timeout_seconds` | `10` | HTTP timeout of the probes |
| `probe_base_url` | `env('STATUS_PROBE_BASE_URL')` | send HTTP probes straight to the app (e.g. `http://nginx`); `APP_URL`'s host is kept in `Host` |
| `probe_verify_ssl` | `env('STATUS_PROBE_VERIFY_SSL', true)` | `false` skips TLS checks, only for an internal probe base URL |
| `probe_user_agent` | `SystemStatusProbe/1.0` | User-Agent of the HTTP probes (allow it in your firewall) |
| `alerts.enabled` | `env('SYSTEM_STATUS_ALERTS_ENABLED', true)` | send Telegram alerts; `SYSTEM_STATUS_ALERTS_ENABLED=false` in `.env` turns them off |
| `alerts.telegram_chat_id` | `env('SYSTEM_STATUS_TELEGRAM_CHAT_ID')` | chat that receives the alerts |
| `alerts.prefix` | `[APP_NAME status]` | first words of every alert |
| `retention_days` | checks 7, route_buckets 7, daily 90, incidents 90 | what `status:prune` keeps |
| `queues` | `[['connection' => null, 'queue' => 'default']]` | queues that get a heartbeat job every run |
| `ignore_uris` | tools, health checks, this page | URIs that are never counted |
| `fallback` | `api/*` → `api_other`, `*` → `bo_other` | component for unmatched routes |
| `groups` | platform, mobile_api, backoffice | your components (see above) |

A component definition:

| Key | Example | Meaning |
| --- | --- | --- |
| `probes` | `[['class' => X::class, 'with' => [...], 'every' => 5]]` | probe classes; `with` = constructor arguments, `every` = minutes |
| `names` | `['website.*']` | route-name patterns |
| `uris` | `['api/v1/orders*']` | URI patterns |
| `signal` | `true` | accepts `StatusSignal` calls |

---

## Probes

### Built-in probes

| Probe | Options (`with`) | Checks |
| --- | --- | --- |
| `SpatieCheckProbe` | `check` (a spatie/laravel-health check class) | database, Redis, cache, Horizon, disk space, or any custom Spatie check |
| `HttpEndpointProbe` | `path`, `json` (default `true`) | a GET request to a path of the application; on failure it says whether the app or a proxy (e.g. Cloudflare) answered |
| `QueueHeartbeatProbe` | – | the heartbeat jobs on every queue in `queues` were processed |
| `CdnStorageProbe` | `disk` (default `s3`) | a HEAD request to the disk's public URL |
| `TelegramProbe` | – | the bot token works (`getMe`) |

### Writing a probe

```php
namespace App\Probes;

use App\Services\PaymentGateway;
use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\ProbeResult;
use Throwable;

class PaymentGatewayProbe implements Probe
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function name(): string
    {
        return 'Payment gateway verify-key';
    }

    public function run(): ProbeResult
    {
        if (blank(config('services.payments.key'))) {
            return ProbeResult::skipped('Payment gateway is not configured');
        }

        try {
            $this->gateway->verifyKey();          // free and read-only
        } catch (Throwable $e) {
            return ProbeResult::outage('Gateway refused the key ('.class_basename($e).')');
        }

        return ProbeResult::ok();
    }
}
```

Results: `ProbeResult::ok()`, `degraded($message)`, `outage($message)`, `skipped($message)`.
A probe decides its own status; return `degraded()` yourself when the service is slow.

Rules for probes:

- **Read-only and free.** Never create orders, payments, shipments, messages or OTPs, never
  call a paid API, never POST to the application. Use a signal for those.
- **No secrets in messages.** No URLs with tokens, no raw exception messages from HTTP clients
  (they often contain the URL); use the exception class or a short text.
- Return `skipped()` while the service is not configured, so it shows *Not configured*
  instead of an outage.

---

## Signals

For calls you cannot test for free, report each real call:

```php
use Technobase\SystemStatus\Support\StatusSignal;

try {
    $this->smsGateway->send($phone, $code);
    StatusSignal::success('sms_otp');
} catch (Throwable $e) {
    StatusSignal::failure('sms_otp', class_basename($e));

    throw $e;   // behaviour unchanged
}
```

- The component needs `'signal' => true`.
- Signals never throw and never change the caller's behaviour.
- The status follows the error rate of the last 15 minutes, with at least 3 calls.
- The last failure reason is shown in the component's detail popup.

---

## Reacting to status changes

Every confirmed change fires `Technobase\SystemStatus\Events\ComponentStatusChanged`:

| Property | Type |
| --- | --- |
| `component` | `string` key, e.g. `payments` |
| `label` | `string` display name |
| `previous`, `current` | `Technobase\SystemStatus\Enums\ComponentStatus` (`Operational`, `Degraded`, `MajorOutage`) |
| `message` | `?string` reason |
| `changedAt` | `CarbonInterface` |
| `isRecovery()` | `true` when the component is operational again |

Send it to mail, Slack or a webhook:

```php
use Illuminate\Support\Facades\Event;
use Technobase\SystemStatus\Events\ComponentStatusChanged;

Event::listen(function (ComponentStatusChanged $event) {
    Notification::route('mail', 'ops@example.com')->notify(new SystemStatusChanged($event));
});
```

A failing listener is logged and never stops the status run. To use only your own channel,
set `SYSTEM_STATUS_ALERTS_ENABLED=false` in `.env` (or `alerts.enabled` to `false`).

**Telegram alerts** need `laravel-notification-channels/telegram`, the bot token in
`services.telegram-bot-api.token` and `alerts.telegram_chat_id`. They are never sent in the
`local` environment (they are logged instead). Example messages:

```
[Shop status] 🟠 Delivery courier: degraded. Courier API answered HTTP 502 on 4 of 12 calls
[Shop status] ✅ Delivery courier recovered after 38 min.
```

---

## Access

The page shows infrastructure details, so it is closed by default.

- **Roles** (default): `access.roles` = `['Super Admin']`, checked with `hasRole()` from
  `spatie/laravel-permission`. A user model without `hasRole()` is refused.
- **Gate**: set `access.gate` to an ability name and define it:

  ```php
  Gate::define('viewSystemStatus', fn (User $user) => $user->is_admin);
  ```

The page always needs a signed-in user (`middleware`). The data endpoints follow the same rules.

---

## Look and feel

The page follows the host theme: its colours use `--success`, `--warning`, `--danger`,
`--primary`, `--border` and `--muted` when the theme defines them.

**Brand colours** with a small CSS file:

```php
'extra_css' => resource_path('css/system-status.css'),
```

```css
.sst {
    --sst-accent: #0f766e;     /* KPI accents, selected range */
    --sst-ok: #16a34a;
    --sst-degraded: #f59e0b;
    --sst-partial: #f97316;
    --sst-major: #dc2626;
    --sst-radius: .5rem;
}
```

**Layout**: set `layout` and `stacks` to match your backoffice layout.

**Full control**: publish the view and edit it.

```bash
php artisan vendor:publish --tag=system-status-views   # resources/views/vendor/system-status/index.blade.php
```

A published view no longer receives the package's changes to the page; prefer `extra_css`
when it is enough.

---

## Translations

The package ships English (`en`), Arabic (`ar`) and Sorani Kurdish (`ku`) for every text on the
page, plus labels for common components (`database`, `redis`, `queue`, `scheduler`, `storage`,
`search`, `api_*`, `bo_*`, `firebase`, `telegram`, …).

Add or override any key per locale in `lang/vendor/system-status/<locale>/status.php`:

```php
return [
    'status_component_payments' => 'بوابة الدفع',
    'status_group_integrations' => 'التكاملات',
    'system_status_description' => 'Live health of every part of the shop.',
];
```

- Component label: `status_component_<key>`; group label: `status_group_<group>`.
- A component without a label is shown as its key in words (`api_orders` → *Api Orders*).
- `system_status_description` can use `:app` (the application name).
- To copy all package texts for editing: `php artisan vendor:publish --tag=system-status-lang`.
- The page direction follows the layout (`dir="rtl"` for Arabic and Kurdish).

---

## Commands and schedule

| Command | When | What |
| --- | --- | --- |
| `status:check` | every minute (`withoutOverlapping(5)`, in background) | flushes traffic counters, runs due probes, evaluates every component, confirms changes, writes incidents, history and alerts, sends queue heartbeats |
| `status:prune` | daily at 03:30 | deletes history older than `retention_days` |
| `status:routes` | by hand | shows which component every route counts under (`--fallback`, `--component=`) |

Set `schedule` to `false` to schedule them yourself.

---

## Database tables

| Table | Holds | Kept |
| --- | --- | --- |
| `status_components` | current status, candidate status, message, `configured`, last check | always |
| `status_checks` | one row per probe run | 7 days |
| `status_route_buckets` | requests, errors, slow requests and total time per component, route and 5 minutes; signals use route `(signal)` | 7 days |
| `status_daily` | runs and degraded / outage runs per component and day | 90 days |
| `status_incidents` | incidents opened and resolved by `status:check` | 90 days after resolution |

The migration creates a table only when it does not exist yet, so it is safe on a database
that already has them.

---

## Claude Code skill

The package ships a [Claude Code](https://claude.com/claude-code) skill that teaches the
assistant how the status page works in your application: where components live, how to add a
probe or a signal, and the rules (read-only probes, no secrets in messages, never edit
`vendor/`).

```bash
php artisan vendor:publish --tag=system-status-skill   # .claude/skills/system-status/SKILL.md
```

Fill in its last section, *This application*, with your components and probes, and commit it.

---

## Deployment

- **Composer access.** Every machine or container that runs `composer install` needs read
  access to `TechnoBase-krd/laravel-system-status`: an SSH key with read access (a GitHub deploy
  key), or a GitHub token in `COMPOSER_AUTH` (`{"github-oauth": {"github.com": "<token>"}}`) with
  the URL `https://github.com/TechnoBase-krd/laravel-system-status.git`.
- **Servers without git or SSH (Docker images).** Keep the SSH repository for developers and,
  on the server, give Composer a read-only GitHub token and install from the zip that
  `composer.lock` records:

  ```bash
  # once, in the project folder on the server (auth.json is git-ignored by Laravel)
  echo '{"github-oauth": {"github.com": "<token>"}}' > auth.json && chmod 600 auth.json
  # every deploy
  composer install --prefer-dist --no-interaction
  ```

  The token needs read access to this repository only (fine-grained token, Contents: Read-only).
- **Scheduler.** `schedule:run` must run every minute (cron or a scheduler container), otherwise
  the page shows the scheduler red and nothing updates.
- **Queues.** List in `queues` only queues that always have a worker.
- **Firewall.** HTTP probes go through `APP_URL`. If Cloudflare or a WAF answers them with 403,
  allow your `probe_user_agent`, or set `STATUS_PROBE_BASE_URL` to reach the app directly.
- **Migration.** `php artisan migrate` (runs unattended).

---

## Troubleshooting

| What you see | Why | Fix |
| --- | --- | --- |
| "Waiting for the first status check" | `status:check` never ran | run it once; start the scheduler |
| Scheduler red | no run for more than `heartbeat_stale_minutes` | check the scheduler container / cron |
| Queue red, "heartbeat not processed" | no worker on a queue in `queues` | start the worker or remove the queue |
| An HTTP probe fails with "Cloudflare, not the app" | the firewall blocks the probe | allow the user agent or set `STATUS_PROBE_BASE_URL` |
| A component shows "No data" | no probe ran and no traffic yet | wait a run, or add a source to the component |
| A component shows "Not configured" | its probes return `skipped()` | configure the integration, or accept it |
| A component name looks like "Api Orders" | no label for it | add `status_component_<key>` in `lang/vendor/system-status` |
| 403 on the page | the user has none of `access.roles` (or fails `access.gate`) | change the access settings |
| No Telegram alerts | `local` environment, no chat id, no bot token, `SYSTEM_STATUS_ALERTS_ENABLED=false`, or the Telegram package missing | check those |
| Routes counted under `api_other` / `bo_other` | no rule matches them | `php artisan status:routes --fallback`, then add `uris` |

---

## Upgrading and releases

Versions follow semantic versioning: `1.x` releases never rename or remove a config key, route
name, translation key, class or event property. See [CHANGELOG.md](CHANGELOG.md).

```bash
composer update technobase/laravel-system-status
```

To work on the package itself, see `.claude/skills/laravel-system-status/SKILL.md`.
