# Installation and setup

[← Back to README](../README.md) · [All docs](README.md)

---

## Requirements

| | |
| --- | --- |
| PHP | 8.3 or newer |
| Laravel | 12 or 13 |
| Database | PostgreSQL, MySQL 8 / MariaDB, or SQLite |
| Redis | for the per-minute traffic counters (any connection; see `redis_connection`). Apps that share one Redis need different key prefixes (`REDIS_PREFIX` / `APP_NAME`), or they will read each other's traffic |
| Scheduler | `php artisan schedule:work` or a cron entry running `schedule:run` every minute |
| `spatie/laravel-health` | installed automatically; used for the database, Redis, cache and Horizon checks |
| Layout | none required: without a backoffice layout the page renders as its own full HTML page (see [Layout](customization.md#layout)) |
| Front end | Font Awesome 5 or 6 (the standalone page loads it). No jQuery or Bootstrap: the page script is plain JavaScript and the dialogs and forms use Tailwind from a CDN (`tailwind_cdn`) with prefixed `tw:` classes and no global reset, so your layout's own styles are not touched |

Optional:

- `spatie/laravel-permission`, for the default role check (or use a Gate, see [Access](customization.md#access));
- `laravel-notification-channels/telegram`, for Telegram alerts;
- `barryvdh/laravel-dompdf`, for the PDF version of the monthly report;
- `laravel/pulse`, for the [Pulse card](customization.md#pulse-card).

## Installation

Install the package from [Packagist](https://packagist.org/packages/technobase/laravel-system-status)
with Composer, publish the config and run the migrations:

```bash
composer require technobase/laravel-system-status
php artisan vendor:publish --tag=system-status-config
php artisan migrate
```

The service provider is discovered automatically. Out of the box it:

- registers the page at `/system-status` (route names `system_status.index`,
  `system_status.summary`, `system_status.component`, `system_status.report`, and the
  `system_status.incidents.*` / `system_status.maintenances.*` write routes) behind `web` +
  `auth` and the access check;
- adds the traffic middleware to every request;
- schedules `status:check` every minute and `status:prune` every day at 03:30 (and
  `status:report` monthly when `reports.mail_to` is set);
- registers the commands `status:check`, `status:prune`, `status:routes`, `status:maintenance`,
  `status:test-alert`, `status:report` and `status:doctor`.

Run `php artisan status:doctor` to check the setup, then open `/system-status` as a Super Admin.
Until the first `status:check` run the banner says *Waiting for the first status check*.

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
            'depends_on' => ['database'],
        ],
        'api_auth' => ['uris' => ['api/v1/auth*'], 'sla' => 99.9],
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
php artisan status:doctor                     # the whole setup
```

### 2. Add probes for your integrations

For every external service that has a **free, read-only** call (a "verify key" endpoint, a
list of cities, an account lookup), write a probe. See [Probes](probes.md#probes).

### 3. Add signals where a call costs money or has side effects

SMS and OTP gateways, social login, paid AI APIs: wrap the existing call. See [Signals](probes.md#signals).

### 4. Name your components

Add labels for components the package does not know in every locale. See
[Translations](customization.md#translations).

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

## Deployment

- **Composer.** The package is public on Packagist, so a normal
  `composer install --no-dev --prefer-dist --no-interaction` works on any server or Docker
  image; no SSH key, deploy key or GitHub token is needed.
- **Moving from the old private setup.** If your application still has the `vcs` repository for
  `TechnoBase-krd/laravel-system-status` (and `preferred-install` for `technobase/*`) in its
  `composer.json`, remove them, delete any `auth.json` / `COMPOSER_AUTH` added only for this
  package, and run `composer update technobase/laravel-system-status`.
- **Scheduler.** `schedule:run` must run every minute (cron or a scheduler container), otherwise
  the page shows the scheduler red and nothing updates.
- **Several servers.** The scheduled commands run on one server only (`schedule_on_one_server`)
  through the cache's locks, so the cache must be shared (Redis, Memcached, database). If the
  cache is the Redis you are monitoring, `Schedule::useCache('database')` in the application keeps
  the scheduler's locks, and so the checks, working while Redis is down. Point `heartbeat_store`
  at a shared store too.
- **Queues.** List in `queues` only queues that always have a worker.
- **Firewall.** HTTP probes go through `APP_URL`. If Cloudflare or a WAF answers them with 403,
  allow your `probe_user_agent`, or set `STATUS_PROBE_BASE_URL` to reach the app directly.
- **Migration.** `php artisan migrate` (runs unattended).
- **Deploys.** Start a maintenance window before the deploy
  (`php artisan status:maintenance --all --minutes=10`), so restarts do not open incidents or
  send alerts (see [Maintenance windows](incidents.md#maintenance-windows)).
- **Deploy marker.** End the deploy script with `php artisan status:deploy` (see
  [Deploy markers](incidents.md#deploy-markers)).
- **Check.** `php artisan status:doctor` after the first deploy.
