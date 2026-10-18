# technobase/laravel-system-status

A status page for TechnoBase Laravel backoffices, in the style of githubstatus.com. It shows
whether each part of the system works, decided from three sources:

- **Probes**: read-only, free checks run by `status:check` every few minutes (database, Redis,
  queues, HTTP endpoints, CDN, Telegram, your own integrations).
- **Real traffic**: every request is counted per component and route; 5xx responses are failures.
- **Signals**: `StatusSignal::success()` / `failure()` from code that cannot be probed for free
  (OTP gateways, social login, paid AI APIs).

A status changes only after two runs in a row agree. Each change opens or resolves an incident
and sends one Telegram alert. History: 1 hour, 24 hours, 7 days and 90 days.

Used by Khatun-Ecommerce and CloudNova-OnlineShopping.

## Requirements

- PHP 8.3, Laravel 12 or 13, Redis (traffic counters), a database for the `status_*` tables.
- `spatie/laravel-health` (database, Redis, cache, Horizon checks).
- A Blade layout with `@yield('title')`, `@yield('content')` and stacks for styles, modals and
  scripts, plus jQuery and the Bootstrap modal plugin (the Limitless backoffice theme has them).
- Optional: `spatie/laravel-permission` for the role check, `laravel-notification-channels/telegram`
  for alerts.

## Install

The package is private. Add the repository to the application's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:TechnoBase-krd/laravel-system-status.git" }
]
```

```bash
composer require technobase/laravel-system-status
php artisan vendor:publish --tag=system-status-config
php artisan migrate
```

The service provider is auto-discovered. It registers the routes (`system_status.index`,
`.summary`, `.component` under `/system-status`), the global traffic middleware, the
`status:check` (every minute) and `status:prune` (daily 03:30) schedule, and the commands
`status:check`, `status:prune` and `status:routes`. The scheduler must be running.

**Deploying:** a server or container that runs `composer install` needs read access to this
repository: an SSH deploy key, or a GitHub token in `COMPOSER_AUTH`
(`{"github-oauth": {"github.com": "<token>"}}`) with the `https://github.com/...` URL.

## Configure

Everything is in `config/system-status.php`. Describe your components under `groups`:

```php
'groups' => [
    'integrations' => [
        'payments' => [
            'probes' => [['class' => App\Probes\PaymentGatewayProbe::class, 'every' => 10]],
            'uris' => ['api/v1/webhooks/payments*'],
        ],
        'otp' => ['signal' => true],
    ],
],
```

- `probes`: classes implementing `Technobase\SystemStatus\Probes\Probe`; `with` passes
  constructor arguments, `every` is the interval in minutes.
- `names` / `uris`: route-name and URI patterns. The longest matching pattern wins; unmatched
  routes go to `fallback` (`api_other` / `bo_other`). Check with `php artisan status:routes --fallback`.
- `signal`: the component takes `StatusSignal` calls.

Other settings: `path`, `middleware`, `access.roles` (default `Super Admin`), `layout`,
`stacks`, `record_traffic`, `schedule`, `thresholds`, `probe_base_url` (`STATUS_PROBE_BASE_URL`),
`probe_verify_ssl`, `probe_user_agent`, `alerts.telegram_chat_id`, `alerts.prefix`,
`retention_days`, `queues` (heartbeat jobs), `ignore_uris`, `fallback`.

### Built-in probes

| Probe | Checks |
| --- | --- |
| `SpatieCheckProbe` | any spatie/laravel-health check: `'with' => ['check' => DatabaseCheck::class]` |
| `HttpEndpointProbe` | GET on a path of the app: `'with' => ['path' => '/api/v1/categories']` |
| `QueueHeartbeatProbe` | the heartbeat jobs on `queues` were processed |
| `CdnStorageProbe` | HEAD on a disk's public URL: `'with' => ['disk' => 's3']` |
| `TelegramProbe` | the bot token with `getMe` |

### Writing a probe

```php
use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\ProbeResult;

class PaymentGatewayProbe implements Probe
{
    public function name(): string { return 'Gateway verify-key'; }

    public function run(): ProbeResult
    {
        if (blank(config('payments.key'))) {
            return ProbeResult::skipped('Not configured');
        }

        // A free, read-only call. Never create data, send messages or call paid APIs.
        return $ok ? ProbeResult::ok() : ProbeResult::outage('Gateway refused the key');
    }
}
```

Never put tokens, URLs with secrets or raw HTTP exception messages in a result message.

### Signals

```php
use Technobase\SystemStatus\Support\StatusSignal;

try {
    $gateway->send($phone, $code);
    StatusSignal::success('otp');
} catch (Throwable $e) {
    StatusSignal::failure('otp', class_basename($e));
    throw $e;
}
```

Signals never throw. A component needs `'signal' => true`.

## Translations

The page ships English, Arabic and Sorani Kurdish (`en`, `ar`, `ku`). Labels for your own
components and groups go in `lang/vendor/system-status/<locale>/status.php`:

```php
return [
    'status_component_payments' => 'Payments',
];
```

A component without a label is shown as its key in words.

## Upgrading

Tag releases with semantic versions (`v1.0.0`). Never edit a migration that has been released;
add a new one.
