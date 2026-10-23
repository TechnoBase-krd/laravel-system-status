---
name: laravel-system-status
description: Working on the technobase/laravel-system-status package itself — its structure, what belongs in the package versus an application, backward compatibility for the projects that use it (Khatun-Ecommerce, CloudNova-OnlineShopping), driver-specific SQL, migrations, translations, configuration keys, the ComponentStatusChanged event, the publishable Claude skill, screenshots and the release process. Use for any change in this repository.
---

# Maintaining technobase/laravel-system-status

## What this package is

A status page for Laravel backoffices, extracted from Khatun-Ecommerce. Repository
`TechnoBase-krd/laravel-system-status`; Composer name `technobase/laravel-system-status`;
namespace `Technobase\SystemStatus`. Public on Packagist (MIT); used by Khatun-Ecommerce and CloudNova-OnlineShopping.

## Layout

| Path | Holds |
| --- | --- |
| `src/SystemStatusServiceProvider.php` | config merge, routes, views, translations, migrations, commands, traffic middleware, schedule (incl. the monthly report), publish tags, the optional Pulse card |
| `src/Services/StatusService.php` | one `status:check` run: probes, traffic, latency, signals, manual incidents, maintenance windows, dependencies, confirmation, incidents, alerts, reminders, events, daily and monthly rollup |
| `src/Services/IncidentService.php` | manual incidents, timeline updates, postmortems, `IncidentUpdated` |
| `src/Services/MaintenanceService.php` | schedules and ends maintenance windows (page and `status:maintenance`) |
| `src/Services/DeployService.php` | records deploys (`status:deploy`, `POST {path}/deploys`), reads the version from git, compares errors and p95 before/after a deploy |
| `src/Support/` | `StatusRegistry` (config, labels, per-component `threshold()`), `RouteComponentResolver`, `StatusMetrics` (Redis counters), `StatusSignal`, `StatusTimeline` (1h / 24h / 7d), `StatusSql` (driver-specific SQL), `StatusAlerter` (channels, flap protection, folded alerts, webhooks), `LatencyHistogram` (`le_*` buckets, p50 / p95), `StatusDuration` ("1 h 5 min") |
| `src/Probes/` | `Probe`, `ProbeResult` and the generic probes (Spatie, HTTP, queue heartbeat, CDN, Telegram, TLS certificate, disk space, failed jobs, queue size, queue wait, DNS, TCP port, callback) |
| `src/Actions/` | data for the page (`GetStatusSummaryAction`), the detail popup (`GetStatusComponentAction`) and the monthly SLA report (`GetStatusReportAction`: CSV, HTML, PDF) |
| `src/Console/` | `status:check`, `status:prune`, `status:routes`, `status:maintenance`, `status:test-alert`, `status:report`, `status:doctor`, `status:deploy` |
| `src/Http/` | controllers (page, management, report), `AuthorizeSystemStatus`, `AuthorizeSystemStatusManagement` (`access.manage_gate`), `RecordRouteHealth`, form requests |
| `src/Enums/` | `ComponentStatus` (incl. `Maintenance`), `IncidentState` |
| `src/Events/` | `ComponentStatusChanged` (every confirmed change), `IncidentUpdated`, `MaintenanceStarted`, `MaintenanceEnded` |
| `src/Models/` | `StatusComponent`, `StatusCheck`, `StatusIncident`, `StatusIncidentUpdate`, `StatusMaintenance` |
| `src/Pulse/SystemStatusCard.php` | Laravel Pulse card, registered only when Pulse and Livewire are installed |
| `config/system-status.php` | every option, documented inline |
| `database/migrations/` | six migrations: the original `status_*` tables plus maintenance windows, incident updates, latency columns, `affected_by`, `status_monthly` |
| `resources/views/index.blade.php` + `partials/` | the page (plain JS, Font Awesome; `partials/detail` dialog, `partials/panels` slide-over forms and ⋯ menu, `partials/script`, `partials/tailwind` = CDN Tailwind with `tw:` prefix, important utilities and no preflight); extends the `$layout` the controller resolves. Tailwind classes always use the `tw:` prefix; never add jQuery or Bootstrap |
| `resources/views/layouts/standalone.blade.php` | the package's own full HTML page, used for `layout` = `'standalone'` / `null` or a missing layout view |
| `resources/views/report.blade.php` | the monthly uptime report (page, email attachment, PDF source) |
| `resources/views/pulse/` | the Pulse card view |
| `resources/css/system-status.css` | page styles, `--sst-*` variables on `.sst` |
| `resources/claude/SKILL.md` | the skill applications publish with `--tag=system-status-skill` |
| `lang/{en,ar,ku}/status.php` | all page text and generic component labels |
| `tests/` | Pest suite on Orchestra Testbench: `Feature/`, `Unit/`, `Fixtures/`, `TestCase.php` (DB driver from env or `tests/.env.testing`), `helpers.php` |
| `.github/workflows/tests.yml` | CI: `composer lint` and the test matrix |
| `docs/screenshots/` | README images |

## Rules

- **Generic only.** Nothing that belongs to one application: no project names, integrations,
  env variable names of a project, or business logic. Applications add components, probes,
  labels and listeners on their side.
- **Backward compatible within a major version.** Never rename or remove a config key, route
  name (`system_status.*`), translation key, public class, method or event property in a
  1.x release. New options get defaults that keep the current behaviour.
- **Migrations.** Never change a released migration's columns; add a new migration. Table
  creation is guarded with `Schema::hasTable()` so a database that already has the tables is
  safe. Keep the original file name `2026_10_18_120000_create_status_tables.php`.
- **Databases.** PostgreSQL, MySQL / MariaDB and SQLite are supported. SQL that differs per
  driver lives in `Support\StatusSql` (incrementing upserts, hour formatting); add new
  driver-specific SQL there, never inline in a query.
- **Never throw from counting.** `StatusMetrics::record()`, `StatusSignal` and event listeners
  are wrapped so a status problem never breaks a request or a run.
- **No secrets in messages.** Probe messages use the exception class, never the raw message.
- **Translations.** Every new key in `en`, `ar` and `ku`. Component labels fall back to the key
  in words when missing.
- **Probes are read-only and free.** A probe's constructor argument names are its `with` keys,
  so renaming one is a breaking change.
- **New enum cases break exhaustive `match`es in applications.** Prefer new fields over new
  `ComponentStatus` cases, and call them out in the changelog's upgrade notes.
- **Alerts go through `StatusAlerter`**, never straight to a channel, so `alerts.enabled`, the
  `local` check, flap protection and dependency folding apply everywhere.

## Checking a change

Every change comes with tests. Before pushing:

1. `composer test` (Pest) and `composer lint` (Pint, laravel preset, on `src config routes lang
   database tests`; `composer format` fixes it). Tests use in-memory SQLite by default; to run
   them on MySQL or PostgreSQL, put `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
   `DB_USERNAME` and `DB_PASSWORD` in the git-ignored `tests/.env.testing`. Tests that need
   Redis (`127.0.0.1:6379`, database 15) skip themselves when it is not running.
2. CI (`.github/workflows/tests.yml`) runs `composer lint` and the suite on PHP 8.3 / 8.4 ×
   Laravel 12 / 13 × SQLite / MySQL 8 / PostgreSQL 16 for every pull request and push to
   `main`. Driver-specific SQL must pass on all three.
3. For page or front-end changes, install the working copy into an application with a `path`
   repository (`{"type": "path", "url": "../laravel-system-status", "options": {"symlink": true}}`),
   run `composer dump-autoload` and `php artisan migrate`, then: `php artisan status:doctor`,
   `php artisan route:list --path=system-status`, `php artisan status:routes --fallback`,
   `php artisan status:check`, and open the page as a Super Admin, once with the application's
   layout and once with `'layout' => 'standalone'`. Switch the application back to the
   Packagist version afterwards.
4. Screenshots: `composer serve` (Testbench demo in `workbench/` with sample data), open
   `/demo-login` (`?locale=ar` for RTL) and capture with headless Chrome at 1440 px (desktop),
   820 px (tablet) and 390 px (phone).

## Releasing

Releases are automatic: `.github/workflows/release.yml` runs on every push to `main`, checks PHP
syntax and `composer.json`, and creates the next `vX.Y.Z` tag plus a GitHub Release from the
commit messages since the last tag (`.github/scripts/next-version.sh`,
`.github/scripts/release-notes.sh`):

- `type!:` or a `BREAKING CHANGE:` body line → major; `feat:` → minor;
  `fix:` / `perf:` / `refactor:` / `revert:` → patch; only `docs:` / `chore:` / `ci:` /
  `style:` / `test:` → no release.
- *Actions → Release → Run workflow* forces `patch`, `minor` or `major`.
- Never create, move or delete `v*` tags by hand.

So the commit prefix is the release decision: use `feat:` for a new option, `fix:` for a bug,
`feat!:` only for a change that breaks an application. Before pushing, move the "Unreleased"
notes in `CHANGELOG.md` under the version the commits will produce.

Then, in each application: `composer update technobase/laravel-system-status`, check the page,
and commit `composer.lock`.
