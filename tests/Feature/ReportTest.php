<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Technobase\SystemStatus\Actions\GetStatusReportAction;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database', extra: ['sla' => 99.9]);
    probeReturns('database', ProbeResult::ok());
});

afterEach(fn () => Carbon::setTestNow());

function monthRow(string $component, int $runs, int $outage, int $maintenance = 0, string $month = '2026-09'): void
{
    DB::table('status_monthly')->insert([
        'component' => $component, 'month' => $month, 'runs' => $runs,
        'degraded_runs' => 0, 'outage_runs' => $outage, 'maintenance_runs' => $maintenance,
    ]);
}

it('rolls runs up per month', function () {
    runChecks(3);

    expect((int) DB::table('status_monthly')->where(['component' => 'database', 'month' => '2026-10'])->value('runs'))->toBe(3);
});

it('computes uptime against the SLA, excluding maintenance', function () {
    monthRow('database', 43200, 60, maintenance: 1200);

    $row = collect(app(GetStatusReportAction::class)->execute('2026-09')['components'])->firstWhere('key', 'database');

    // (42000 - 60) / 42000
    expect($row['uptime'])->toBe(99.857)
        ->and($row['target'])->toBe(99.9)
        ->and($row['met'])->toBeFalse()
        ->and($row['maintenance_minutes'])->toBe(1200);
});

it('uses the default target and leaves components without data blank', function () {
    config()->set('system-status.sla.default_target', 99.0);

    $report = app(GetStatusReportAction::class)->execute('2026-09');
    $orders = collect($report['components'])->firstWhere('key', 'api_orders');

    expect($orders['target'])->toBe(99.0)->and($orders['uptime'])->toBeNull()->and($orders['met'])->toBeNull();
});

it('exports CSV and HTML from the page', function () {
    monthRow('database', 1000, 0);
    $user = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);

    $csv = $this->actingAs($user)->get('/system-status/reports/2026-09.csv')->assertOk()->getContent();
    expect($csv)->toContain('2026-09,platform,database,100');

    $this->actingAs($user)->get('/system-status/reports/2026-09.html')->assertOk()->assertSee('Uptime report');
    $this->actingAs($user)->get('/system-status/reports/2026-13.html')->assertNotFound();
});

it('prints and mails the report from the console', function () {
    Mail::fake();
    monthRow('database', 1000, 10);

    $this->artisan('status:report', ['--month' => '2026-09'])->assertSuccessful();
    $this->artisan('status:report', ['--month' => '2026-09', '--format' => 'csv'])->expectsOutputToContain('database')->assertSuccessful();
    $this->artisan('status:report', ['--month' => '2026-09', '--mail' => ['ops@example.test']])->assertSuccessful();
    $this->artisan('status:report', ['--format' => 'pdf'])->assertFailed();
});

it('marks components below their SLA in the 90-day view', function () {
    DB::table('status_daily')->insert(['component' => 'database', 'day' => '2026-10-19', 'runs' => 1000, 'degraded_runs' => 0, 'outage_runs' => 100, 'maintenance_runs' => 0]);
    $user = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);

    $database = collect($this->actingAs($user)->getJson('/system-status/summary')->json('data.groups'))
        ->flatMap(fn ($g) => $g['components'])->firstWhere('key', 'database');

    expect($database['sla_target'])->toEqual(99.9)->and($database['sla_missed'])->toBeTrue();
});
