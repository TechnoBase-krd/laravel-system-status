<?php

use Illuminate\Support\Carbon;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Tests\Fixtures\FakeProbe;

afterEach(fn () => Carbon::setTestNow());

it('passes on a healthy setup', function () {
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
    config()->set('system-status.groups.api.api_orders.probes', [['class' => FakeProbe::class]]);
    config()->set('system-status.groups.api.api_other.signal', true);
    config()->set('system-status.groups.backoffice.bo_other.signal', true);
    runChecks();

    $this->artisan('status:doctor')
        ->expectsOutputToContain('Database tables')
        ->assertSuccessful();
});

it('fails on unknown dependencies, broken probes and a stopped scheduler', function () {
    config()->set('system-status.groups.platform.database', [
        'depends_on' => ['nope'],
        'probes' => [['class' => 'App\\Missing\\Probe']],
    ]);
    runChecks();
    Carbon::setTestNow(now()->addMinutes(30));

    $this->artisan('status:doctor')
        ->expectsOutputToContain('Unknown depends_on: database → nope')
        ->expectsOutputToContain('Is the scheduler running?')
        ->assertFailed();
});
