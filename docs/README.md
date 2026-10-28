# Laravel System Status documentation

[← Back to README](../README.md)

Full guide for `technobase/laravel-system-status`, the status page, uptime monitor and incident
tracker for Laravel.

| Page | Covers |
| --- | --- |
| [How it works](how-it-works.md) | components, sources, "worst source wins", two-run confirmation |
| [Installation and setup](installation.md) | requirements, install, the five integration steps, deployment |
| [Configuration reference](configuration.md) | every option in `config/system-status.php` and per-component keys |
| [Probes, signals, latency and dependencies](probes.md) | built-in probes, writing your own, `StatusSignal`, p95 thresholds, `depends_on` |
| [Incidents, maintenance windows and deploys](incidents.md) | manual incidents, timelines, postmortems, `status:maintenance`, `status:deploy` |
| [Alerts, webhooks and events](alerts.md) | Telegram, mail, Slack, Discord, signed webhooks, flap protection, Laravel events |
| [Suspicious activity and security](security.md) | attack detection, login abuse, honeypots, auto-block, Cloudflare, security probes |
| [SLA and uptime reports](reports.md) | uptime targets, monthly report as HTML, CSV or PDF |
| [Access, layout and translations](customization.md) | roles and gates, branding, standalone layout, English / Arabic / Kurdish, Pulse card, Claude Code skill |
| [Commands and database tables](reference.md) | every artisan command and its schedule, every table and how long it is kept |
| [Troubleshooting](troubleshooting.md) | common symptoms and fixes |
| [Upgrading, releases and contributing](contributing.md) | upgrades, automatic releases, running the tests |
