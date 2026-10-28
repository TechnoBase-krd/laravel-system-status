# SLA and uptime reports

[← Back to README](../README.md) · [All docs](README.md)

---

## SLA and reports

Give components a monthly uptime target:

```php
'sla' => ['default_target' => 99.5],          // every component
'groups' => [
    'integrations' => [
        'payments' => ['probes' => [/* … */], 'sla' => 99.9],   // this one
    ],
],
```

Uptime is the share of checked minutes without a major outage. Degraded minutes count as up;
maintenance minutes are left out. The 90-day view marks components below their target.

**Report page.** The *Monthly report* button on the page opens
`/<path>/reports/{YYYY-MM}.html`: uptime, target, outage and maintenance minutes and incidents
per component, printable, with links to the previous and next month. The same report is
available as `.csv` and, with `barryvdh/laravel-dompdf` installed, as `.pdf`. The route is
`system_status.report` and follows the page's access rules.

**Command.**

```bash
php artisan status:report                                # this month, as a table
php artisan status:report --previous                     # last month
php artisan status:report --month=2026-09 --format=csv --output=storage/uptime-2026-09.csv
php artisan status:report --previous --format=pdf --mail=ceo@example.com
php artisan status:report --previous --send              # to reports.mail_to
```

**Monthly email.** With `reports.mail_to` set (`SYSTEM_STATUS_REPORT_MAIL_TO=ceo@example.com`),
last month's report is emailed on the 1st of each month at 08:00, attached in `reports.format`
(`csv`, `html` or `pdf`).

The report reads the `status_monthly` table, which is never pruned. The migration fills it from
the daily history still in `status_daily` (90 days by default), so recent months have data
right after the upgrade.
