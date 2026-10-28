# How it works

[← Back to README](../README.md) · [All docs](README.md)

---

```
                 every minute                              every request
  scheduler ──▶ status:check ◀── Redis counters ◀── RecordRouteHealth middleware
                    │   ▲
                    │   └── StatusSignal::success() / failure()   (your code)
                    ▼
  probes, traffic, signals, manual incidents ──▶ worst source wins
                                                      │
                     in a maintenance window? ── yes ─┼─▶ "Maintenance" (no incident, no alert)
                                                      ▼
                        depends_on failing too? ──▶ "Affected by …" (alert folded into it)
                                                      │
                                       2 runs agree? ──▶ status changes
                                                      │
       incidents · daily and monthly history · alerts (Telegram, mail, Slack, Discord, webhooks) · events
                                                      │
                               page /system-status ◀──┘  (refreshes itself)
```

1. You describe your application as **components** in groups (for example *Platform*,
   *Mobile API*, *Backoffice*, *Integrations*) in `config/system-status.php`.
2. Each component gets its status from its sources, and **the worst one wins**:
   - its **probes**, run every `every` minutes by `status:check`;
   - its **routes**: real requests matched by route name or URI. Over the last 15 minutes an
     error rate of 5 % or more is *degraded*, 25 % or more is a *major outage*, as long as there
     were at least 20 requests. A slow p95 response time can count too (off by default, see
     [Latency](probes.md#latency));
   - its **signal**: success and failure reports from your code (at least 3 calls);
   - an open **manual incident**: it holds the component at its severity until someone resolves
     it.
3. A component inside a **maintenance window** shows *Maintenance* at once. It opens no incident
   and sends no alert, and the time does not count against uptime.
4. A failing component whose **dependency** (`depends_on`) fails too shows *Affected by …*, and
   its alert is folded into the dependency's.
5. A new status is **confirmed** only when two runs in a row agree, so one slow answer never
   pages anyone.
6. A confirmed change opens, upgrades or resolves an **incident**, sends one **alert** to the
   configured channels and webhooks, and fires `ComponentStatusChanged`.
7. The scheduler cannot report its own outage: the first run after a gap records a resolved
   scheduler incident for the gap, and the page shows the scheduler red while runs are late.

A component with no data yet shows **No data**, never a fake *Operational*. A probe that is not
configured (missing API key, integration turned off) shows **Not configured** and does not
count as a failure.
