# Incidents, maintenance windows and deploys

[← Back to README](../README.md) · [All docs](README.md)

---

## Incidents

`status:check` opens, upgrades and resolves incidents by itself. You can also **post an
incident by hand** from the page (*Report incident*): choose the component, the severity
(*degraded* or *major outage*), a title, an optional message and the starting state.

- **A manual incident holds its component** at its severity until someone resolves it, even
  when every check passes. It does not open a second, automatic incident for the same problem.
- **Timeline**: add updates with a state (*Investigating*, *Identified*, *Monitoring*,
  *Resolved*) and a message. Each update is shown on the page with its author and time.
- **Only manual incidents can be resolved by hand.** Automatic incidents can get timeline
  updates, but they resolve when the checks recover.
- **Postmortem**: on a resolved incident, write a *cause & fix* note. It is shown under the
  incident.
- Posting an incident and every update send an alert to the configured channels, fire
  `IncidentUpdated` and send the `incident.posted`, `incident.updated` or `incident.postmortem`
  webhook.

Who may post: everyone who can open the page, or only users allowed by `access.manage_gate`
(see [Access](customization.md#access)).

## Maintenance windows

Planned work should not page anyone. During a maintenance window the chosen components show a
blue **Maintenance** status, no incident is opened, no alert is sent, and the time does not
count against uptime (on the page and in the [SLA report](reports.md#sla-and-reports)).

Schedule one from the page (*Schedule maintenance*, for users allowed by
`access.manage_gate`) or from the command line:

```bash
php artisan status:maintenance --all --minutes=10                       # every component, now, 10 minutes
php artisan status:maintenance database redis --start="2026-10-25 02:00" --minutes=60 \
    --title="Database upgrade" --message="Expect short interruptions."
php artisan status:maintenance --list                                   # active and upcoming windows
php artisan status:maintenance --end=12                                 # end window #12 now
php artisan status:maintenance --end=all                                # end every active window
```

`--start` is read in the application's timezone. Ending a window that has not started yet
cancels it.

In a deploy script, open a window before the work and end it after:

```bash
php artisan status:maintenance --all --minutes=15
# … deploy …
php artisan status:maintenance --end=all
```

After a window ends, only traffic recorded after its end counts, so errors from the deploy
itself never open an incident, and a problem must still be confirmed by two runs in a row as
usual. While the application is down (`php artisan down`), the scheduler does not run
`status:check`.

How it behaves:

- A window starts and ends on the next `status:check` run (so within a minute).
- When it ends, the component takes its observed status at once, without the two-run
  confirmation. A problem found right after the work opens an incident as usual.
- "Still down" reminders and dependency folding skip components in maintenance.
- `MaintenanceStarted` and `MaintenanceEnded` fire, and the `maintenance.started` /
  `maintenance.ended` [webhooks](alerts.md#webhooks) are sent.

## Deploy markers

Record each deploy, and the page shows it as a line on the bars and on the detail charts, with
"Last deploy" under the status and a *Recent deploys* list. An incident that starts within
`deploys.incident_window_minutes` (30) after a deploy says "6 min after deploy v1.8.2", on the
page, in the alert and in the monthly report.

At the end of the deploy script:

```bash
php artisan migrate --force
php artisan status:deploy 1.8.2 --note="New checkout"
```

Without a version, it reads `git describe --tags --always` and the commit from git. Other
options: `--commit=`, `--by=`, `--at="2026-10-25 14:05"`, and `--list` for the last deploys.
(The version is an argument because `--version` belongs to artisan itself.)

From CI, without SSH, set `SYSTEM_STATUS_DEPLOY_TOKEN` and POST to `{path}/deploys`:

```bash
curl -X POST https://shop.example.com/system-status/deploys \
  -H "Authorization: Bearer $SYSTEM_STATUS_DEPLOY_TOKEN" \
  -d version=1.8.2 -d commit=$GITHUB_SHA -d deployed_by=$GITHUB_ACTOR
```

The endpoint answers 404 while no token is set. Fields: `version` (required), `commit`, `note`,
`deployed_by`, `deployed_at`.

```php
'deploys' => [
    'token' => env('SYSTEM_STATUS_DEPLOY_TOKEN'),
    'incident_window_minutes' => 30,
    'announce' => false,          // also send "🚀 Deployed 1.8.2" to the alert channels
    'compare' => [
        'enabled' => false,       // before/after comparison
        'after_minutes' => 15,
        'error_rate_increase' => 2,      // percentage points
        'p95_increase_percent' => 50,    // and at least 100 ms
    ],
],
```

With `compare.enabled`, `status:check` compares each component's error rate and p95 in the
`after_minutes` after a deploy with the same time before it, and sends one alert when they got
clearly worse ("⚠️ Worse in the 15 min after deploy 1.8.2: Orders: errors 0.3% → 4.1%"). The
marker turns red on those components and *Recent deploys* shows the result. Listen to
`Technobase\SystemStatus\Events\DeployRecorded` to react in code.
