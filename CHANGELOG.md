# Changelog

## Unreleased (v1.1.0)

- `ComponentStatusChanged` event on every confirmed status change.
- `alerts.enabled` (`SYSTEM_STATUS_ALERTS_ENABLED`) to turn the Telegram alerts off.
- `access.gate`: a Gate ability instead of the role check.
- `refresh_seconds`: refresh interval of the page per range.
- `extra_css`: a CSS file of the application for branding.
- `redis_connection`: Redis connection of the traffic counters.
- `system-status-skill` publish tag: a Claude Code skill for the application.
- The migration skips tables that already exist.
- Full README with screenshots; maintainer skill in `.claude/skills/laravel-system-status`.

## v1.0.0 - 2026-10-18

- First release, extracted from Khatun-Ecommerce's System Status page (modules/monitoring).
- Probes, per-route traffic counting, signals, two-run confirmation, incidents, Telegram alerts.
- 1h / 24h / 7d / 90d history; English, Arabic and Sorani Kurdish.
- Configurable path, middleware, access roles, layout and stacks; auto-registered middleware
  and schedule.
