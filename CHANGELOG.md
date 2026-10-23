# Changelog

## Unreleased

- Maintenance windows: schedule them from the page or with `php artisan status:maintenance`.
  Covered components show *Maintenance*, raise no incident or alert, and the time does not
  count against uptime. New `ComponentStatus::Maintenance`, `MaintenanceStarted` /
  `MaintenanceEnded` events and the `status_maintenances` table.
- Manual incidents: post an incident from the page, add timeline updates (Investigating,
  Identified, Monitoring, Resolved) and a postmortem (cause & fix) note. A manual incident holds
  its component at its severity until resolved; only manual incidents can be resolved by hand.
  New `IncidentUpdated` event and `status_incident_updates` table.
- `access.manage_gate`: a Gate ability for posting incidents and scheduling maintenance.
- Alert channels: `alerts.channels` picks any of `telegram`, `mail`, `slack`, `discord`
  (`alerts.mail_to`, `alerts.slack_webhook`, `alerts.discord_webhook`). Default `['telegram']`.
- Flap protection (`alerts.flap`): a component that keeps changing status sends one
  "flapping" alert, then stays quiet until it is stable.
- "Still down" reminders for long major outages (`alerts.reminder_minutes`).
- Outgoing webhooks (`alerts.webhooks`): JSON events for status changes, incidents and
  maintenance, signed with `X-System-Status-Signature: sha256=<hmac>` when a secret is set.
- Recovery alerts show a readable duration ("recovered after 1 h 5 min").
- `php artisan status:test-alert`: sends a test alert to each channel (and webhooks) and shows
  the result.
- Latency: p50 / p95 response times per component and route, a response-time chart in the
  detail popup, and `thresholds.latency_degraded_p95_ms` / `latency_outage_p95_ms` (off by
  default).
- Per-component `thresholds` that override the global ones.
- New probes: `SslCertificateProbe`, `DiskSpaceProbe`, `FailedJobsProbe`, `QueueSizeProbe`,
  `QueueWaitProbe`, `DnsProbe`, `TcpPortProbe` and `CallbackProbe`.
- Component dependencies (`depends_on`): a component that fails because its dependency fails
  shows "Affected by …", and its alert is folded into the dependency's.
- SLA targets (`sla.default_target`, per-component `sla`) and monthly uptime reports: the
  90-day view marks components below target, a report page with CSV and PDF
  (`<path>/reports/{YYYY-MM}.{html|csv|pdf}`, PDF needs `barryvdh/laravel-dompdf`),
  `php artisan status:report`, and a monthly email to `reports.mail_to`. New `status_monthly`
  table.
- `php artisan status:doctor`: checks tables, Redis, the scheduler, components, probes, labels
  and alert channels.
- Optional Laravel Pulse card: `<livewire:system-status.pulse cols="4" />`.
- `status:prune` also removes old incident updates and maintenance windows.
- Pest test suite on Orchestra Testbench (`composer test`, `composer lint`) and a GitHub
  Actions matrix: PHP 8.3 / 8.4, Laravel 12 / 13, SQLite, MySQL and PostgreSQL.

**Upgrade notes**

- Run `php artisan migrate`: five new migrations (maintenance windows, incident updates,
  latency columns, `affected_by`, `status_monthly`). `php artisan status:doctor` tells you if
  one is missing.
- `ComponentStatus` has a new case, `Maintenance`. Code with an exhaustive `match` on it must
  handle the new case.
- Flap protection is on by default: 4 status changes within 30 minutes pause the alerts of that
  component. Set `alerts.flap` to `null` to turn it off.
- The new `alerts.*`, `thresholds.*`, `access.manage_gate`, `sla` and `reports` keys are
  optional; a config published from an older version keeps working with the defaults.

## v1.3.0 - 2026-10-19

- MySQL 8 / MariaDB and SQLite support: the traffic and daily upserts and the detail popup's
  hourly chart no longer use PostgreSQL-only SQL (`Support\StatusSql`).

## v1.2.0 - 2026-10-19

- Standalone page: `layout` = `'standalone'` (or `null`) renders the page as its own full HTML
  document with Bootstrap 4, jQuery and Font Awesome, so it works without a backoffice layout.
- A configured `layout` view that does not exist falls back to the standalone page instead of
  an error.
- `standalone.css`, `standalone.js`, `standalone.rtl_locales`, `standalone.brand` (default
  `TechnoBase`) and `standalone.back_url` options; a back button in the standalone top bar.
- `back` translation (en, ar, ku).

## v1.1.1 - 2026-10-19

- MIT license.

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
