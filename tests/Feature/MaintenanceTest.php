<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Events\ComponentStatusChanged;
use Technobase\SystemStatus\Events\MaintenanceEnded;
use Technobase\SystemStatus\Events\MaintenanceStarted;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Services\MaintenanceService;
use Technobase\SystemStatus\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
});

afterEach(fn () => Carbon::setTestNow());

function maintain(array $components, int $minutes = 30, int $startsIn = 0): StatusMaintenance
{
    return app(MaintenanceService::class)->schedule(
        $components,
        now()->addMinutes($startsIn),
        now()->addMinutes($startsIn + $minutes),
        'Database upgrade',
    );
}

it('shows maintenance at once and opens no incident during the window', function () {
    Event::fake([ComponentStatusChanged::class, MaintenanceStarted::class]);
    maintain(['database']);
    probeReturns('database', ProbeResult::outage('down for upgrade'));

    runChecks(3);

    $state = StatusComponent::query()->where('component', 'database')->first();
    expect($state->status)->toBe(ComponentStatus::Maintenance)
        ->and($state->message)->toBe('Database upgrade')
        ->and(StatusIncident::query()->count())->toBe(0);
    Event::assertNotDispatched(ComponentStatusChanged::class);
    Event::assertDispatchedTimes(MaintenanceStarted::class, 1);
});

it('returns to the observed status when the window ends', function () {
    Event::fake([MaintenanceEnded::class]);
    maintain(['database'], minutes: 2);
    runChecks(3);

    expect(StatusComponent::query()->where('component', 'database')->first()->status)->toBe(ComponentStatus::Operational);
    Event::assertDispatchedTimes(MaintenanceEnded::class, 1);
});

it('opens an incident when a problem remains after the window, after confirmation', function () {
    maintain(['database'], minutes: 2);
    probeReturns('database', ProbeResult::outage('still down'));
    runChecks(3);
    expect(StatusComponent::query()->where('component', 'database')->first()->status)->toBe(ComponentStatus::Operational);

    runChecks(1);

    expect(StatusComponent::query()->where('component', 'database')->first()->status)->toBe(ComponentStatus::MajorOutage)
        ->and(StatusIncident::query()->open()->where('component', 'database')->count())->toBe(1);
});

it('does not count maintenance against uptime', function () {
    maintain(['database'], minutes: 60);
    runChecks(4);

    $row = DB::table('status_daily')->where('component', 'database')->first();
    expect((int) $row->maintenance_runs)->toBe(4);

    $user = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);
    $component = collect($this->actingAs($user)->getJson('/system-status/summary?range=90d')->json('data.groups'))
        ->flatMap(fn ($g) => $g['components'])->firstWhere('key', 'database');

    expect($component['status'])->toBe('maintenance')
        ->and($component['uptime'])->toEqual(100)
        ->and(end($component['days'])['level'])->toBe('maintenance');
});

it('lets a manager schedule and end maintenance from the page', function () {
    $user = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);

    $this->actingAs($user)->postJson('/system-status/maintenances', [
        'title' => 'Deploy',
        'components' => ['database', 'api_orders'],
        'starts_at' => now()->toIso8601String(),
        'ends_at' => now()->addHour()->toIso8601String(),
    ])->assertCreated()->assertJsonPath('data.active', true);

    $maintenance = StatusMaintenance::query()->sole();
    expect($maintenance->created_by)->toBe('Admin');

    $this->actingAs($user)->deleteJson("/system-status/maintenances/{$maintenance->id}")->assertOk();
    expect($maintenance->fresh()->isActive())->toBeFalse();
});

it('validates the maintenance form', function () {
    $user = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);

    $this->actingAs($user)->postJson('/system-status/maintenances', [
        'title' => '',
        'components' => ['nope'],
        'starts_at' => now()->toIso8601String(),
        'ends_at' => now()->subHour()->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'components.0', 'ends_at']);
});

it('refuses management without the manage gate', function () {
    config()->set('system-status.access.manage_gate', 'manageSystemStatus');
    $user = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);

    $this->actingAs($user)->postJson('/system-status/maintenances', [])->assertForbidden();
});

it('cancels a window that has not started', function () {
    $maintenance = maintain(['database'], startsIn: 60);

    app(MaintenanceService::class)->end($maintenance);

    expect(StatusMaintenance::query()->count())->toBe(0);
});

it('schedules from the console', function () {
    $this->artisan('status:maintenance', ['components' => ['database'], '--minutes' => 15])->assertSuccessful();
    $this->artisan('status:maintenance', ['components' => ['nope']])->assertFailed();
    $this->artisan('status:maintenance', ['--end' => 'all'])->assertSuccessful();

    expect(StatusMaintenance::query()->sole()->isActive())->toBeFalse();
});

it('ignores errors recorded during the window once it is over', function () {
    maintain(['api_orders'], minutes: 2);
    addTraffic('api_orders', 100, 90, at: now()->subMinutes(5));

    runChecks(5);

    expect(StatusComponent::query()->where('component', 'api_orders')->first()->status)->toBe(ComponentStatus::Operational)
        ->and(StatusIncident::query()->count())->toBe(0);
});

it('closes an incident left open from before the window', function () {
    probeReturns('database', ProbeResult::outage('down'));
    runChecks(2);
    maintain(['database'], minutes: 2);
    probeReturns('database', ProbeResult::ok());
    runChecks(3);

    expect(StatusIncident::query()->open()->count())->toBe(0);
});
