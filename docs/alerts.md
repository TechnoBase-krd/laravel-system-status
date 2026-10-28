# Alerts, webhooks and events

[← Back to README](../README.md) · [All docs](README.md)

---

## Alerts

Status changes, manual incidents and their updates, flapping notices and reminders are sent to
every channel in `alerts.channels`. Nothing is sent in the `local` environment (the alert is
logged instead), and `SYSTEM_STATUS_ALERTS_ENABLED=false` turns everything off.

### Channels

| Channel | Needs |
| --- | --- |
| `telegram` | `laravel-notification-channels/telegram`, the bot token in `services.telegram-bot-api.token` and `alerts.telegram_chat_id` |
| `mail` | `alerts.mail_to` (`SYSTEM_STATUS_ALERT_MAIL_TO=ops@example.com,cto@example.com`); uses the default mailer |
| `slack` | an incoming webhook URL in `alerts.slack_webhook` |
| `discord` | a webhook URL in `alerts.discord_webhook` |

```php
'alerts' => [
    'channels' => ['telegram', 'slack'],
    'slack_webhook' => env('SYSTEM_STATUS_SLACK_WEBHOOK'),
    // …
],
```

Example messages:

```
[Shop status] 🟠 Delivery courier: degraded. Courier API answered HTTP 502 on 4 of 12 calls
[Shop status] ✅ Delivery courier recovered after 1 h 5 min.
[Shop status] 🔴 Database: major outage. … Also affected: Catalogue API, Orders API.
[Shop status] 🔴 Still down: Payments for 2 h.
```

A channel that fails is logged and never stops the run. Check the setup with:

```bash
php artisan status:test-alert                    # every configured channel
php artisan status:test-alert --channel=slack    # only Slack
php artisan status:test-alert --webhooks         # also POST a "test" event to alerts.webhooks
php artisan status:test-alert --force            # send even in local or with alerts off
```

### Flap protection

A component that changes status `alerts.flap.changes` times (default 4) within
`alerts.flap.minutes` (default 30) sends one "flapping" alert. Its later changes stay quiet
until it has been stable for `minutes`; then one "stable again" message follows. The page,
incidents and events are not affected. Set `'flap' => null` to turn this off.

### Reminders

With `alerts.reminder_minutes` set (e.g. `SYSTEM_STATUS_REMINDER_MINUTES=60`), a major outage
that is still open after that many minutes sends "Still down: … for 1 h", and again every
`reminder_minutes` until it is resolved. Components in maintenance get no reminders.

### Webhooks

`alerts.webhooks` receives a JSON POST for every event. Give each URL a secret to sign the
body:

```php
'webhooks' => [
    ['url' => 'https://ops.example.com/hooks/status', 'secret' => env('SYSTEM_STATUS_WEBHOOK_SECRET')],
],
```

The body:

```json
{
  "event": "component.status_changed",
  "app": "Shop",
  "sent_at": "2026-10-24T10:15:00+03:00",
  "data": { "component": "payments", "label": "Payments", "previous": "operational",
            "current": "major_outage", "message": "…", "changed_at": "2026-10-24T10:15:00+03:00" }
}
```

Events: `component.status_changed`, `incident.posted`, `incident.updated`,
`incident.postmortem`, `maintenance.started`, `maintenance.ended`, `deploy.recorded`,
`deploy.regression`, and `test` (from
`status:test-alert --webhooks`). Webhooks follow `alerts.enabled` and are not sent in `local`.

With a secret, the header `X-System-Status-Signature: sha256=<hmac>` holds the HMAC-SHA256 of
the raw body. Verify it on the receiving side:

```php
$expected = 'sha256='.hash_hmac('sha256', $request->getContent(), config('services.status.webhook_secret'));

abort_unless(hash_equals($expected, (string) $request->header('X-System-Status-Signature')), 401);
```

## Reacting to status changes

Every confirmed change fires `Technobase\SystemStatus\Events\ComponentStatusChanged`:

| Property | Type |
| --- | --- |
| `component` | `string` key, e.g. `payments` |
| `label` | `string` display name |
| `previous`, `current` | `Technobase\SystemStatus\Enums\ComponentStatus` (`Operational`, `Degraded`, `MajorOutage`, `Maintenance`) |
| `message` | `?string` reason |
| `changedAt` | `CarbonInterface` |
| `isRecovery()` | `true` when the component is operational again |

`ComponentStatus::Maintenance` is a new case. Entering and leaving maintenance does not fire
`ComponentStatusChanged`, but code with an exhaustive `match` on `ComponentStatus` (no
`default` arm) must handle it.

Other events:

| Event | Fired when | Properties |
| --- | --- | --- |
| `IncidentUpdated` | an incident is posted by hand, gets a timeline update or a postmortem | `incident` (`StatusIncident`), `action` (`IncidentUpdated::POSTED`, `UPDATED` or `POSTMORTEM`), `update` (`?StatusIncidentUpdate`) |
| `MaintenanceStarted` | the first `status:check` run inside a maintenance window | `maintenance` (`StatusMaintenance`: `title`, `message`, `components`, `starts_at`, `ends_at`) |
| `MaintenanceEnded` | the first run after a window ended (or was ended early) | `maintenance` |

All events are in `Technobase\SystemStatus\Events`. Send them anywhere:

```php
use Illuminate\Support\Facades\Event;
use Technobase\SystemStatus\Events\ComponentStatusChanged;

Event::listen(function (ComponentStatusChanged $event) {
    Notification::route('mail', 'ops@example.com')->notify(new SystemStatusChanged($event));
});
```

A failing listener is logged and never stops the status run. To use only your own channel,
set `SYSTEM_STATUS_ALERTS_ENABLED=false` in `.env` (or `alerts.enabled` to `false`); the events
still fire. For the built-in channels, see [Alerts](#alerts).
