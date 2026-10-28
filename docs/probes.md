# Probes, signals, latency and dependencies

[← Back to README](../README.md) · [All docs](README.md)

---

## Probes

### Built-in probes

All probes live in `Technobase\SystemStatus\Probes`. The `with` keys are the constructor
argument names.

| Probe | Options (`with`) | Checks |
| --- | --- | --- |
| `SpatieCheckProbe` | `check` (a spatie/laravel-health check class) | database, Redis, cache, Horizon, disk space, or any custom Spatie check |
| `HttpEndpointProbe` | `path`, `json` (default `true`) | a GET request to a path of the application; on failure it says whether the app or a proxy (e.g. Cloudflare) answered |
| `QueueHeartbeatProbe` | – | the heartbeat jobs on every queue in `queues` were processed |
| `CdnStorageProbe` | `disk` (default `s3`) | a HEAD request to the disk's public URL |
| `TelegramProbe` | – | the bot token works (`getMe`) |
| `SslCertificateProbe` | `host` (default: `APP_URL`'s host), `port` (`443`), `warnDays` (`14`) | days until the TLS certificate expires: *degraded* within `warnDays`, *major outage* once expired (only the expiry date is checked) |
| `DiskSpaceProbe` | `path` (default: `storage_path()`), `degradedBelowPercent` (`15`), `outageBelowPercent` (`5`) | free space on the disk that holds `path` |
| `FailedJobsProbe` | `windowMinutes` (`60`), `degradedAt` (`5`), `outageAt` (`50`), `table`, `connection` (default: `queue.failed`) | jobs in `failed_jobs` in the last `windowMinutes`; *Not configured* without the table |
| `QueueSizeProbe` | `queue` (`default`), `connection`, `degradedAt` (`100`), `outageAt` (`1000`) | jobs waiting in a queue |
| `QueueWaitProbe` | `queue` (`default`), `connection`, `degradedSeconds` (`60`), `outageSeconds` (`300`) | how long the oldest waiting job has waited; *Not configured* on drivers that cannot tell |
| `DnsProbe` | `host`, `type` (`A`; also `AAAA`, `CNAME`, `MX`, `TXT`, `NS`) | the host has a record of that type |
| `TcpPortProbe` | `host`, `port` | a TCP connection opens (SMTP, a database or search engine on its own port); slow = *degraded* |
| `ExposedFilesProbe` | `paths`, `skip`, `baseUrl`, `timeoutSeconds` (5) | secret files anyone can download (`.env`, `.git`, logs, SQL dumps, phpinfo): *major outage*; see [Suspicious activity](security.md#suspicious-activity) |
| `SecurityConfigProbe` | `checkDebug` (`true`), `checkMigrations` (`true`), `checkEnvFile` (`false`), `baseUrl` | `APP_DEBUG` on in production, an empty `APP_KEY` or a readable `.env` (*major outage*), pending migrations (*degraded*); see [Suspicious activity](security.md#suspicious-activity) |
| `CallbackProbe` | `callback`, `label` (`Callback`) | a quick check without a probe class (see below) |

```php
use Technobase\SystemStatus\Probes\CallbackProbe;
use Technobase\SystemStatus\Probes\DiskSpaceProbe;
use Technobase\SystemStatus\Probes\FailedJobsProbe;
use Technobase\SystemStatus\Probes\QueueWaitProbe;
use Technobase\SystemStatus\Probes\SslCertificateProbe;
use Technobase\SystemStatus\Probes\TcpPortProbe;

'platform' => [
    'tls'    => ['probes' => [['class' => SslCertificateProbe::class, 'with' => ['warnDays' => 21], 'every' => 60]]],
    'disk'   => ['probes' => [['class' => DiskSpaceProbe::class, 'with' => ['degradedBelowPercent' => 20], 'every' => 5]]],
    'queue'  => ['probes' => [
        ['class' => FailedJobsProbe::class, 'every' => 5],
        ['class' => QueueWaitProbe::class, 'with' => ['queue' => 'emails', 'degradedSeconds' => 120], 'every' => 1],
    ]],
    'search' => ['probes' => [['class' => TcpPortProbe::class, 'with' => ['host' => 'meilisearch', 'port' => 7700], 'every' => 2]]],
    'mailer' => ['probes' => [['class' => CallbackProbe::class, 'with' => ['callback' => App\Probes\CheckMailer::class, 'label' => 'Mailer'], 'every' => 5]]],
],
```

`CallbackProbe`'s `callback` is an invokable class name, a `[Class::class, 'method']` pair
(resolved from the container) or a closure. It returns a `ProbeResult`, `true` (operational),
`false` (major outage), `null` (not configured) or a string (degraded, with that message).
Closures break `php artisan config:cache`; prefer a class.

### Writing a probe

```php
namespace App\Probes;

use App\Services\PaymentGateway;
use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\ProbeResult;
use Throwable;

class PaymentGatewayProbe implements Probe
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function name(): string
    {
        return 'Payment gateway verify-key';
    }

    public function run(): ProbeResult
    {
        if (blank(config('services.payments.key'))) {
            return ProbeResult::skipped('Payment gateway is not configured');
        }

        try {
            $this->gateway->verifyKey();          // free and read-only
        } catch (Throwable $e) {
            return ProbeResult::outage('Gateway refused the key ('.class_basename($e).')');
        }

        return ProbeResult::ok();
    }
}
```

Results: `ProbeResult::ok()`, `degraded($message)`, `outage($message)`, `skipped($message)`.
A probe decides its own status; return `degraded()` yourself when the service is slow.

#### A probe that makes one HTTP request

Implement `ConcurrentProbe` instead (and use the `SendsHttpRequest` trait for `run()`).
`status:check` then sends its request together with those of the other HTTP probes that are due
(`Http::pool`), so ten slow endpoints take as long as the slowest one, not their sum. The
built-in `HttpEndpointProbe`, `CdnStorageProbe` and `TelegramProbe` work this way.

```php
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Technobase\SystemStatus\Probes\{ConcurrentProbe, ProbeResult, SendsHttpRequest};
use Throwable;

class SearchApiProbe implements ConcurrentProbe
{
    use SendsHttpRequest;

    public function name(): string
    {
        return 'Search API health';
    }

    public function precheck(): ?ProbeResult       // a result without a request, or null
    {
        return blank(config('services.search.url')) ? ProbeResult::skipped('Search is not configured') : null;
    }

    public function send(PendingRequest $request): mixed
    {
        return $request->timeout(5)->get(config('services.search.url').'/health');
    }

    public function result(Response|Throwable $response): ProbeResult
    {
        return match (true) {
            $response instanceof Throwable => ProbeResult::outage('Could not connect to search'),
            $response->failed() => ProbeResult::outage('Search returned HTTP '.$response->status()),
            default => ProbeResult::ok(),
        };
    }
}
```

Rules for probes:

- **Read-only and free.** Never create orders, payments, shipments, messages or OTPs, never
  call a paid API, never POST to the application. Use a signal for those.
- **No secrets in messages.** No URLs with tokens, no raw exception messages from HTTP clients
  (they often contain the URL); use the exception class or a short text.
- Return `skipped()` while the service is not configured, so it shows *Not configured*
  instead of an outage.

## Signals

For calls you cannot test for free, report each real call:

```php
use Technobase\SystemStatus\Support\StatusSignal;

try {
    $this->smsGateway->send($phone, $code);
    StatusSignal::success('sms_otp');
} catch (Throwable $e) {
    StatusSignal::failure('sms_otp', class_basename($e));

    throw $e;   // behaviour unchanged
}
```

- The component needs `'signal' => true`.
- Signals never throw and never change the caller's behaviour.
- The status follows the error rate of the last 15 minutes, with at least 3 calls.
- The last failure reason is shown in the component's detail popup.

## Latency

Every request also lands in a response-time histogram, so the package knows the **p50** and
**p95** response time per component, route and hour. The component detail popup shows a p95
number, a response-time chart and the p95 of each route.

Slowness alone can change a status. Both thresholds are off by default:

```php
'thresholds' => [
    // …
    'latency_degraded_p95_ms' => 1500,   // degraded when the p95 over the window is above this
    'latency_outage_p95_ms' => 5000,     // major outage above this
],
```

The p95 is taken over `thresholds.window_minutes` and needs `min_requests` requests. Set the
limits per component when one is slower by nature:

```php
'api_search' => [
    'uris' => ['api/v1/search*'],
    'thresholds' => ['latency_degraded_p95_ms' => 2500, 'min_requests' => 50],
],
```

## Dependencies

When the database is down, every API that uses it fails too. `depends_on` turns that into one
problem instead of ten:

```php
'api_orders' => ['uris' => ['api/v1/orders*'], 'depends_on' => ['database', 'redis']],
```

When a component fails and one of its dependencies fails in the same run:

- it shows **Affected by Database** (with its own reason after it);
- its incident records the root cause;
- its alert is folded into the dependency's: "🔴 Database: major outage. … Also affected:
  Orders API.", and only the dependency's recovery is sent;
- if it is still failing after the dependency recovered, it alerts on its own.

Chains work (`api_orders` → `redis` → `database`): the root cause is the first failing
component up the chain. `status:doctor` reports `depends_on` keys that are not components.
