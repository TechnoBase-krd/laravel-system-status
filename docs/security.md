# Suspicious activity and security

[← Back to README](../README.md) · [All docs](README.md)

---

## Suspicious activity

Off by default. `SYSTEM_STATUS_SECURITY_ENABLED=true` watches for signs that someone is
attacking or misusing the application. Each finding is stored, listed in a live *Suspicious
activity (24 h)* panel on the page (shown with a pulsing *Live* badge whenever monitoring is on;
click a finding for its rule, request, other findings from the same IP and what to do) and in `php artisan status:security`, sent to the alert channels
(`🛡️ Suspicious activity: 34 denied requests (401, 403, 419) from 203.0.113.9 in 5 min`), posted
to webhooks as `security.suspicious_activity`, and fires
`Technobase\SystemStatus\Events\SuspiciousActivityDetected`. The same finding (rule + IP or
account) alerts once per `cooldown_minutes` (30); while it goes on, its hit count keeps growing.

| What | Rule | Default |
| --- | --- | --- |
| Denied requests (401, 403, 419) from one IP | `requests.auth_errors` | more than 30 in 5 min |
| Rate-limited requests (429) from one IP | `requests.rate_limited` | more than 50 in 5 min |
| Scanner paths from one IP: secrets (`.env`, `.git/*`, `.aws/*`, `id_rsa`), Laravel internals (`storage/logs/*`, `vendor/*`, `_ignition/*`), backups (`*.sql`, `*.bak`, `backup.zip`), WordPress, phpMyAdmin / Adminer, web shells and known exploit paths (`eval-stdin.php`, `actuator`, `owa/*`, cloud metadata) | `requests.scanner` | more than 3 in 10 min |
| Attack payloads in the URL from one IP: SQL injection (`union select`, `sleep(`), XSS (`<script`, `onerror=`), path traversal (`../`, `/etc/passwd`), file inclusion (`php://`), command and template injection, Log4Shell (`${jndi:`), SSRF to `169.254.169.254` | `requests.injection` | more than 2 in 10 min |
| Known attack tools by User-Agent (sqlmap, Nikto, Nuclei, WPScan, gobuster, ffuf, Acunetix, …) | `requests.scanner_tools` | more than 1 in 10 min |
| Not-found requests (404) from one IP | `requests.not_found` | more than 100 in 5 min |
| Failed logins from one IP / for one account | `logins.failed` / `logins.failed_per_account` | more than 10 / 5 in 10 min |
| A user signs in from a country they never used | `logins.new_country` | on (needs a country header) |
| A user signs in from an IP they never used | `logins.new_ip` | off |
| Signal calls (SMS, OTP) above a budget, in total or from one IP | `signals.<component>` | none set |
| Risky actions reported from code | `actions.<name>` | `roles.changed` always, `export` more than 10 / h, `bulk_delete` more than 3 / h |
| Any reported action in quiet hours | `quiet_hours` | off |

**Turning one check off.** `SYSTEM_STATUS_SECURITY_ENABLED=false` turns everything off. To keep
monitoring but drop one check (in one environment, for example), each check has its own switch,
on by default:

| Check | `.env` | Config |
| --- | --- | --- |
| Denied requests | `SYSTEM_STATUS_SECURITY_AUTH_ERRORS=false` | `requests.auth_errors.enabled` |
| Rate-limited requests | `SYSTEM_STATUS_SECURITY_RATE_LIMITED=false` | `requests.rate_limited.enabled` |
| Scanner paths | `SYSTEM_STATUS_SECURITY_SCANNER=false` | `requests.scanner.enabled` |
| Attack payloads | `SYSTEM_STATUS_SECURITY_INJECTION=false` | `requests.injection.enabled` |
| Attack tools | `SYSTEM_STATUS_SECURITY_SCANNER_TOOLS=false` | `requests.scanner_tools.enabled` |
| Many 404s | `SYSTEM_STATUS_SECURITY_NOT_FOUND=false` | `requests.not_found.enabled` |
| Honeypots and canaries | `SYSTEM_STATUS_SECURITY_HONEYPOT=false` | `honeypot.enabled` |
| Reading many records | `SYSTEM_STATUS_SECURITY_RECORDS=false` | `records.enabled` |
| All login checks | `SYSTEM_STATUS_SECURITY_LOGINS=false` | `logins.enabled` |

Your own request rules, `signals.<component>` and `actions.<name>` take `'enabled' => false` too.
An application that published the config before this option has no `enabled` keys: add the
lines from the package's config to use the `.env` switches.

```php
'security' => [
    'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_ENABLED', false),
    'ignore_ips' => ['10.0.0.0/8'],                       // office, monitoring, own servers
    'requests' => [
        // Your own rule, counted per IP. Every condition it sets must match (case-insensitive):
        // statuses, paths (Str::is), contains (text in the decoded URL), user_agents.
        'admin_probing' => ['statuses' => [403], 'paths' => ['admin/*'], 'max' => 10, 'minutes' => 5],
    ],
    'logins' => [
        'country_header' => 'CF-IPCountry',             // Cloudflare; null = no country check
        'new_ip' => true,
        'watch_roles' => ['Super Admin'],                // or 'watch_gate' => 'viewSystemStatus'
    ],
    'signals' => [
        'otp' => ['max' => 500, 'max_per_ip' => 10, 'minutes' => 60],
    ],
    'actions' => [
        'roles.changed' => ['alert' => true],
        'export' => ['max' => 10, 'minutes' => 60],
    ],
    'quiet_hours' => ['from' => '23:00', 'to' => '06:00'],
],
```

**Requests** are checked by the same middleware that counts traffic, after the response is
sent, including URLs without a route (that is where scanners go). **Logins** come from Laravel's
`Illuminate\Auth\Events\Failed` and `Login`, which Breeze, Fortify, Jetstream, Filament and
`Auth::attempt()` fire. Only the account name is read from the credentials, never the password,
and it is masked (`sa***@example.com`). **Signals** are the existing `StatusSignal` calls; the
per-IP limit applies while handling a request (not in queue workers). **Actions** are reported
from code:

```php
use Technobase\SystemStatus\Support\StatusSecurity;

StatusSecurity::action('roles.changed', auth()->user(), ['target' => 'User #12']);
StatusSecurity::action('export');   // the signed-in user
```

Details must be short scalars without secrets or personal data. React in code, for example to
block an IP:

```php
Event::listen(SuspiciousActivityDetected::class, function (SuspiciousActivityDetected $e) {
    if ($e->event->type === 'scanner') {
        Firewall::block($e->event->ip);   // your own code
    }
});
```

Request IPs come from `$request->ip()`. Behind a load balancer or Cloudflare, set Laravel's
trusted proxies, or every request seems to come from the proxy. The counters use the default
cache store (`security.store`); with several servers it must be shared (Redis, database).

**Dangerous settings.** `status:doctor` reports `APP_DEBUG` on in production, an empty
`APP_KEY`, pending migrations and, in production, a `.env` file readable at `APP_URL/.env`
(`--no-http` skips that request). The same checks can run as a probe on the page:

```php
'security_config' => [
    'probes' => [
        ['class' => SecurityConfigProbe::class, 'with' => ['checkEnvFile' => true], 'every' => 15],
    ],
],
```

`SecurityConfigProbe` is a major outage for `APP_DEBUG` in production, an empty `APP_KEY` or a
readable `.env`, and degraded for pending migrations. Its `.env` request is signed with the
`APP_KEY`, so the monitor does not report your own server as a scanner.

In production, `status:doctor` also runs `ExposedFilesProbe` and warns about `APP_URL` without https, a missing HSTS header,
`SESSION_SECURE_COOKIE` off, `session.http_only` off, CORS open to `*` (a failure together with
`supports_credentials`), Debugbar on and Telescope on.

### Critical findings

Some findings mean the attack may have **worked**, or that nobody but an attacker could have
done it. They are marked *Critical* (red on the page, `🚨 CRITICAL — possible breach` in the
alert) and are reported on the first hit:

| Finding | When |
| --- | --- |
| Scanned file served | a `scanner` path answered 2xx by Laravel with something other than an HTML page (`requests.scanner.critical`), e.g. a debug route or a file download route. HTML is skipped because a catch-all route answers every path with the app's own page. Real files in `public/` are served by the web server without Laravel, so this never sees them: `ExposedFilesProbe` (below) checks those. |
| Attack payload broke the app | an `injection` payload made the app answer 500 (`requests.injection.critical`): the input may reach a query or a command unchecked |
| Honeypot path, honeypot field, canary secret | see below |
| Impossible travel | a user signs in from another country within `logins.impossible_travel_minutes` (120) of a login from a different one |
| Session used from another country | a signed-in session is suddenly used from another country (`logins.session_country_change`): a stolen session cookie |

Any request rule can have its own `'critical' => ['statuses' => [...], 'not_html' => true]`.

### Auto-block

Off by default. `SYSTEM_STATUS_SECURITY_BLOCK=true` answers 403 to an IP for `block.minutes`
(60) after a finding of one of `block.types` (scanner, injection, attack tools, honeypots,
canaries) or any critical finding. The check is a middleware that runs before sessions, auth and
the database, so a blocked request costs one cache read.

```php
'block' => [
    'enabled' => (bool) env('SYSTEM_STATUS_SECURITY_BLOCK', false),
    'minutes' => 60,
    'types' => ['scanner', 'injection', 'scanner_tools', 'honeypot', 'honeypot_field', 'canary'],
    'critical' => true,
    'cloudflare' => [
        'token' => env('SYSTEM_STATUS_CLOUDFLARE_TOKEN'),     // Zone > Firewall Services > Edit
        'zone_id' => env('SYSTEM_STATUS_CLOUDFLARE_ZONE_ID'),
    ],
],
```

With a Cloudflare token and zone id, the IP is also blocked at Cloudflare (an IP access rule),
so its requests never reach the server; the rule is removed when the block ends
(`status:security --expire`, scheduled every 5 minutes). Unblock with the **Unblock** button in a
finding's dialog (managers) or `php artisan status:security --unblock=203.0.113.9`; list blocks
with `--blocked`.

To keep a misconfiguration from locking everyone out, these are never blocked: `ignore_ips`,
loopback and private addresses (a proxy or load balancer, when trusted proxies are not set), and
an address that Cloudflare's `CF-Connecting-IP` header shows is not the client.

### Honeypots

Traps nobody but an attacker touches; one hit is a critical finding (and a block).

```php
'honeypot' => [
    'paths' => ['/old-admin*'],              // e.g. listed as Disallow in robots.txt, linked nowhere
    'field' => 'website_url',                 // <x-system-status::honeypot /> inside a form
    'canaries' => ['sk_live_51Hx…fake'],      // a fake key in an HTML comment or a decoy file
],
```

`<x-system-status::honeypot />` renders a field people never see; a bot that fills every field
fills it too. A **canary** is a fake secret you leave where only someone digging through your
pages or files would find it: any request that carries it in its URL, headers or body is someone
using what they found. Only its first characters are stored.

### Many records, many IPs

- **Reading many records** (`records`): one signed-in user opening more than 200 different URLs
  of the same route (`orders/1`, `orders/2`, …) within 10 minutes. Catches scraping and IDOR
  (changing the id in a URL to read other people's data). Skip routes with `ignore_routes`.
- **Attacks from many IPs** (`max_total` on a request rule or `logins.failed`): the same rule
  summed over all IPs, for botnets that stay under the per-IP limit. The finding says how many IPs
  took part.

### New API tokens and role changes

With `watch_changes`, a new Laravel Sanctum token and a role or permission given or taken
(spatie/laravel-permission with `permission.events_enabled`) are reported as risky actions, with
who did it.

`tokens` is off (`false`) by default, because many apps give customers a token at every login.
List the staff models whose new tokens matter, or set `true` for the users `logins.watch_gate` /
`watch_roles` watch (everybody when neither is set):

```php
'watch_changes' => ['tokens' => [App\Models\Admin::class], 'roles' => true],
```

### Exposed files, web shells, changed code and vulnerable packages

Four probes for a `security` group:

```php
use Technobase\SystemStatus\Probes\{ComposerAuditProbe, ExposedFilesProbe, SecurityFilesProbe, WebShellProbe};

'security' => [
    'exposed_files' => ['probes' => [['class' => ExposedFilesProbe::class, 'every' => 15]]],
    'web_shells' => ['probes' => [['class' => WebShellProbe::class, 'every' => 30]]],
    'code_files' => ['probes' => [['class' => SecurityFilesProbe::class, 'every' => 15]]],
    'packages' => ['probes' => [['class' => ComposerAuditProbe::class, 'every' => 720]]],
],
```

| Probe | Options (`with`) | Checks |
| --- | --- | --- |
| `ExposedFilesProbe` | `paths` (extra path => regex its content matches), `skip`, `baseUrl`, `timeoutSeconds` (5) | downloads `.env` and its backups, `.git/config`, `.git/HEAD`, `laravel.log`, SQL dumps, `phpinfo.php`, `adminer.php` and `auth.json` from the site, the way an attacker would: *major outage* when one answers with that kind of content (a catch-all page is not a finding). Never prints what it got. Its requests are signed with the `APP_KEY`, so the monitor skips them. |
| `WebShellProbe` | `paths` (`public`, `storage/app/public`), `allow` (`public/index.php`), `extensions`, `maxFiles` (20 000) | PHP and other executable files where only assets and uploads belong, and uploads whose first bytes are PHP (an image with a shell inside): *major outage* |
| `SecurityFilesProbe` | – | code files (`security.files`) added or changed since the last `status:deploy`: *major outage*; removed: *degraded*. The baseline is saved by every deploy (`files.baseline_on_deploy`) or `php artisan status:security --baseline`. |
| `ComposerAuditProbe` | `binary` (`composer`), `timeoutSeconds` (60) | `composer audit --locked`: a high or critical advisory is a *major outage*, others *degraded*; skipped without composer |

Run `status:deploy` (or `--baseline`) after the files of a deploy are in place, on each server
that serves the app.
