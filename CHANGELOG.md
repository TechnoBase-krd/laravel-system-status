# Changelog

## Unreleased

- MySQL 8 / MariaDB and SQLite support: the traffic and daily upserts and the detail popup's
  hourly chart no longer use PostgreSQL-only SQL (`Support\StatusSql`).
- Standalone page: `layout` = `'standalone'` (or `null`) renders the page as its own full HTML
  document with Bootstrap 4, jQuery and Font Awesome, so it works without a backoffice layout.
- A configured `layout` view that does not exist falls back to the standalone page instead of
  an error.
- `standalone.css`, `standalone.js`, `standalone.rtl_locales`, `standalone.brand` (default
  `TechnoBase`) and `standalone.back_url` options; a back button in the standalone top bar.
- `back` translation (en, ar, ku).

## v1.1.0 - 2026-10-18

- `ComponentStatusChanged` event on every confirmed status change.
- `alerts.enabled` (`SYSTEM_STATUS_ALERTS_ENABLED`) to turn the Telegram alerts off.
- `access.gate`: a Gate ability instead of the role check.
- `refresh_seconds`: refresh interval of the page per range.
- `extra_css`: a CSS file of the application for branding.
- `redis_connection`: Redis connection of the traffic counters.
- `system-status-skill` publish tag: a Claude Code skill for the application.
- The migration skips tables that already exist.
- Full README with screenshots; maintainer skill in `.claude/skills/laravel-system-status`.
- Automatic releases: GitHub Actions creates the version tag and the GitHub Release from the commit messages.

## v1.0.0 - 2026-10-18

- First release, extracted from Khatun-Ecommerce's System Status page (modules/monitoring).
- Probes, per-route traffic counting, signals, two-run confirmation, incidents, Telegram alerts.
- 1h / 24h / 7d / 90d history; English, Arabic and Sorani Kurdish.
- Configurable path, middleware, access roles, layout and stacks; auto-registered middleware
  and schedule.
