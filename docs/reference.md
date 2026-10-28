# Commands and database tables

[← Back to README](../README.md) · [All docs](README.md)

---

## Commands and schedule

| Command | When | What |
| --- | --- | --- |
| `status:check` | every minute (`withoutOverlapping(5)`, in background) | flushes traffic counters, runs due probes, evaluates every component, applies maintenance windows and dependencies, confirms changes, writes incidents, history and alerts, sends reminders and queue heartbeats |
| `status:prune` | daily at 03:30 | deletes history older than `retention_days` |
| `status:report --previous --send` | monthly on the 1st at 08:00, only when `reports.mail_to` is set | emails last month's uptime report |
| `status:routes` | by hand | shows which component every route counts under (`--fallback`, `--component=`) |
| `status:maintenance` | by hand / deploy scripts | schedules, lists or ends maintenance windows (`components…`, `--all`, `--minutes=30`, `--start=`, `--title=`, `--message=`, `--end=<id\|all>`, `--list`) |
| `status:report` | by hand | monthly uptime against SLA (`--month=YYYY-MM`, `--previous`, `--format=table\|csv\|html\|pdf`, `--output=`, `--mail=`, `--send`) |
| `status:test-alert` | by hand | sends a test alert to each channel and shows the result (`--channel=`, `--webhooks`, `--force`) |
| `status:doctor` | by hand | checks the setup and dangerous settings, one line per check (`--no-http`) |
| `status:deploy` | deploy scripts | records a deploy (`release`, `--commit=`, `--note=`, `--by=`, `--at=`, `--list`) |
| `status:security` | by hand; `--expire` every 5 minutes with auto-block | lists suspicious activity (`--hours=24`, `--type=`, `--ip=`, `--limit=50`), blocked IPs (`--blocked`, `--unblock=<ip>`, `--expire`) and saves the code file baseline (`--baseline`) |

Set `schedule` to `false` to schedule them yourself.

## Database tables

| Table | Holds | Kept |
| --- | --- | --- |
| `status_components` | current status, candidate status, message, `configured`, `affected_by`, last check | always |
| `status_checks` | one row per probe run | 7 days |
| `status_route_buckets` | requests, errors, slow requests, total time and a response-time histogram (`le_50` … `le_inf`) per component, route and 5 minutes; signals use route `(signal)` | 7 days |
| `status_daily` | runs and degraded / outage / maintenance runs per component and day | 90 days |
| `status_monthly` | the same per component and month, for SLA reports | always |
| `status_incidents` | incidents opened by `status:check` or posted by hand: severity, title, state, source (`auto` / `manual`), `affected_by`, postmortem | 90 days after resolution |
| `status_incident_updates` | the timeline of each incident: state, message, author | with their incident |
| `status_maintenances` | maintenance windows: title, message, components, start and end | 90 days after they end |
| `status_deploys` | deploys: version, commit, note, who, when, and the before/after comparison | 90 days |
| `status_security_events` | suspicious activity findings: rule, IP, subject, country, hits, when | 90 days (`retention_days.security`) |
| `status_security_logins` | the IPs and countries each watched user signed in from | 90 days after the last login |
| `status_security_blocks` | blocked IPs: until when, why, the Cloudflare rule | a day after the block ends |

The migrations create tables and columns only when they do not exist yet, so they are safe on a
database that already has them.
