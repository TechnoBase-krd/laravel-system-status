<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Http\Middleware\BlockSuspiciousIps;
use Technobase\SystemStatus\Listeners\RecordSecurityChanges;
use Technobase\SystemStatus\Models\StatusSecurityEvent;
use Technobase\SystemStatus\Probes\ComposerAuditProbe;
use Technobase\SystemStatus\Probes\ExposedFilesProbe;
use Technobase\SystemStatus\Probes\SecurityFilesProbe;
use Technobase\SystemStatus\Probes\WebShellProbe;
use Technobase\SystemStatus\Services\DeployService;
use Technobase\SystemStatus\Services\SecurityBlocker;
use Technobase\SystemStatus\Services\SecurityMonitor;
use Technobase\SystemStatus\Support\ProbeSignature;
use Technobase\SystemStatus\Tests\Fixtures\User;

const ATTACKER = '203.0.113.9';

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    Http::fake(['*hooks.*' => Http::response(), 'api.cloudflare.com/*' => Http::response(['result' => ['id' => 'cf-rule-1']])]);
    config()->set('cache.default', 'array');
    config()->set('system-status.security.enabled', true);
    config()->set('system-status.alerts.channels', ['slack']);
    config()->set('system-status.alerts.slack_webhook', 'https://hooks.slack.test/abc');

    Route::get('/plain/{file}', fn () => response('DB_PASSWORD=secret', 200, ['Content-Type' => 'text/plain']))->where('file', '.*');
    Route::get('/page/{file}', fn () => response('<html>app</html>', 200, ['Content-Type' => 'text/html']))->where('file', '.*');
    Route::get('/broken', fn () => throw new RuntimeException('boom'));
    Route::post('/contact', fn () => 'thanks');

    $this->tmp = sys_get_temp_dir().'/sst-'.uniqid();
    File::ensureDirectoryExists($this->tmp);
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->tmp);
});

function fromAttacker(): array
{
    return ['REMOTE_ADDR' => ATTACKER];
}

/* ---------- 1. auto-block ---------- */

it('blocks an IP with a serious finding, also at Cloudflare, and unblocks it', function () {
    config()->set('system-status.security.block', [
        'enabled' => true, 'minutes' => 60, 'types' => ['scanner'], 'critical' => true,
        'cloudflare' => ['token' => 'cf-token', 'zone_id' => 'zone-1'],
    ]);
    config()->set('system-status.security.requests.scanner.max', 1);
    $this->app->make(Kernel::class)->pushMiddleware(BlockSuspiciousIps::class);

    $this->withServerVariables(fromAttacker())->get('/wp-login.php')->assertNotFound();
    $this->withServerVariables(fromAttacker())->get('/xmlrpc.php')->assertNotFound();

    $event = StatusSecurityEvent::query()->sole();
    expect($event->details['blocked_until'])->toBe('2026-10-20 13:00');

    $this->withServerVariables(fromAttacker())->get('/plain/x')->assertForbidden()->assertSee('Access denied.');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get('/plain/x')->assertOk();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), 'zones/zone-1/firewall/access_rules/rules')
        && $request['configuration'] === ['target' => 'ip', 'value' => ATTACKER]);

    // Managers unblock from the page.
    $this->actingAs(User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']))
        ->postJson('/system-status/security/unblock', ['ip' => ATTACKER])
        ->assertOk()->assertJsonPath('data.unblocked', true);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rules/cf-rule-1'));
    $this->withServerVariables(fromAttacker())->get('/plain/x')->assertOk();
});

it('blocks the visitor behind a trusted proxy, not the proxy', function () {
    config()->set('system-status.security.block.enabled', true);
    config()->set('system-status.security.requests.scanner.max', 1);
    $kernel = $this->app->make(Kernel::class);
    $kernel->prependMiddleware(TrustProxies::class);
    $kernel->pushMiddleware(BlockSuspiciousIps::class);
    TrustProxies::at('*');

    // Each request starts fresh, as on PHP-FPM: only TrustProxies sets the trusted proxies.
    $send = function (string $path, string $client) {
        Request::setTrustedProxies([], 0);

        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => $client])->get($path);
    };

    $send('/wp-login.php', ATTACKER);
    $send('/xmlrpc.php', ATTACKER);

    $send('/plain/x', ATTACKER)->assertForbidden();
    $send('/plain/x', '198.51.100.1')->assertOk();

    TrustProxies::flushState();
    Request::setTrustedProxies([], 0);
});

it('ends blocks when their time is up', function () {
    config()->set('system-status.security.block.enabled', true);
    config()->set('system-status.security.requests.scanner.max', 1);

    $this->withServerVariables(fromAttacker())->get('/wp-login.php');
    $this->withServerVariables(fromAttacker())->get('/xmlrpc.php');
    expect(app(SecurityBlocker::class)->blockedUntil(ATTACKER))->not->toBeNull();

    $this->artisan('status:security --blocked')->expectsOutputToContain(ATTACKER)->assertSuccessful();

    Carbon::setTestNow(now()->addMinutes(61));
    $this->artisan('status:security --expire')->expectsOutputToContain('1 block(s) ended')->assertSuccessful();
    expect(DB::table('status_security_blocks')->count())->toBe(0);
});

it('never blocks private, loopback or proxy addresses', function () {
    config()->set('system-status.security.block.enabled', true);
    config()->set('system-status.security.requests.scanner.max', 1);

    // 127.0.0.1: a proxy when trusted proxies are not set.
    $this->get('/wp-login.php');
    $this->get('/xmlrpc.php');

    // A Cloudflare edge address: CF-Connecting-IP says the client is someone else.
    $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.1'])->withHeader('CF-Connecting-IP', ATTACKER)->get('/wp-login.php');
    $this->withServerVariables(['REMOTE_ADDR' => '172.70.1.1'])->withHeader('CF-Connecting-IP', ATTACKER)->get('/xmlrpc.php');

    expect(StatusSecurityEvent::query()->count())->toBe(2)
        ->and(DB::table('status_security_blocks')->count())->toBe(0);
});

/* ---------- 2. web shells and code files ---------- */

it('finds web shells and PHP hidden in uploads', function () {
    File::put($this->tmp.'/index.php', '<?php // front controller');
    File::put($this->tmp.'/logo.png', 'PNG');
    expect((new WebShellProbe([$this->tmp], [ltrim(str_replace(base_path(), '', $this->tmp), '/').'/index.php', $this->tmp.'/index.php']))->run()->status)
        ->toBe(ComponentStatus::Operational);

    File::put($this->tmp.'/shell.phtml', '<?php system($_GET["c"]);');
    File::put($this->tmp.'/avatar.jpg', "GIF89a<?php eval(\$_POST['x']); ?>");

    $result = (new WebShellProbe([$this->tmp], [$this->tmp.'/index.php']))->run();
    expect($result->status)->toBe(ComponentStatus::MajorOutage)
        ->and($result->message)->toContain('shell.phtml')
        ->and($result->message)->toContain('avatar.jpg');
});

it('reports code files changed since the last deploy', function () {
    config()->set('system-status.security.files.paths', [$this->tmp]);
    config()->set('system-status.security.files.baseline', $this->tmp.'-baseline.json');
    File::put($this->tmp.'/Controller.php', '<?php class A {}');
    File::put($this->tmp.'/readme.txt', 'not code');

    expect((new SecurityFilesProbe)->run()->isSkipped())->toBeTrue();

    // A deploy saves the baseline.
    app(DeployService::class)->record('1.0.0');
    expect((new SecurityFilesProbe)->run()->status)->toBe(ComponentStatus::Operational);

    File::put($this->tmp.'/readme.txt', 'changed, but not code');
    expect((new SecurityFilesProbe)->run()->status)->toBe(ComponentStatus::Operational);

    File::put($this->tmp.'/Controller.php', '<?php class A { /* backdoor */ }');
    File::put($this->tmp.'/.htaccess', 'AddType application/x-httpd-php .jpg');
    $result = (new SecurityFilesProbe)->run();
    expect($result->status)->toBe(ComponentStatus::MajorOutage)
        ->and($result->message)->toContain('1 new')
        ->and($result->message)->toContain('1 changed');

    $this->artisan('status:security --baseline')->expectsOutputToContain('Baseline saved: 2 code files')->assertSuccessful();
    File::delete($this->tmp.'/Controller.php');
    expect((new SecurityFilesProbe)->run()->status)->toBe(ComponentStatus::Degraded);

    File::delete($this->tmp.'-baseline.json');
});

/* ---------- 3. critical when the attack may have worked ---------- */

it('raises a critical finding when a scanned file is served', function () {
    $this->withServerVariables(fromAttacker())->get('/plain/.env')->assertOk();

    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('scanner_critical')
        ->and($event->isCritical())->toBeTrue()
        ->and($event->describe('en'))->toBe('A scanned path was served: /plain/.env answered 200 to '.ATTACKER);

    $texts = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['text'] ?? null)->filter()->values();
    expect($texts[0])->toStartWith('[Laravel status] 🚨 CRITICAL — possible breach');
});

it('does not call an HTML page a served secret (catch-all routes)', function () {
    $this->withServerVariables(fromAttacker())->get('/page/.env')->assertOk();

    expect(StatusSecurityEvent::query()->count())->toBe(0);
});

it('raises a critical finding when a payload makes the app fail', function () {
    $this->withServerVariables(fromAttacker())->get('/broken?id=1%27%20UNION%20SELECT%201--')->assertServerError();

    expect(StatusSecurityEvent::query()->where('type', 'injection_critical')->sole()->subject)->toBe('union select');
});

/* ---------- 4. honeypots ---------- */

it('treats one honeypot hit as critical', function () {
    config()->set('system-status.security.honeypot.paths', ['/old-admin*']);
    config()->set('system-status.security.honeypot.canaries', ['sk_live_CANARY_8f2a']);

    $this->withServerVariables(fromAttacker())->get('/old-admin/login');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->post('/contact', ['name' => 'Bot', 'website_url' => 'http://spam.test']);
    // People leave the hidden field empty.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])->post('/contact', ['name' => 'Sara', 'website_url' => '']);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->withHeader('Authorization', 'Bearer sk_live_CANARY_8f2a')->get('/plain/x');

    $events = StatusSecurityEvent::query()->orderBy('id')->get();
    expect($events->pluck('type')->all())->toBe(['honeypot', 'honeypot_field', 'canary'])
        ->and($events->every->isCritical())->toBeTrue()
        ->and($events[2]->subject)->toBe('sk_l***')
        ->and(json_encode(DB::table('status_security_events')->get()))->not->toContain('CANARY_8f2a');
});

it('renders the hidden honeypot field', function () {
    $html = view('system-status::components.honeypot')->render();

    expect($html)->toContain('name="website_url"')
        ->and($html)->toContain('tabindex="-1"')
        ->and($html)->toContain('aria-hidden="true"');
});

/* ---------- 5. composer audit ---------- */

it('reports vulnerable composer packages', function () {
    $lock = base_path('composer.lock');
    $hadLock = is_file($lock);
    if (! $hadLock) {
        File::put($lock, '{}');
    }

    Process::fake([
        '*' => Process::result(json_encode(['advisories' => [
            'guzzlehttp/guzzle' => [['packageName' => 'guzzlehttp/guzzle', 'severity' => 'high', 'cve' => 'CVE-2022-1']],
            'league/commonmark' => [['packageName' => 'league/commonmark', 'severity' => 'medium']],
        ]]), exitCode: 1),
    ]);
    $result = (new ComposerAuditProbe)->run();
    expect($result->status)->toBe(ComponentStatus::MajorOutage)
        ->and($result->message)->toContain('2 advisories in guzzlehttp/guzzle, league/commonmark (1 high or critical)');

    Process::fake(['*' => Process::result('{"advisories": []}')]);
    expect((new ComposerAuditProbe)->run()->status)->toBe(ComponentStatus::Operational);

    if (! $hadLock) {
        File::delete($lock);
    }
});

/* ---------- 6. reading many records (IDOR, scraping) ---------- */

it('reports a user who opens many different records', function () {
    config()->set('system-status.security.records.max', 3);
    Route::middleware('web')->get('/orders/{order}', fn ($order) => 'order '.$order);
    $user = User::query()->create(['name' => 'Curious']);

    foreach ([1, 1, 2, 2, 3] as $id) {
        $this->actingAs($user)->get('/orders/'.$id)->assertOk();
    }
    expect(StatusSecurityEvent::query()->count())->toBe(0);

    $this->actingAs($user)->get('/orders/4');
    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('record_access')
        ->and($event->describe('en'))->toBe("Curious #{$user->id} opened 4 different /orders/{order} records in 10 min (limit 3)");
});

/* ---------- 7. attacks spread over many IPs ---------- */

it('reports a rule crossed by many IPs together', function () {
    config()->set('system-status.security.requests.auth_errors', ['statuses' => [403], 'max' => 1000, 'max_total' => 3, 'minutes' => 5]);
    Route::get('/denied', fn () => abort(403));

    foreach (['198.51.100.10', '198.51.100.11', '198.51.100.12', '198.51.100.13'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('/denied');
    }

    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('distributed')
        ->and($event->describe('en'))->toBe('4 auth_errors hits from 4 IPs in 5 min (limit 3)');
});

it('sums failed logins over all IPs', function () {
    config()->set('system-status.security.logins.failed', ['max' => 100, 'max_total' => 2, 'minutes' => 10]);
    $monitor = app(SecurityMonitor::class);

    foreach (['198.51.100.20', '198.51.100.21', '198.51.100.22'] as $ip) {
        $monitor->failedLogin(null, $ip);
    }

    expect(StatusSecurityEvent::query()->where('type', 'distributed')->sole()->rule)->toBe('failed_logins');
});

/* ---------- 8. stolen sessions and impossible travel ---------- */

it('reports a session used from another country', function () {
    $user = User::query()->create(['name' => 'Sara']);
    $session = new Store('test', new ArraySessionHandler(10), str_repeat('a', 40));
    $request = function (string $country, string $ip) use ($user, $session) {
        $request = Request::create('/account', 'GET', server: ['REMOTE_ADDR' => $ip, 'HTTP_CF_IPCOUNTRY' => $country]);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        return $request;
    };

    $monitor = app(SecurityMonitor::class);
    $monitor->request($request('IQ', '198.51.100.30'), 200);
    $monitor->request($request('IQ', '198.51.100.31'), 200);
    expect(StatusSecurityEvent::query()->count())->toBe(0);

    $monitor->request($request('NL', '185.1.1.1'), 200);
    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('session_country_change')
        ->and($event->isCritical())->toBeTrue()
        ->and($event->describe('en'))->toBe("The session of Sara #{$user->id} was used from NL (185.1.1.1) after IQ");
});

it('reports impossible travel between two logins', function () {
    $user = User::query()->create(['name' => 'Ali']);
    $monitor = app(SecurityMonitor::class);

    $monitor->login($user, '198.51.100.40', 'IQ');
    Carbon::setTestNow(now()->addMinutes(20));
    $monitor->login($user, '185.2.2.2', 'BR');

    $event = StatusSecurityEvent::query()->where('type', 'impossible_travel')->sole();
    expect($event->isCritical())->toBeTrue()
        ->and($event->describe('en'))->toBe("Ali #{$user->id} signed in from BR (185.2.2.2) 20 min after a login from IQ");

    // Hours later is a normal trip.
    Carbon::setTestNow(now()->addHours(5));
    $monitor->login($user, '198.51.100.41', 'IQ');
    expect(StatusSecurityEvent::query()->where('type', 'impossible_travel')->count())->toBe(1);
});

/* ---------- 9. new API tokens, role changes and doctor checks ---------- */

it('reports new API tokens and role changes', function () {
    $admin = User::query()->create(['name' => 'Rawa']);
    $this->actingAs($admin);
    $listener = app(RecordSecurityChanges::class);

    $token = new class extends Model
    {
        protected $guarded = [];
    };
    $token->forceFill(['name' => 'deploy-bot']);
    $listener->tokenCreated($token);

    $listener->roleChanged((object) ['model' => $admin, 'rolesOrIds' => ['Super Admin']]);

    $events = StatusSecurityEvent::query()->orderBy('id')->get();
    expect($events->pluck('rule')->all())->toBe(['token.created', 'roles.attached'])
        ->and($events[0]->icon())->toBe('fa-key')
        ->and($events[1]->details)->toMatchArray(['items' => 'Super Admin']);
});

it('reports new tokens only for the owners it watches', function () {
    $listener = app(RecordSecurityChanges::class);
    $admin = User::query()->create(['name' => 'Rawa', 'role' => 'Super Admin']);
    $customer = User::query()->create(['name' => 'Shopper', 'role' => 'Customer']);
    $tokenFor = function (Model $owner) {
        $token = new class extends Model
        {
            protected $guarded = [];
        };

        return $token->forceFill(['name' => 'mobile'])->setRelation('tokenable', $owner);
    };

    // true: the login watch rules decide.
    config()->set('system-status.security.logins.watch_roles', ['Super Admin']);
    $listener->tokenCreated($tokenFor($customer));
    $listener->tokenCreated($tokenFor($admin));

    $events = StatusSecurityEvent::query()->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->describe('en'))->toBe('New API token "mobile" for User #'.$admin->id.' (by system)');

    // A list of owner classes.
    config()->set('system-status.security.watch_changes.tokens', [Model::class]);
    $listener->tokenCreated($tokenFor($customer));
    config()->set('system-status.security.watch_changes.tokens', ['App\\Models\\Admin']);
    $listener->tokenCreated($tokenFor($admin));

    expect(StatusSecurityEvent::query()->count())->toBe(2);
});

it('reports insecure production settings in status:doctor', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('app.debug', false);
    config()->set('app.url', 'http://shop.test');
    config()->set('session.secure', false);
    config()->set('cors.allowed_origins', ['*']);
    config()->set('cors.supports_credentials', true);

    $this->artisan('status:doctor --no-http')
        ->expectsOutputToContain('APP_URL is not https://')
        ->expectsOutputToContain('SESSION_SECURE_COOKIE is off')
        ->expectsOutputToContain('any website can call the API as the signed-in user')
        ->assertFailed();

    app()->detectEnvironment(fn () => 'testing');
});

/* ---------- exposed files ---------- */

it('finds secret files the web server serves without Laravel', function () {
    config()->set('app.url', 'https://shop.test');
    Http::fake([
        'shop.test/.env' => Http::response("APP_NAME=Shop\nAPP_KEY=base64:x\n"),
        'shop.test/.git/config' => Http::response("[core]\n\trepositoryformatversion = 0\n"),
        // A catch-all route answers every other path with a page: not a finding.
        '*' => Http::response('<!doctype html><html><body>Home</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = (new ExposedFilesProbe)->run();

    expect($result->status)->toBe(ComponentStatus::MajorOutage)
        ->and($result->message)->toContain('/.env')->toContain('/.git/config')
        ->and($result->message)->not->toContain('APP_KEY')->not->toContain('backup.sql');

    Http::assertSent(fn ($request) => $request->hasHeader(ProbeSignature::HEADER));
});

it('is operational when nothing is exposed', function () {
    config()->set('app.url', 'https://shop.test');
    Http::fake(['*' => Http::response('Not found', 404)]);

    expect((new ExposedFilesProbe(skip: ['info.php']))->run()->status)->toBe(ComponentStatus::Operational);
});

it('does not report the package\'s own signed checks, but does report a forged signature', function () {
    config()->set('system-status.security.requests.scanner.max', 1);

    foreach (['/.env', '/.git/config', '/backup.sql'] as $path) {
        $this->withServerVariables(fromAttacker())
            ->withHeaders(ProbeSignature::headers('https://shop.test'.$path))
            ->get($path);
    }
    expect(StatusSecurityEvent::query()->count())->toBe(0);

    foreach (['/.env', '/.git/config'] as $path) {
        $this->withServerVariables(fromAttacker())->withHeaders([ProbeSignature::HEADER => str_repeat('a', 64)])->get($path);
    }
    expect(StatusSecurityEvent::query()->where('type', 'scanner')->count())->toBe(1);
});
