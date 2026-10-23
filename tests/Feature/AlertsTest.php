<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Support\StatusAlerter;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
    Http::fake();
    config()->set('system-status.alerts.channels', ['slack']);
    config()->set('system-status.alerts.slack_webhook', 'https://hooks.slack.test/abc');
    // The tests jump in time; a jump is not a scheduler gap here.
    config()->set('system-status.thresholds.heartbeat_stale_minutes', 1000);
});

afterEach(fn () => Carbon::setTestNow());

function slackTexts(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'hooks.slack.test'))
        ->map(fn ($pair) => $pair[0]->data()['text'])
        ->values()->all();
}

it('sends status changes to slack with a readable recovery duration', function () {
    probeReturns('database', ProbeResult::outage('connection refused'));
    runChecks(2);
    Carbon::setTestNow(now()->addMinutes(12));
    probeReturns('database', ProbeResult::ok());
    runChecks(2);

    $texts = slackTexts();
    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain('Database: major outage')->toContain('connection refused')
        ->and($texts[1])->toContain('Database recovered after 14 min');
});

it('sends mail and discord when configured', function () {
    Mail::fake();
    config()->set('system-status.alerts.channels', ['mail', 'discord']);
    config()->set('system-status.alerts.mail_to', ['ops@example.test']);
    config()->set('system-status.alerts.discord_webhook', 'https://discord.test/hook');

    $results = app(StatusAlerter::class)->send('Hello');

    expect($results)->toBe(['mail' => 'sent', 'discord' => 'sent']);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://discord.test/hook' && str_contains($r['content'], 'Hello'));
});

it('reports unconfigured channels as skipped', function () {
    $results = app(StatusAlerter::class)->send('Hello', ['telegram', 'mail']);

    expect($results['telegram'])->toStartWith('skipped')->and($results['mail'])->toStartWith('skipped');
});

it('sends nothing in the local environment unless forced', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(app(StatusAlerter::class)->send('x')['slack'])->toStartWith('skipped')
        ->and(app(StatusAlerter::class)->send('x', force: true)['slack'])->toBe('sent');
});

it('pauses alerts while a component flaps and says when it is stable', function () {
    config()->set('system-status.alerts.flap', ['changes' => 4, 'minutes' => 30]);
    config()->set('system-status.thresholds.confirm_runs', 1);

    foreach ([1, 2, 3, 4, 5, 6] as $i) {
        probeReturns('database', $i % 2 ? ProbeResult::outage('down') : ProbeResult::ok());
        runChecks(1);
    }

    $texts = slackTexts();
    expect($texts)->toHaveCount(4)
        ->and($texts[3])->toContain('flapping');

    Carbon::setTestNow(now()->addMinutes(31));
    runChecks(1);

    expect(last(slackTexts()))->toContain('stable again');
});

it('reminds about a long major outage', function () {
    config()->set('system-status.alerts.reminder_minutes', 30);
    probeReturns('database', ProbeResult::outage('down'));
    runChecks(2);

    Carbon::setTestNow(now()->addMinutes(31));
    runChecks(1);
    Carbon::setTestNow(now()->addMinutes(5));
    runChecks(1);

    $reminders = array_values(array_filter(slackTexts(), fn ($t) => str_contains($t, 'Still down')));
    expect($reminders)->toHaveCount(1)
        ->and(StatusIncident::query()->where('component', 'database')->sole()->reminded_at)->not->toBeNull();
});

it('posts signed webhook events on a status change', function () {
    config()->set('system-status.alerts.webhooks', [['url' => 'https://example.test/hook', 'secret' => 's3cret']]);
    probeReturns('database', ProbeResult::outage('down'));

    runChecks(2);

    Http::assertSent(function (Request $request) {
        if ($request->url() !== 'https://example.test/hook') {
            return false;
        }

        $signature = 'sha256='.hash_hmac('sha256', $request->body(), 's3cret');

        return $request['event'] === 'component.status_changed'
            && $request['data']['current'] === 'major_outage'
            && $request->header('X-System-Status-Signature')[0] === $signature;
    });
});

it('tests the alert setup from the console', function () {
    $this->artisan('status:test-alert')->expectsTable(['Channel', 'Result'], [['slack', 'sent']])->assertSuccessful();

    Http::swap(new Factory);
    Http::fake(['*' => Http::response('nope', 500)]);
    $this->artisan('status:test-alert')->assertFailed();
});
