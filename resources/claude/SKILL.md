---
name: system-status
description: The System Status page provided by the technobase/laravel-system-status package (Super Admin only at /system-status by default) — the component registry in config/system-status.php, how a status is decided (read-only probes, real per-route traffic, StatusSignal for paid or side-effect calls), status:check with two-in-a-row confirmation, incidents, Telegram alerts and the ComponentStatusChanged event, the status_* tables, the 1h / 24h / 7d / 90d history, translations and customisation. Use when adding a module, route group or integration, adding or changing a component, probe, route rule, signal, threshold or alert, when a component shows an unexpected status, or when touching the status page.
---

# System Status (technobase/laravel-system-status)

Published into this application by `php artisan vendor:publish --tag=system-status-skill`.
Fill in the "This application" section below with the project's own components and probes.

## Purpose

Keep the status page truthful and cheap: every route and integration maps to a component,
checks never create data or cost money, and a status only changes when it is real.

## Where things are

| What | Where |
| --- | --- |
| Engine, page, tables, commands, built-in probes, translations | `vendor/technobase/laravel-system-status` (never edit) |
| Components, thresholds, alerts, access, layout | `config/system-status.php` |
| The application's own probes | a folder of the application (e.g. `app/Probes` or a module's `Probes`) |
| Labels for the application's components | `lang/vendor/system-status/<locale>/status.php` |
| Reactions to status changes | listeners of `Technobase\SystemStatus\Events\ComponentStatusChanged` |

## How a status is decided

The worst of three sources wins:

1. **Probes** (`Technobase\SystemStatus\Probes\Probe`, returning `ProbeResult::ok()`,
   `degraded()`, `outage()` or `skipped()`): read-only checks run by `status:check` every
   `every` minutes. `skipped()` means "not configured" and does not count.
2. **Real traffic**: every request is counted per component and route; 5xx responses are
   failures, 4xx are not. A route maps to a component by `names` (route-name patterns) first,
   then `uris`; the longest matching pattern wins; unmatched routes go to `fallback`.
3. **Signals**: `Technobase\SystemStatus\Support\StatusSignal::success('key')` /
   `failure('key', 'short reason')` around calls that cannot be probed for free (OTP and SMS
   gateways, social login, paid AI APIs). The component needs `'signal' => true`.

Over the last `thresholds.window_minutes` (15): error rate ≥ 5 % = degraded, ≥ 25 % = major
outage, only with ≥ 20 requests (≥ 3 signal calls). A status changes only after
`confirm_runs` (2) runs in a row agree; the change opens or resolves an incident, sends one
Telegram alert (unless `alerts.enabled` is false, in `local`, or without a chat id) and fires
`ComponentStatusChanged`.

`status:check` runs every minute and `status:prune` daily at 03:30, both scheduled by the
package (`schedule`). The scheduler must be running.

## Recipes

**New component**

```php
// config/system-status.php → groups.<group>
'payments' => [
    'probes' => [['class' => App\Probes\PaymentGatewayProbe::class, 'every' => 10]],
    'uris' => ['api/v1/payments*', 'api/v1/webhooks/payments*'],
    'signal' => true,
],
```

Then a label in every locale in `lang/vendor/system-status/<locale>/status.php`
(`'status_component_payments' => 'Payments'`), and check coverage with
`php artisan status:routes --fallback`.

**New probe**: implement `Probe` (`name()`, `run()`); return `skipped()` while the service is
not configured; catch exceptions and return `outage('… ('.class_basename($e).')')`.

**New signal**: wrap the existing call; report success after it, failure in the catch, and
rethrow so behaviour does not change.

## Rules

- Probes are read-only and free: no POST to the application, no creating orders, payments,
  messages, OTPs or shipments, no paid API calls. Use a signal instead.
- Never put secrets in probe or signal messages: no URLs with tokens, no raw HTTP exception
  messages; use the exception class or a short text.
- Signals and traffic counting never throw; keep it that way around new calls.
- Never edit `vendor/`. A change to the engine or the page goes to the package repository,
  is released with a tag, then installed with `composer update technobase/laravel-system-status`.
- Showing the page to more people: change `access.roles` or set `access.gate`; never remove
  the check.

## Commands

```bash
php artisan status:check              # one run: probes, traffic, confirmation, incidents, alerts
php artisan status:routes --fallback  # routes that match no component
php artisan status:routes --component=api_orders
php artisan status:prune              # delete history past retention_days
```

## This application

<!-- List the groups and components, the application's own probes and signals, and anything
     special (proxies, firewalls, queues that only run in some environments). -->
