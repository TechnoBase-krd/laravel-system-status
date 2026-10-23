# Changelog

## Unreleased

- Deploy markers: `php artisan status:deploy 1.8.2` (or `POST {path}/deploys` from CI with
  `deploys.token`) records a deploy. It shows as a line on the bars and the detail charts, as
  "Last deploy" and in a *Recent deploys* list; incidents that start within
  `deploys.incident_window_minutes` after it name it on the page, in the alert and in the
  monthly report. Optional before/after comparison (`deploys.compare`, off by default) sends one
  alert when errors or p95 got clearly worse. New `status_deploys` table, `DeployRecorded`
  event, `deploy.recorded` / `deploy.regression` webhook events and the
  `system_status.deploys.store` route.
- Fix: the standalone layout's top bar styles (`.sst-bar`) also applied to every history bar,
  making each one sticky; they now apply to the header only.

## v1.5.0 - 2026-10-23

- Faster status page with many components or a lot of history: a refresh only updates what
  changed instead of redrawing every bar, groups off screen are not drawn, and the summary is
  built about 4–8 times faster with a response about half the size (zero and empty values are
  left out of each bar).
- *Past incidents* is paged: the first `incidents_per_page` (10) incidents, then *Show more*.
  It now lists every stored incident, not only the last 14 days. New route
  `system_status.incidents.index` (`GET {path}/incidents?page=2`); the summary adds
  `incidents_meta` (`page`, `per_page`, `total`, `has_more`, `open`).
- The component detail popup opens with its name and status filled in, shows placeholders
  while loading, then fades the sections in, grows the chart bars and counts the numbers up.

## v1.4.0 - 2026-10-23

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

- New look for the write actions: report incident, schedule maintenance, timeline updates and
  postmortems open in slide-over panels (from the left in RTL) with colour-coded choices,
  duration presets (15 min to 4 h), a searchable component picker, a ⋯ menu on every component
  (prefilled forms), Ctrl / ⌘ + Enter to send, Esc to close and a toast after each save. The
  detail popup is a native dialog that fills the screen on phones.
- No more jQuery or Bootstrap: the page script is plain JavaScript and the dialogs and forms use
  Tailwind from a CDN (`tailwind_cdn`, prefixed `tw:` classes, no global reset). The standalone
  page now loads only Font Awesome. Better tablet and phone layouts, and pointer cursors on
  everything clickable.
- The monthly report has the status page's look and branding (brand bar, accent-coloured hero,
  KPI cards, panels, `extra_css`): a day-by-day strip per component, uptime bars against the
  SLA target, the month's incidents with their cause & fix, a month picker, CSV / PDF / print.
  `reports.accent` sets the PDF header colour.
- `composer serve`: a Testbench demo app with sample data (`workbench/`).

**Upgrade notes**

- The page no longer needs jQuery or the Bootstrap modal plugin. A layout that loaded them only
  for this page can drop them. Views published from an older version (`system-status-views`)
  should be published again.

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
