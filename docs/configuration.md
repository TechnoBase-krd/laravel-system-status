# Configuration reference

[← Back to README](../README.md) · [All docs](README.md)

---

All options live in `config/system-status.php`.

| Key | Default | What it does |
| --- | --- | --- |
| `enabled` | `env('SYSTEM_STATUS_ENABLED', true)` | `SYSTEM_STATUS_ENABLED=false` turns the whole package off: no traffic counting, signals, schedule, `status:check` runs or alerts, and every package route answers 404 (route names stay, so links keep working). Migrations and commands still load |
| `path` | `system-status` | URL of the page; the data endpoints are `<path>/summary`, `<path>/components/{key}` and `<path>/reports/{YYYY-MM}.{html\|csv\|pdf}` |
| `middleware` | `['web', 'auth']` | middleware of the page routes (the access check is always added) |
| `access.roles` | `['Super Admin']` | roles allowed in, checked with `$user->hasRole()` |
| `access.gate` | `null` | a Gate ability name; when set, it replaces the role check |
| `access.manage_gate` | `null` | a Gate ability for posting incidents, updates and postmortems and for scheduling maintenance; `null` = everyone who can open the page |
| `layout` | `layouts.app` | Blade layout the page extends; `'standalone'` (or `null`, or a view that does not exist) = the package's own full page |
| `stacks.styles` / `.modals` / `.scripts` | `styles` / `modals` / `scripts` | stack names in that layout |
| `standalone.css` / `.js` | Font Awesome 6 from a CDN / none | assets of the standalone page; point them at local files for an offline server or a strict CSP |
| `tailwind_cdn` | `@tailwindcss/browser@4.3.3` on jsDelivr | the Tailwind script that styles the dialogs, slide-over panels and forms; point it at a local copy for an offline server or a strict CSP |
| `standalone.rtl_locales` | `ar`, `ku`, `ckb`, `fa`, `he`, `ur` | locales the standalone page shows right to left |
| `standalone.brand` | `TechnoBase` | name in the standalone page's top bar, browser title and description line |
| `standalone.back_url` | `null` (`/`) | where the back button goes when there is no previous page of the application |
| `extra_css` | `null` | absolute path of a CSS file added after the package styles |
| `incidents_per_page` | `10` | incidents in *Past incidents* at first and per *Show more* click (1–100) |
| `refresh_seconds` | `['1h' => 20, 'default' => 60]` | seconds between automatic refreshes, per range |
| `record_traffic` | `true` | count every request (global middleware) |
| `schedule` | `true` | schedule `status:check`, `status:prune` and (with `reports.mail_to`) the monthly `status:report` |
| `schedule_on_one_server` | `true` | run those scheduled commands on one server only (`onOneServer()`), so several servers do not double probes, incidents and alerts; skipped when the cache store cannot lock |
| `heartbeat_store` | `null` | cache store that keeps the outcome of the last runs (`null` = default store), so a failing `status:check` shows on the page; use a shared store on several servers |
| `redis_connection` | `null` | Redis connection of the traffic counters (`null` = default) |
| `thresholds.window_minutes` | `15` | window for traffic and signal error rates and the p95 |
| `thresholds.min_requests` | `20` | fewer requests in the window = operational |
| `thresholds.min_signals` | `3` | fewer signal calls in the window = operational |
| `thresholds.degraded_error_rate` | `5` | % of failures for *degraded* |
| `thresholds.outage_error_rate` | `25` | % of failures for *major outage* |
| `thresholds.slow_request_ms` | `2000` | a request slower than this counts as slow |
| `thresholds.slow_probe_ms` | `3000` | an `HttpEndpointProbe` or `TcpPortProbe` slower than this is *degraded* |
| `thresholds.latency_degraded_p95_ms` | `null` | p95 response time (ms) of a component's routes above which it is *degraded*; `null` = off |
| `thresholds.latency_outage_p95_ms` | `null` | p95 response time (ms) above which it is a *major outage*; `null` = off |
| `thresholds.confirm_runs` | `2` | runs in a row that must agree before a status changes |
| `thresholds.heartbeat_stale_minutes` | `3` | scheduler and queue heartbeats older than this are an outage |
| `thresholds.probe_timeout_seconds` | `10` | timeout of the HTTP, TLS and TCP probes |
| `probe_base_url` | `env('STATUS_PROBE_BASE_URL')` | send HTTP probes straight to the app (e.g. `http://nginx`); `APP_URL`'s host is kept in `Host` |
| `probe_verify_ssl` | `env('STATUS_PROBE_VERIFY_SSL', true)` | `false` skips TLS checks, only for an internal probe base URL |
| `probe_user_agent` | `SystemStatusProbe/1.0` | User-Agent of the HTTP probes and outgoing webhooks (allow it in your firewall) |
| `concurrent_probes` | `true` | send the requests of the HTTP probes due in a run together, so a run takes as long as the slowest one; `false` = one after another |
| `alerts.enabled` | `env('SYSTEM_STATUS_ALERTS_ENABLED', true)` | send alerts and webhooks; `SYSTEM_STATUS_ALERTS_ENABLED=false` turns them off (the events still fire) |
| `alerts.channels` | `['telegram']` | any of `telegram`, `mail`, `slack`, `discord` |
| `alerts.telegram_chat_id` | `env('SYSTEM_STATUS_TELEGRAM_CHAT_ID')` | chat that receives Telegram alerts |
| `alerts.mail_to` | `env('SYSTEM_STATUS_ALERT_MAIL_TO')`, comma-separated | addresses for the `mail` channel (the default mailer) |
| `alerts.slack_webhook` | `env('SYSTEM_STATUS_SLACK_WEBHOOK')` | Slack incoming webhook URL |
| `alerts.discord_webhook` | `env('SYSTEM_STATUS_DISCORD_WEBHOOK')` | Discord webhook URL |
| `alerts.prefix` | `[APP_NAME status]` | first words of every alert |
| `alerts.webhooks` | `[]` | URLs that receive JSON events, e.g. `[['url' => '…', 'secret' => '…']]` (see [Webhooks](alerts.md#webhooks)) |
| `alerts.flap` | `['changes' => 4, 'minutes' => 30]` | after this many changes within `minutes`, one "flapping" alert, then quiet until stable; `null` = off |
| `alerts.reminder_minutes` | `env('SYSTEM_STATUS_REMINDER_MINUTES')` (`null`) | repeat "still down" for a major outage every this many minutes; `null` = no reminders |
| `sla.default_target` | `null` | monthly uptime target in % for components without their own `sla`; `null` = no target |
| `reports.mail_to` | `env('SYSTEM_STATUS_REPORT_MAIL_TO')`, comma-separated | email last month's report on the 1st at 08:00 to these addresses |
| `reports.format` | `csv` | format of the emailed report: `csv`, `html` or `pdf` |
| `reports.accent` | `#6F2E8F` | header colour of the PDF report (the web report follows the page's `--sst-accent` and your `extra_css`) |
| `retention_days` | checks 7, route_buckets 7, daily 90, incidents 90 | what `status:prune` keeps (`incidents` also covers incident updates and ended maintenance windows) |
| `queues` | `[['connection' => null, 'queue' => 'default']]` | queues that get a heartbeat job every run |
| `ignore_uris` | tools, health checks, this page | URIs that are never counted |
| `fallback` | `api/*` → `api_other`, `*` → `bo_other` | component for unmatched routes |
| `groups` | platform, mobile_api, backoffice | your components (see above) |

A component definition:

| Key | Example | Meaning |
| --- | --- | --- |
| `probes` | `[['class' => X::class, 'with' => [...], 'every' => 5]]` | probe classes; `with` = constructor arguments by name, `every` = minutes |
| `names` | `['website.*']` | route-name patterns |
| `uris` | `['api/v1/orders*']` | URI patterns |
| `signal` | `true` | accepts `StatusSignal` calls |
| `thresholds` | `['degraded_error_rate' => 2, 'latency_degraded_p95_ms' => 800]` | overrides the global thresholds for this component: `min_requests`, `min_signals`, `degraded_error_rate`, `outage_error_rate`, `latency_degraded_p95_ms`, `latency_outage_p95_ms`, `confirm_runs` |
| `depends_on` | `['database']` | components this one needs; see [Dependencies](probes.md#dependencies) |
| `sla` | `99.9` | monthly uptime target in %; wins over `sla.default_target` |

Configs published before these options existed keep working: missing keys use the defaults
above.
