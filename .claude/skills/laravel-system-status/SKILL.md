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
| `src/SystemStatusServiceProvider.php` | config merge, routes, views, translations, migrations, commands, traffic middleware, schedule, publish tags |
| `src/Services/StatusService.php` | one `status:check` run: probes, traffic, signals, confirmation, incidents, alerts, event, daily rollup |
| `src/Support/` | `StatusRegistry` (config + labels), `RouteComponentResolver`, `StatusMetrics` (Redis counters), `StatusSignal`, `StatusTimeline` (1h / 24h / 7d) |
| `src/Probes/` | `Probe`, `ProbeResult` and the generic probes |
| `src/Actions/` | data for the page (`GetStatusSummaryAction`) and the detail popup (`GetStatusComponentAction`) |
| `src/Http/` | controller, `AuthorizeSystemStatus`, `RecordRouteHealth`, `StatusSummaryRequest` |
| `src/Events/ComponentStatusChanged.php` | fired on every confirmed change |
| `config/system-status.php` | every option, documented inline |
| `database/migrations/` | the five `status_*` tables |
| `resources/views/index.blade.php` | the page (jQuery, Bootstrap modal, Font Awesome); extends the `$layout` the controller resolves |
| `resources/views/layouts/standalone.blade.php` | the package's own full HTML page, used for `layout` = `'standalone'` / `null` or a missing layout view |
| `resources/css/system-status.css` | page styles, `--sst-*` variables on `.sst` |
| `resources/claude/SKILL.md` | the skill applications publish with `--tag=system-status-skill` |
| `lang/{en,ar,ku}/status.php` | all page text and generic component labels |
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
- **Probes are read-only and free.**

## Checking a change

There are no automated tests yet. Before a release:

1. `php -l` on changed files and `vendor/bin/pint --test --preset laravel src config routes lang database`
   (Pint from either application).
2. Install the working copy into an application with a `path` repository
   (`{"type": "path", "url": "../laravel-system-status", "options": {"symlink": true}}`),
   run `composer dump-autoload`, then: `php artisan route:list --path=system-status`,
   `php artisan status:routes --fallback`, `php artisan status:check`, and open the page as a
   Super Admin, once with the application's layout and once with `'layout' => 'standalone'`.
   Switch the application back to the Packagist version afterwards.
3. Screenshots: a throwaway Laravel app with the package, a Bootstrap 4 + jQuery + Font Awesome
   layout and sample rows in the `status_*` tables; capture with headless Chrome at 1440 px
   (desktop) and 390 px (phone).

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
