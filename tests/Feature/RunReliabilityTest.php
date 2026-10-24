<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;
use Technobase\SystemStatus\Probes\CdnStorageProbe;
use Technobase\SystemStatus\Probes\HttpEndpointProbe;
use Technobase\SystemStatus\Services\ProbeRunner;
use Technobase\SystemStatus\Support\StatusHeartbeat;
use Technobase\SystemStatus\SystemStatusServiceProvider;
use Technobase\SystemStatus\Tests\Fixtures\BrokenSendProbe;
use Technobase\SystemStatus\Tests\Fixtures\CustomRunHttpProbe;

beforeEach(fn () => Carbon::setTestNow('2026-10-20 12:00:00'));

afterEach(fn () => Carbon::setTestNow());

function httpProbe(string $component, string $path): void
{
    config()->set("system-status.groups.web.{$component}", [
        'probes' => [['class' => HttpEndpointProbe::class, 'with' => ['path' => $path]]],
    ]);
}

/**
 * Makes the following runs throw. Not through a database error: on PostgreSQL that would abort
 * the test's transaction and every later query with it.
 */
function breakRuns(): void
{
    test()->mock(ProbeRunner::class)->shouldReceive('run')->andThrow(new RuntimeException('probe runner broke'));
}

function checkStatus(string $component): ?string
{
    return DB::table('status_checks')->where('component', $component)->value('status');
}

it('sends due HTTP probes together and stores each result', function (bool $concurrent) {
    config()->set('app.url', 'https://shop.test');
    config()->set('system-status.concurrent_probes', $concurrent);
    httpProbe('home', '/');
    httpProbe('api', '/api/ping');
    httpProbe('broken', '/broken');

    Http::fake([
        'shop.test/api/ping' => Http::response(['ok' => true]),
        'shop.test/broken' => Http::response('', 500),
        'shop.test/*' => Http::response('<html></html>'),
    ]);

    $result = runChecks();

    expect($result['probes_run'])->toBe(3)
        ->and(checkStatus('home'))->toBe('operational')
        ->and(checkStatus('api'))->toBe('operational')
        ->and(checkStatus('broken'))->toBe('major_outage')
        ->and(DB::table('status_checks')->where('component', 'broken')->value('message'))->toBe('/broken returned HTTP 500');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->hasHeader(HttpEndpointProbe::HEADER));
})->with(['pooled' => true, 'one by one' => false]);

it('reports a connection failure inside the pool', function () {
    config()->set('app.url', 'https://shop.test');
    httpProbe('home', '/');
    httpProbe('api', '/api/ping');

    Http::fake([
        'shop.test/api/ping' => Http::failedConnection(),
        'shop.test/*' => Http::response('ok'),
    ]);

    runChecks();

    expect(checkStatus('home'))->toBe('operational')
        ->and(checkStatus('api'))->toBe('major_outage')
        ->and(DB::table('status_checks')->where('component', 'api')->value('message'))->toStartWith('Could not connect to https://shop.test/api/ping');
});

it('skips a concurrent probe in its precheck without a request', function () {
    config()->set('filesystems.disks.cdn.url', null);
    config()->set('system-status.groups.web.cdn', ['probes' => [['class' => CdnStorageProbe::class, 'with' => ['disk' => 'cdn']]]]);
    httpProbe('home', '/');
    Http::fake(['*' => Http::response('ok')]);

    runChecks();

    expect(checkStatus('cdn'))->toBeNull()
        ->and(DB::table('status_checks')->where('component', 'cdn')->value('message'))->toBe('CDN URL is not configured');
    Http::assertSentCount(1);
});

it('runs the probes one by one when the pool fails', function () {
    config()->set('app.url', 'https://shop.test');
    httpProbe('home', '/');
    config()->set('system-status.groups.web.odd', ['probes' => [['class' => BrokenSendProbe::class]]]);
    Http::fake(['*' => Http::response('ok')]);

    runChecks();

    expect(checkStatus('home'))->toBe('operational')
        ->and(checkStatus('odd'))->toBe('major_outage')
        ->and(DB::table('status_checks')->where('component', 'odd')->value('message'))->toBe('Check failed (RuntimeException)');
});

it('keeps calling run() of a subclass that overrides it', function () {
    config()->set('app.url', 'https://shop.test');
    httpProbe('home', '/');
    httpProbe('api', '/api/ping');
    config()->set('system-status.groups.web.custom', ['probes' => [['class' => CustomRunHttpProbe::class, 'with' => ['path' => '/custom']]]]);
    Http::fake(['*' => Http::response('ok')]);

    runChecks();

    expect(DB::table('status_checks')->where('component', 'custom')->value('message'))->toBe('custom run');
    Http::assertSentCount(2);
});

it('keeps the heartbeat of successful and failed runs', function () {
    runChecks();
    expect(StatusHeartbeat::get())->toMatchArray([
        'last_success_at' => Carbon::parse('2026-10-20 12:00:00')->getTimestamp(),
        'failing_since' => null,
    ]);

    breakRuns();

    expect(fn () => runChecks())->toThrow(Exception::class);
    expect(fn () => runChecks())->toThrow(Exception::class);

    $state = StatusHeartbeat::get();
    expect($state['last_success_at'])->toBe(Carbon::parse('2026-10-20 12:00:00')->getTimestamp())
        ->and($state['failing_since'])->toBe(Carbon::parse('2026-10-20 12:01:00')->getTimestamp())
        ->and($state['error'])->toBe('RuntimeException');
});

it('shows failing checks on the page instead of old data', function () {
    runChecks();
    breakRuns();

    for ($i = 0; $i < 5; $i++) {
        try {
            runChecks();
        } catch (Throwable) {
            Carbon::setTestNow(now()->addMinute());
        }
    }

    $summary = app(GetStatusSummaryAction::class)->execute('24h');
    $scheduler = collect($summary['groups'])->flatMap(fn ($group) => $group['components'])->firstWhere('key', 'scheduler');

    expect($summary['stale'])->toBeTrue()
        ->and($summary['checks_failing'])->toBe(['since' => '2026-10-20 12:01', 'error' => 'RuntimeException'])
        ->and($scheduler['status'])->toBe('major_outage')
        ->and($scheduler['message'])->toContain('2026-10-20 12:01')->toContain('RuntimeException');
});

it('does not call one failed run a stall', function () {
    runChecks();
    breakRuns();

    try {
        runChecks();
    } catch (Throwable) {
    }

    expect(app(GetStatusSummaryAction::class)->execute('24h'))->toMatchArray(['stale' => false, 'checks_failing' => null]);
});

/**
 * @return array<string, ScheduledEvent>
 */
function scheduledStatusCommands(): array
{
    config()->set('system-status.schedule', true);
    config()->set('system-status.reports.mail_to', ['ops@example.com']);
    (new SystemStatusServiceProvider(app()))->boot();

    return collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'status:'))
        ->keyBy(fn (ScheduledEvent $event) => preg_replace('/.*(status:\w+).*/', '$1', (string) $event->command))
        ->all();
}

it('schedules the commands on one server', function () {
    $events = scheduledStatusCommands();

    expect(array_keys($events))->toEqualCanonicalizing(['status:check', 'status:prune', 'status:report'])
        ->and(collect($events)->every(fn (ScheduledEvent $event) => $event->onOneServer))->toBeTrue()
        ->and($events['status:check']->withoutOverlapping)->toBeTrue();
});

it('runs on every server when schedule_on_one_server is off', function () {
    config()->set('system-status.schedule_on_one_server', false);

    expect(collect(scheduledStatusCommands())->contains(fn (ScheduledEvent $event) => $event->onOneServer))->toBeFalse();
});

it('runs on every server when the cache store cannot be used for locks', function () {
    config()->set('cache.default', 'not-configured');

    expect(collect(scheduledStatusCommands())->contains(fn (ScheduledEvent $event) => $event->onOneServer))->toBeFalse();
});
