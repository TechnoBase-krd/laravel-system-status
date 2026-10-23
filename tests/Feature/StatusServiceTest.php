<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Events\ComponentStatusChanged;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Probes\ProbeResult;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
});

afterEach(fn () => Carbon::setTestNow());

it('stores every component as operational after a healthy run', function () {
    runChecks();

    expect(StatusComponent::query()->pluck('status', 'component')->map->value->all())
        ->toMatchArray(['database' => 'operational', 'api_orders' => 'operational']);
});

it('confirms a change only after two runs agree', function () {
    probeReturns('database', ProbeResult::outage('down'));

    runChecks(1);
    expect(StatusComponent::query()->where('component', 'database')->first()->status)->toBe(ComponentStatus::Operational);

    runChecks(1);
    $state = StatusComponent::query()->where('component', 'database')->first();
    expect($state->status)->toBe(ComponentStatus::MajorOutage)
        ->and($state->message)->toBe('down');
});

it('ignores a single bad run', function () {
    probeReturns('database', ProbeResult::outage('blip'));
    runChecks(1);
    probeReturns('database', ProbeResult::ok());
    runChecks(2);

    expect(StatusIncident::query()->count())->toBe(0);
});

it('opens, upgrades and resolves an incident', function () {
    probeReturns('database', ProbeResult::degraded('slow'));
    runChecks(2);

    $incident = StatusIncident::query()->sole();
    expect($incident->severity)->toBe(ComponentStatus::Degraded)->and($incident->resolved_at)->toBeNull();

    probeReturns('database', ProbeResult::outage('down'));
    runChecks(2);
    expect($incident->fresh()->severity)->toBe(ComponentStatus::MajorOutage)
        ->and(StatusIncident::query()->count())->toBe(1);

    probeReturns('database', ProbeResult::ok());
    runChecks(2);
    expect($incident->fresh()->resolved_at)->not->toBeNull();
});

it('fires ComponentStatusChanged on a confirmed change', function () {
    Event::fake([ComponentStatusChanged::class]);
    probeReturns('database', ProbeResult::outage('down'));

    runChecks(2);

    Event::assertDispatched(ComponentStatusChanged::class, fn ($e) => $e->component === 'database'
        && $e->previous === ComponentStatus::Operational
        && $e->current === ComponentStatus::MajorOutage);
});

it('marks a component degraded from its route error rate', function () {
    addTraffic('api_orders', 100, 10);

    runChecks(2);

    expect(StatusComponent::query()->where('component', 'api_orders')->first()->status)->toBe(ComponentStatus::Degraded);
});

it('needs a minimum number of requests before judging traffic', function () {
    addTraffic('api_orders', 10, 9);

    runChecks(2);

    expect(StatusComponent::query()->where('component', 'api_orders')->first()->status)->toBe(ComponentStatus::Operational);
});

it('treats a skipped probe as not configured', function () {
    probeReturns('database', ProbeResult::skipped('not set up'));

    runChecks();

    expect(StatusComponent::query()->where('component', 'database')->first()->configured)->toBeFalse();
});

it('records a scheduler gap as a resolved incident', function () {
    runChecks(1);
    Carbon::setTestNow(now()->addMinutes(20));

    runChecks(1);

    $incident = StatusIncident::query()->where('component', 'scheduler')->sole();
    expect($incident->severity)->toBe(ComponentStatus::MajorOutage)->and($incident->resolved_at)->not->toBeNull();
});

it('rolls up runs into status_daily', function () {
    probeReturns('database', ProbeResult::outage('down'));
    runChecks(3);

    $row = DB::table('status_daily')->where('component', 'database')->first();
    expect((int) $row->runs)->toBe(3)->and((int) $row->outage_runs)->toBe(2);
});
