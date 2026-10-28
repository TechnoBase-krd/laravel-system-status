# Access, layout and translations

[← Back to README](../README.md) · [All docs](README.md)

---

## Access

The page shows infrastructure details, so it is closed by default.

- **Roles** (default): `access.roles` = `['Super Admin']`, checked with `hasRole()` from
  `spatie/laravel-permission`. A user model without `hasRole()` is refused.
- **Gate**: set `access.gate` to an ability name and define it:

  ```php
  Gate::define('viewSystemStatus', fn (User $user) => $user->is_admin);
  ```

- **Managing**: posting incidents, timeline updates and postmortems and scheduling maintenance
  is open to everyone who can see the page. To limit it, set `access.manage_gate`:

  ```php
  Gate::define('manageSystemStatus', fn (User $user) => $user->hasRole('Super Admin'));
  ```

  Users without it see the page but not the buttons, and the write routes answer 403.

The page always needs a signed-in user (`middleware`). The data endpoints and the report follow
the same rules.

## Look and feel

The page follows the host theme: its colours use `--success`, `--warning`, `--danger`,
`--primary`, `--border` and `--muted` when the theme defines them.

**Brand colours** with a small CSS file:

```php
'extra_css' => resource_path('css/system-status.css'),
```

```css
.sst {
    --sst-accent: #0f766e;     /* KPI accents, selected range */
    --sst-ok: #16a34a;
    --sst-degraded: #f59e0b;
    --sst-partial: #f97316;
    --sst-major: #dc2626;
    --sst-radius: .5rem;
}
```

### Layout

The page works in any Laravel application, with or without a backoffice:

- **Inside your backoffice** (sidebar, header, your theme): set `layout` to your layout view and
  `stacks` to its stack names. The layout needs `@yield('title')`, `@yield('content')`, the
  three stacks, and Font Awesome. Nothing else: no jQuery or Bootstrap.
- **As its own page**: set `layout` to `'standalone'`. The package renders a complete HTML
  page (`system-status::layouts.standalone`) that loads Font Awesome itself
  (`standalone.css` / `standalone.js`), follows the locale's direction (RTL for Arabic
  and Kurdish) and needs nothing from the application. Its top bar has a back button (to the
  previous page, or `standalone.back_url`) and the `standalone.brand` name.

When the configured `layout` view does not exist, the standalone page is used, so a fresh
install never fails on a missing `layouts.app`. Access rules are the same in both modes.

**Full control**: publish the views and edit them.

```bash
php artisan vendor:publish --tag=system-status-views   # resources/views/vendor/system-status/
```

A published view no longer receives the package's changes to the page; prefer `extra_css`
when it is enough.

## Translations

The package ships English (`en`), Arabic (`ar`) and Sorani Kurdish (`ku`) for every text on the
page, plus labels for common components (`database`, `redis`, `queue`, `scheduler`, `storage`,
`search`, `api_*`, `bo_*`, `firebase`, `telegram`, …).

Add or override any key per locale in `lang/vendor/system-status/<locale>/status.php`:

```php
return [
    'status_component_payments' => 'بوابة الدفع',
    'status_group_integrations' => 'التكاملات',
    'system_status_description' => 'Live health of every part of the shop.',
];
```

- Component label: `status_component_<key>`; group label: `status_group_<group>`.
- A component without a label is shown as its key in words (`api_orders` → *Api Orders*).
  `status:doctor` lists the missing labels per locale.
- `system_status_description` can use `:app` (the application name).
- To copy all package texts for editing: `php artisan vendor:publish --tag=system-status-lang`.
- The page direction follows the layout (`dir="rtl"` for Arabic and Kurdish).
- Alerts and webhooks are always in English.

## Pulse card

With [Laravel Pulse](https://pulse.laravel.com) installed, the package registers a card with
the overall status, the last check, open incidents and the components that have a problem. Add
it to `resources/views/vendor/pulse/dashboard.blade.php`:

```blade
<livewire:system-status.pulse cols="4" />
```

The card refreshes every 30 seconds and links to the status page. It is shown to everyone who
can open the Pulse dashboard (Pulse's `viewPulse` gate), not only to users with access to the
status page.

## Claude Code skill

The package ships a [Claude Code](https://claude.com/claude-code) skill that teaches the
assistant how the status page works in your application: where components live, how to add a
probe or a signal, maintenance windows, incidents, alerts, and the rules (read-only probes, no
secrets in messages, never edit `vendor/`).

```bash
php artisan vendor:publish --tag=system-status-skill   # .claude/skills/system-status/SKILL.md
```

Fill in its last section, *This application*, with your components and probes, and commit it.
Publish it again with `--force` after an upgrade to get the new guidance.
