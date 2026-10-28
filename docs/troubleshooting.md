# Troubleshooting

[← Back to README](../README.md) · [All docs](README.md)

---

Start with `php artisan status:doctor`. It checks the tables and columns, Redis and its key
prefix, the scheduler, components without a source, unknown `fallback` and `depends_on` keys,
probes that cannot be built, missing labels, alert channels without credentials and closures
that break `config:cache`. It exits with an error when something is broken.

| What you see | Why | Fix |
| --- | --- | --- |
| "Waiting for the first status check" | `status:check` never ran | run it once; start the scheduler |
| Scheduler red | no run for more than `heartbeat_stale_minutes` | check the scheduler container / cron |
| "Status checks have been failing since …" | `status:check` runs but throws (shown with the exception class) | run `php artisan status:check` to see the error; often a missing migration |
| Probes, incidents or alerts doubled | several servers run the scheduler without a shared cache | use a shared cache store (see [Deployment](installation.md#deployment)) |
| Queue red, "heartbeat not processed" | no worker on a queue in `queues` | start the worker or remove the queue |
| An HTTP probe fails with "Cloudflare, not the app" | the firewall blocks the probe | allow the user agent or set `STATUS_PROBE_BASE_URL` |
| A component shows "No data" | no probe ran and no traffic yet | wait a run, or add a source to the component |
| A component shows "Not configured" | its probes return `skipped()` | configure the integration, or accept it |
| A component stays degraded although every check passes | an open manual incident holds it | resolve the incident on the page |
| A component shows "Maintenance" | it is in a maintenance window | `status:maintenance --list`, then `--end=<id>` |
| Traffic from another application shows up | two apps share one Redis with the same key prefix | give each app its own `REDIS_PREFIX` (or `redis_connection`) |
| A component name looks like "Api Orders" | no label for it | add `status_component_<key>` in `lang/vendor/system-status` |
| 403 on the page | the user has none of `access.roles` (or fails `access.gate`) | change the access settings |
| No "Report incident" / "Schedule maintenance" buttons | the user fails `access.manage_gate` | change the gate |
| No alerts | `local` environment, `SYSTEM_STATUS_ENABLED=false`, `SYSTEM_STATUS_ALERTS_ENABLED=false`, or a channel without credentials (Telegram: no chat id, bot token or package) | `php artisan status:test-alert` shows the result per channel |
| "… is flapping" and then silence | flap protection | wait until it is stable, fix the cause, or change `alerts.flap` |
| Report shows "—" for earlier months | `status_monthly` is filled from this version on | nothing to do; later months fill in |
| No PDF link on the report | `barryvdh/laravel-dompdf` is not installed | `composer require barryvdh/laravel-dompdf` |
| "Missing … Run php artisan migrate" from `status:doctor` | new migrations not run | `php artisan migrate` |
| Routes counted under `api_other` / `bo_other` | no rule matches them | `php artisan status:routes --fallback`, then add `uris` |
