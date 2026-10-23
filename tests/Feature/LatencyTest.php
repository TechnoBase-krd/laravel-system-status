<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Support\StatusMetrics;
use Technobase\SystemStatus\Tests\Fixtures\User;

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    withFakeProbe('database');
    probeReturns('database', ProbeResult::ok());
});

afterEach(fn () => Carbon::setTestNow());

function slowTraffic(int $requests = 100): void
{
    addTraffic('api_orders', $requests, 0, extra: ['le_100' => intdiv($requests, 2), 'le_5000' => $requests - intdiv($requests, 2)]);
}

it('ignores slowness while no latency threshold is set', function () {
    slowTraffic();
    runChecks(2);

    expect(StatusComponent::query()->where('component', 'api_orders')->first()->status)->toBe(ComponentStatus::Operational);
});

it('marks a slow component degraded without any errors', function () {
    config()->set('system-status.thresholds.latency_degraded_p95_ms', 1000);
    slowTraffic();
    runChecks(2);

    $state = StatusComponent::query()->where('component', 'api_orders')->first();
    expect($state->status)->toBe(ComponentStatus::Degraded)->and($state->message)->toContain('p95 response time');
});

it('lets a component override thresholds', function () {
    config()->set('system-status.groups.api.api_orders.thresholds', ['latency_degraded_p95_ms' => 6000, 'latency_outage_p95_ms' => 2000]);
    slowTraffic();
    runChecks(2);

    expect(StatusComponent::query()->where('component', 'api_orders')->first()->status)->toBe(ComponentStatus::MajorOutage);
});

it('shows p50 and p95 in the component detail', function () {
    slowTraffic();
    $user = User::query()->create(['name' => 'Admin', 'role' => 'Super Admin']);

    $data = $this->actingAs($user)->getJson('/system-status/components/api_orders')->assertOk()->json('data');

    expect($data['latency']['p95'])->toBeGreaterThan(2500)
        ->and($data['latency']['p50'])->toBeLessThanOrEqual(100)
        ->and($data['routes'][0]['p95_ms'])->toBeGreaterThan(2500);
});

it('records a histogram in Redis and flushes it to the database', function () {
    $this->flushRedis();
    Carbon::setTestNow(null);

    try {
        StatusMetrics::record('api_orders', 'GET api/orders', false, 30);
        StatusMetrics::record('api_orders', 'GET api/orders', false, 3000);
        app('redis')->connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not available.');
    }

    // Move the recorded minute into the past so flush() picks it up.
    $redis = app('redis')->connection();
    $minute = intdiv(time(), 60) * 60;
    $redis->rename('status:metrics:'.$minute, 'status:metrics:'.($minute - 60));

    expect(app(StatusMetrics::class)->flush())->toBe(1);

    $row = DB::table('status_route_buckets')->where('component', 'api_orders')->sole();
    expect((int) $row->requests)->toBe(2)
        ->and((int) $row->le_50)->toBe(1)
        ->and((int) $row->le_5000)->toBe(1);
});
