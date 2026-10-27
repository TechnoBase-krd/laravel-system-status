<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Technobase\SystemStatus\Http\Middleware\RecordRouteHealth;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Support\StatusSignal;
use Technobase\SystemStatus\SystemStatusServiceProvider;
use Technobase\SystemStatus\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
});

afterEach(fn () => Carbon::setTestNow());

function superAdmin(): User
{
    return User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);
}

it('is on by default', function () {
    expect(config('system-status.enabled'))->toBeTrue();

    $this->actingAs(superAdmin())->get('/system-status')->assertOk();
});

it('answers 404 on every route when turned off, but keeps the route names', function () {
    config()->set('system-status.enabled', false);
    config()->set('system-status.deploys.token', 'secret');

    $this->actingAs(superAdmin())->get('/system-status')->assertNotFound();
    $this->actingAs(superAdmin())->getJson('/system-status/summary')->assertNotFound();
    $this->postJson('/system-status/deploys', ['version' => '1.0.0'], ['Authorization' => 'Bearer secret'])->assertNotFound();

    expect(route('system_status.index'))->toEndWith('/system-status');
});

it('does not run status:check when turned off', function () {
    config()->set('system-status.enabled', false);

    $this->artisan('status:check')->expectsOutputToContain('turned off')->assertSuccessful();

    expect(StatusComponent::query()->count())->toBe(0);
});

it('sends no alerts when turned off', function () {
    Http::fake();
    config()->set('system-status.alerts.channels', ['slack']);
    config()->set('system-status.alerts.slack_webhook', 'https://hooks.slack.test/abc');
    config()->set('system-status.enabled', false);

    probeReturns('database', ProbeResult::outage('down'));
    runChecks(3);

    Http::assertNothingSent();
});

it('records no signals when turned off', function () {
    config()->set('system-status.enabled', false);

    StatusSignal::failure('database', 'RuntimeException');

    expect(StatusSignal::lastFailure('database'))->toBeNull();
});

it('registers neither the schedule nor the traffic middleware when turned off', function () {
    config()->set('system-status.enabled', false);
    config()->set('system-status.schedule', true);
    config()->set('system-status.record_traffic', true);

    // A fresh kernel and schedule, so only the boot below can add to them.
    app()->forgetInstance(Kernel::class);
    app()->forgetInstance(Schedule::class);
    (new SystemStatusServiceProvider(app()))->boot();

    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

    expect($commands->filter(fn ($command) => str_contains($command, 'status:'))->all())->toBe([])
        ->and(app(Kernel::class)->hasMiddleware(RecordRouteHealth::class))->toBeFalse();
});
