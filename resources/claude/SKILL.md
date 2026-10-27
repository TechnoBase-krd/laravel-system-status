---
name: system-status
description: The System Status page provided by the technobase/laravel-system-status package (Super Admin only at /system-status by default) — the component registry in config/system-status.php, how a status is decided (read-only probes, real per-route traffic and p95 latency, StatusSignal for paid or side-effect calls, manual incidents), status:check with two-in-a-row confirmation, maintenance windows, deploy markers (status:deploy), suspicious activity (security.*, StatusSecurity::action, status:security), incidents with timelines and postmortems, alert channels (Telegram, mail, Slack, Discord, webhooks), depends_on, SLA targets and monthly reports, the ComponentStatusChanged / IncidentUpdated / Maintenance* events, the status_* tables, status:doctor, the 1h / 24h / 7d / 90d history, translations and customisation. Use when adding a module, route group or integration, adding or changing a component, probe, route rule, signal, threshold, dependency, SLA or alert, when writing a deploy script or CI step, when a component shows an unexpected status, or when touching the status page.
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
| Components, thresholds, alerts, SLA, reports, access, layout | `config/system-status.php` |
| The application's own probes | a folder of the application (e.g. `app/Probes` or a module's `Probes`) |
| Labels for the application's components | `lang/vendor/system-status/<locale>/status.php` |
| Reactions to status changes | listeners of `ComponentStatusChanged`, `IncidentUpdated`, `MaintenanceStarted`, `MaintenanceEnded`, `DeployRecorded`, `SuspiciousActivityDetected` (all in `Technobase\SystemStatus\Events`) |
| Risky actions to watch | `StatusSecurity::action('roles.changed', $user)` in the application's code, rules in `security.actions` |
| Alert credentials | `.env`: `SYSTEM_STATUS_TELEGRAM_CHAT_ID`, `SYSTEM_STATUS_ALERT_MAIL_TO`, `SYSTEM_STATUS_SLACK_WEBHOOK`, `SYSTEM_STATUS_DISCORD_WEBHOOK`, `SYSTEM_STATUS_REMINDER_MINUTES`, `SYSTEM_STATUS_REPORT_MAIL_TO`, `SYSTEM_STATUS_DEPLOY_TOKEN`, `SYSTEM_STATUS_SECURITY_ENABLED` |

## How a status is decided

The worst of these sources wins:

1. **Probes** (`Technobase\SystemStatus\Probes\Probe`, returning `ProbeResult::ok()`,
   `degraded()`, `outage()` or `skipped()`): read-only checks run by `status:check` every
   `every` minutes. `skipped()` means "not configured" and does not count.
2. **Real traffic**: every request is counted per component and route; 5xx responses are
   failures, 4xx are not. A route maps to a component by `names` (route-name patterns) first,
   then `uris`; the longest matching pattern wins; unmatched routes go to `fallback`. The p95
   response time counts too when `thresholds.latency_degraded_p95_ms` /
   `latency_outage_p95_ms` are set (off by default).
3. **Signals**: `Technobase\SystemStatus\Support\StatusSignal::success('key')` /
   `failure('key', 'short reason')` around calls that cannot be probed for free (OTP and SMS
   gateways, social login, paid AI APIs). The component needs `'signal' => true`.
4. **Manual incidents** posted on the page hold the component at their severity until someone
   resolves them.

Over the last `thresholds.window_minutes` (15): error rate ≥ 5 % = degraded, ≥ 25 % = major
outage, only with ≥ 20 requests (≥ 3 signal calls). A component can override these with its
own `'thresholds' => [...]`. A status changes only after `confirm_runs` (2) runs in a row
agree; the change opens or resolves an incident, sends one alert to `alerts.channels` and
`alerts.webhooks` (unless `alerts.enabled` is false or in `local`), and fires
`ComponentStatusChanged`.

`SYSTEM_STATUS_ENABLED=false` (`enabled`) turns the whole package off: nothing is counted,
checked or alerted, and the page answers 404 while its route names stay registered.

- **Maintenance window**: the component shows `Maintenance` at once (`ComponentStatus::Maintenance`):
  no incident, no alert, no `ComponentStatusChanged`, and the time does not count against
  uptime. When the window ends, the observed status is taken without confirmation.
- **`depends_on`**: a failing component whose dependency fails too shows "Affected by …" and
  its alert is folded into the dependency's.
- **Flap protection** (`alerts.flap`, on by default: 4 changes in 30 min): one "flapping"
  alert, then quiet until stable.

`status:check` runs every minute and `status:prune` daily at 03:30, both scheduled by the
package (`schedule`); with `reports.mail_to` set, last month's uptime report is emailed on the
1st. The scheduler must be running.

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
A probe that makes one HTTP request should implement `ConcurrentProbe` (`precheck()`,
`send(PendingRequest)`, `result(Response|Throwable)`) with the `SendsHttpRequest` trait, so
`status:check` sends it together with the other HTTP probes.

**New signal**: wrap the existing call; report success after it, failure in the catch, and
rethrow so behaviour does not change.

**Built-in probes before writing one.** `with` holds constructor arguments by name:

```php
use Technobase\SystemStatus\Probes\{SslCertificateProbe, DiskSpaceProbe, FailedJobsProbe, QueueSizeProbe, QueueWaitProbe, DnsProbe, TcpPortProbe, CallbackProbe};

['class' => SslCertificateProbe::class, 'with' => ['host' => 'shop.example.com', 'warnDays' => 14], 'every' => 60],
['class' => DiskSpaceProbe::class, 'with' => ['degradedBelowPercent' => 15, 'outageBelowPercent' => 5], 'every' => 5],
['class' => FailedJobsProbe::class, 'with' => ['windowMinutes' => 60, 'degradedAt' => 5, 'outageAt' => 50], 'every' => 5],
['class' => QueueSizeProbe::class, 'with' => ['queue' => 'default', 'degradedAt' => 100, 'outageAt' => 1000], 'every' => 1],
['class' => QueueWaitProbe::class, 'with' => ['queue' => 'default', 'degradedSeconds' => 60, 'outageSeconds' => 300], 'every' => 1],
['class' => DnsProbe::class, 'with' => ['host' => 'shop.example.com', 'type' => 'A'], 'every' => 10],
['class' => TcpPortProbe::class, 'with' => ['host' => 'smtp.example.com', 'port' => 587], 'every' => 5],
['class' => CallbackProbe::class, 'with' => ['callback' => App\Probes\CheckSomething::class, 'label' => 'Something'], 'every' => 5],
```

`CallbackProbe` takes an invokable class or `[Class::class, 'method']` returning a
`ProbeResult`, `true`, `false`, `null` (not configured) or a string (degraded). Never a closure
in the config: it breaks `config:cache`.

**Dependencies, SLA and per-component thresholds**

```php
'api_orders' => [
    'uris' => ['api/v1/orders*'],
    'depends_on' => ['database', 'redis'],   // keys of other components
    'sla' => 99.9,                           // monthly uptime target in %
    'thresholds' => ['degraded_error_rate' => 2, 'latency_degraded_p95_ms' => 1500],
],
```

Per-component `thresholds` can override `min_requests`, `min_signals`, `degraded_error_rate`,
`outage_error_rate`, `latency_degraded_p95_ms`, `latency_outage_p95_ms` and `confirm_runs`.
`sla.default_target` sets a target for every component without its own `sla`.

**Maintenance in a deploy script**: start a window before the work and end it after (traffic
from before the end of a window is ignored, so the deploy's own errors never open an incident):

```bash
php artisan status:maintenance --all --minutes=15
# … deploy …
php artisan status:maintenance --end=all
```

Then record the deploy, so it shows as a line on the charts and incidents that start soon after
name it ("6 min after deploy v1.8.2"):

```bash
php artisan status:deploy 1.8.2 --note="New checkout"   # no version = read from git
```

From CI: `POST {path}/deploys` with `Authorization: Bearer $SYSTEM_STATUS_DEPLOY_TOKEN` and
`version` (plus `commit`, `note`, `deployed_by`). `deploys.compare.enabled` adds one alert when
errors or p95 got clearly worse after a deploy.

Only some components: `php artisan status:maintenance database redis --minutes=30
--title="Database upgrade"`. Later: `--start="2026-10-25 02:00"`. `--list` shows windows,
`--end=<id|all>` ends them. The page has a *Schedule maintenance* form too.

**Incidents by hand**: on the page, *Report incident* (component, degraded or major outage,
title, message, state). Add timeline updates (Investigating, Identified, Monitoring,
Resolved) and, once resolved, a postmortem (cause & fix). A manual incident keeps the component
at its severity until it is resolved; automatic incidents resolve when the checks recover and
cannot be resolved by hand. Limit who may post with `access.manage_gate` (a Gate ability).

**Alert channels**

```php
'alerts' => [
    'channels' => ['telegram', 'mail', 'slack', 'discord'],   // any of these
    'telegram_chat_id' => env('SYSTEM_STATUS_TELEGRAM_CHAT_ID'), // + services.telegram-bot-api.token
    'mail_to' => array_filter(explode(',', (string) env('SYSTEM_STATUS_ALERT_MAIL_TO', ''))),
    'slack_webhook' => env('SYSTEM_STATUS_SLACK_WEBHOOK'),
    'discord_webhook' => env('SYSTEM_STATUS_DISCORD_WEBHOOK'),
    'webhooks' => [['url' => 'https://…', 'secret' => env('…')]],  // signed JSON events
    'flap' => ['changes' => 4, 'minutes' => 30],                  // null = off
    'reminder_minutes' => env('SYSTEM_STATUS_REMINDER_MINUTES'),  // "still down" repeats
],
```

Webhook receivers verify `X-System-Status-Signature` = `'sha256='.hash_hmac('sha256', $rawBody, $secret)`
with `hash_equals()`. Check every channel with `php artisan status:test-alert`.

**Suspicious activity** (`security.*`, off by default): per-IP request rules (`requests`:
`statuses` and/or `paths`, `max` within `minutes`), failed logins and new-country / new-IP
logins (from Laravel's auth events; passwords are never read), signal volume per component
(`signals.otp` => `max`, `max_per_ip`, `minutes`) and risky actions
(`Technobase\SystemStatus\Support\StatusSecurity::action('export')`; `actions.<name>` =>
`alert` or `max` + `minutes`; `quiet_hours`). Findings go to `status_security_events`, the page's
*Suspicious activity* panel, `php artisan status:security`, the alert channels and
`SuspiciousActivityDetected`. Behind a proxy, set trusted proxies so `$request->ip()` is real.
Add the server's own IPs and the office to `ignore_ips`. `SecurityConfigProbe` (and
`status:doctor`) flag `APP_DEBUG` in production, an empty `APP_KEY`, a readable `.env` and
pending migrations. Auto-block (`security.block`, also Cloudflare) answers 403 to IPs with a
serious or critical finding; critical = a scanned file served, a payload causing a 500,
honeypots (`honeypot.paths`, `<x-system-status::honeypot />`, `honeypot.canaries`), impossible
travel, a session used from another country. `ExposedFilesProbe`, `WebShellProbe`, `SecurityFilesProbe` (baseline
at every `status:deploy`) and `ComposerAuditProbe` go in a `security` group. When customers get a
Sanctum token at every login, set `watch_changes.tokens` to the staff model classes. Unblock:
`php artisan status:security --unblock=<ip>`.

**Reacting in code**: when matching on `ComponentStatus`, handle `Maintenance` too (or add a
`default` arm).

## Rules

- Probes are read-only and free: no POST to the application, no creating orders, payments,
  messages, OTPs or shipments, no paid API calls. Use a signal instead.
- Never put secrets in probe or signal messages: no URLs with tokens, no raw HTTP exception
  messages; use the exception class or a short text.
- Signals and traffic counting never throw; keep it that way around new calls.
- Never edit `vendor/`. A change to the engine or the page goes to the package repository,
  is released with a tag, then installed with `composer update technobase/laravel-system-status`.
- Showing the page to more people: change `access.roles` or set `access.gate`; never remove
  the check. Limit posting incidents and maintenance with `access.manage_gate`.
- Planned work (deploys, upgrades) goes in a maintenance window, not in `alerts.enabled =
  false`; turning alerts off hides real outages.
- Alert credentials and webhook secrets live in `.env`, never in the config file or in code.

## Commands

```bash
php artisan status:doctor             # check the setup first: tables, Redis, scheduler, components, probes, labels, alerts
php artisan status:check              # one run: probes, traffic, confirmation, incidents, alerts
php artisan status:routes --fallback  # routes that match no component
php artisan status:routes --component=api_orders
php artisan status:maintenance --all --minutes=10   # maintenance window (--list, --end=<id|all>)
php artisan status:test-alert         # test every alert channel (--channel=slack, --webhooks)
php artisan status:deploy 1.8.2       # record a deploy (default version from git; --list)
php artisan status:report --previous  # last month's uptime against SLA (--format=csv|html|pdf, --send)
php artisan status:prune              # delete history past retention_days
```

After adding or changing components, probes, `depends_on` or labels, run `status:doctor`: it
reports components without a source, unknown `depends_on` keys, probes that cannot be built,
missing labels and alert channels without credentials. After `composer update`, run
`php artisan migrate`.

## This application

<!-- List the groups and components, the application's own probes and signals, and anything
     special (proxies, firewalls, queues that only run in some environments). -->
