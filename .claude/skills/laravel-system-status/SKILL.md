---
name: laravel-system-status
description: Working on the technobase/laravel-system-status package itself — its structure, what belongs in the package versus an application, backward compatibility for the projects that use it (Khatun-Ecommerce, CloudNova-OnlineShopping), PostgreSQL-only SQL, migrations, translations, configuration keys, the ComponentStatusChanged event, the publishable Claude skill, screenshots and the release process. Use for any change in this repository.
---

# Maintaining technobase/laravel-system-status

## What this package is

A status page for Laravel backoffices, extracted from Khatun-Ecommerce. Repository
`TechnoBase-krd/laravel-status-page`; Composer name `technobase/laravel-system-status`;
namespace `Technobase\SystemStatus`. Used by Khatun-Ecommerce and CloudNova-OnlineShopping
through a private `vcs` repository with `preferred-install` = `source` for `technobase/*`.

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
| `resources/views/index.blade.php` | the page (jQuery, Bootstrap modal, Font Awesome) |
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
- **PostgreSQL.** The detail popup uses `to_char` / `date_trunc` and the daily rollup uses an
  upsert with `excluded`; supporting another database means rewriting those queries.
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
   Super Admin. Switch the application back to the `vcs` repository afterwards.
3. Screenshots: a throwaway Laravel app with the package, a Bootstrap 4 + jQuery + Font Awesome
   layout and sample rows in the `status_*` tables; capture with headless Chrome at 1440 px
   (desktop) and 390 px (phone).

## Releasing

1. Update `CHANGELOG.md` (move "Unreleased" under the new version and date).
2. Commit, then tag with a semantic version: `git tag -a v1.2.0 -m "v1.2.0"`, push `main` and the tag.
3. In each application: `composer update technobase/laravel-system-status`, check the page, and
   commit `composer.lock`.

Patch (`1.0.x`) for fixes, minor (`1.x.0`) for new options, major for anything that breaks an
application.
