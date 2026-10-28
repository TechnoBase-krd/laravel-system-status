# Laravel System Status

[![Latest Version on Packagist](https://img.shields.io/packagist/v/technobase/laravel-system-status.svg)](https://packagist.org/packages/technobase/laravel-system-status)
[![Total Downloads](https://img.shields.io/packagist/dt/technobase/laravel-system-status.svg)](https://packagist.org/packages/technobase/laravel-system-status)
[![PHP Version](https://img.shields.io/packagist/php-v/technobase/laravel-system-status.svg)](https://packagist.org/packages/technobase/laravel-system-status)
[![License](https://img.shields.io/packagist/l/technobase/laravel-system-status.svg)](LICENSE)

**A self-hosted status page, uptime monitor and incident tracker for Laravel.** It shows
whether every part of your application works (database, Redis, queues, APIs, backoffice
screens, payment and delivery integrations), keeps 90 days of uptime history, opens incidents
by itself and alerts you on Telegram, Slack, Discord, mail or webhooks. Like githubstatus.com,
but inside your own Laravel app.

![Laravel status page with 24 hours of uptime history](docs/screenshots/range-24h.png)

## Features

- **Health checks (probes)**: database, Redis, Horizon, queues, HTTP endpoints, CDN, TLS
  certificate expiry, disk space, DNS, TCP ports, failed jobs, and your own integrations.
- **Real traffic monitoring**: every request is counted per route; 5xx error rates and slow
  p95 response times change the status.
- **Signals**: one line around paid or side-effect calls (SMS, OTP, social login, AI APIs).
- **No false alarms**: two checks must agree, flapping is grouped, and a failure caused by a
  dependency is folded into one alert.
- **Incidents**: opened and resolved automatically, or posted by hand with a timeline and a
  postmortem.
- **Maintenance windows and deploy markers**: planned work raises no alert; incidents show
  "6 min after deploy v1.8.2".
- **Alerts**: Telegram, mail, Slack, Discord, signed webhooks and Laravel events.
- **Security monitoring** (opt-in): scanners, SQL injection and XSS attempts, brute-force
  logins, logins from new countries, honeypots, auto-block with Cloudflare.
- **SLA reports**: monthly uptime per component, as a page, CSV or PDF.
- **History**: 1 hour (live), 24 hours, 7 days and 90 days.
- **Languages**: English, Arabic and Sorani Kurdish, right to left included.
- Works with **PostgreSQL, MySQL / MariaDB and SQLite**, inside your admin layout or as a
  standalone page. Optional [Laravel Pulse](https://pulse.laravel.com) card.

## Requirements

PHP 8.3+, Laravel 12 or 13, Redis (for traffic counters) and a running scheduler. Details in
[Installation](docs/installation.md#requirements).

## Quick start

```bash
composer require technobase/laravel-system-status
php artisan vendor:publish --tag=system-status-config
php artisan migrate
php artisan status:doctor     # checks the setup
```

Open **`/system-status`** as a Super Admin. The package registers the page, counts traffic and
schedules `status:check` every minute by itself; you only need the Laravel scheduler running.

Then describe your application as components in `config/system-status.php`:

```php
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Technobase\SystemStatus\Probes\HttpEndpointProbe;
use Technobase\SystemStatus\Probes\SpatieCheckProbe;

'groups' => [
    'platform' => [
        'database' => ['probes' => [['class' => SpatieCheckProbe::class, 'with' => ['check' => DatabaseCheck::class], 'every' => 1]]],
    ],
    'mobile_api' => [
        'api_orders' => [
            'probes' => [['class' => HttpEndpointProbe::class, 'with' => ['path' => '/api/v1/health'], 'every' => 2]],
            'uris' => ['api/v1/orders*'],     // real traffic on these routes counts too
            'depends_on' => ['database'],
        ],
    ],
],
```

Choose alert channels (Telegram is the default):

```php
'alerts' => [
    'channels' => ['slack'],      // any of telegram, mail, slack, discord
    'slack_webhook' => env('SYSTEM_STATUS_SLACK_WEBHOOK'),
],
```

```bash
php artisan status:test-alert
```

The full walkthrough is in [Installation and setup](docs/installation.md).

## Screenshots

| Real time (1 hour) | 7 days |
| --- | --- |
| ![Real-time uptime bars](docs/screenshots/range-1h.png) | ![7-day uptime history](docs/screenshots/range-7d.png) |

| Component details | Ongoing problem |
| --- | --- |
| ![Component requests, errors and latency](docs/screenshots/detail.png) | ![Degraded component with failing routes](docs/screenshots/detail-degraded.png) |

| Arabic (RTL) | Phone |
| --- | --- |
| ![Arabic right-to-left status page](docs/screenshots/arabic-rtl.png) | ![Mobile status page](docs/screenshots/mobile.png) |

## Documentation

| Topic | |
| --- | --- |
| [How it works](docs/how-it-works.md) | how a component's status is decided |
| [Installation and setup](docs/installation.md) | requirements, integration steps, deployment |
| [Configuration reference](docs/configuration.md) | every option |
| [Probes and signals](docs/probes.md) | built-in probes, custom probes, signals, latency, dependencies |
| [Incidents and maintenance](docs/incidents.md) | incidents, maintenance windows, deploy markers |
| [Alerts and events](docs/alerts.md) | channels, webhooks, Laravel events |
| [Security monitoring](docs/security.md) | suspicious activity, auto-block, security probes |
| [SLA reports](docs/reports.md) | monthly uptime reports |
| [Access, layout and translations](docs/customization.md) | roles, branding, languages, Pulse card |
| [Commands and tables](docs/reference.md) | artisan commands, database tables |
| [Troubleshooting](docs/troubleshooting.md) | common problems and fixes |
| [Upgrading and contributing](docs/contributing.md) | upgrades, releases, tests |

## Common commands

```bash
php artisan status:doctor                          # check the setup
php artisan status:check                           # run the checks now
php artisan status:routes --fallback               # routes that match no component
php artisan status:maintenance --all --minutes=15  # start a maintenance window
php artisan status:deploy 1.8.2                    # record a deploy
php artisan status:report --previous               # last month's uptime
```

## Upgrading

```bash
composer update technobase/laravel-system-status
php artisan migrate
```

`1.x` releases never break config keys, routes, translations or events. See
[CHANGELOG.md](CHANGELOG.md).

## Contributing

Issues and pull requests are welcome. Run `composer test` and `composer lint`, and use
[Conventional Commits](https://www.conventionalcommits.org/). See
[Contributing](docs/contributing.md).

## License

MIT. See [LICENSE](LICENSE). Made by [TechnoBase](https://github.com/TechnoBase-krd).
