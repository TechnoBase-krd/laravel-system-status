<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Probes\ProbeResult;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    Http::fake();
    config()->set('system-status.alerts.channels', ['slack']);
    config()->set('system-status.alerts.slack_webhook', 'https://hooks.slack.test/abc');
    withFakeProbe('database');
    withFakeProbe('api_orders', 'api', ['depends_on' => ['database']]);
    withFakeProbe('api_other', 'api', ['depends_on' => ['api_orders']]);
    foreach (['database', 'api_orders', 'api_other'] as $c) {
        probeReturns($c, ProbeResult::ok());
    }
});

afterEach(fn () => Carbon::setTestNow());

function slackMessages(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['text'] ?? null)->filter()->values()->all();
}

it('folds dependent failures into one alert and marks them affected', function () {
    foreach (['database', 'api_orders', 'api_other'] as $c) {
        probeReturns($c, ProbeResult::outage('down'));
    }

    runChecks(2);

    $messages = slackMessages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('Database: major outage')->toContain('Also affected: Api Orders, Api Other');

    $orders = StatusComponent::query()->where('component', 'api_orders')->first();
    expect($orders->affected_by)->toBe('database')
        ->and($orders->message)->toStartWith('Affected by Database')
        ->and(StatusComponent::query()->where('component', 'api_other')->value('affected_by'))->toBe('database')
        ->and(StatusIncident::query()->count())->toBe(3);
});

it('sends one recovery alert for the whole chain', function () {
    foreach (['database', 'api_orders'] as $c) {
        probeReturns($c, ProbeResult::outage('down'));
    }
    runChecks(2);
    foreach (['database', 'api_orders'] as $c) {
        probeReturns($c, ProbeResult::ok());
    }
    runChecks(2);

    $messages = slackMessages();
    expect($messages)->toHaveCount(2)
        ->and($messages[1])->toContain('Database recovered')->not->toContain('Api Orders recovered');
});

it('alerts when a dependent component stays down after its dependency recovers', function () {
    foreach (['database', 'api_orders'] as $c) {
        probeReturns($c, ProbeResult::outage('down'));
    }
    runChecks(2);
    probeReturns('database', ProbeResult::ok());
    runChecks(2);

    expect(last(slackMessages()))->toContain('Api Orders: still major outage after Database recovered');
});

it('alerts normally when only the dependent component fails', function () {
    probeReturns('api_orders', ProbeResult::outage('down'));
    runChecks(2);

    expect(slackMessages()[0])->toContain('Api Orders: major outage')
        ->and(StatusComponent::query()->where('component', 'api_orders')->value('affected_by'))->toBeNull();
});
