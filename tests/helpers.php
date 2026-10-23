<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Services\StatusService;
use Technobase\SystemStatus\Tests\Fixtures\FakeProbe;

/**
 * Gives a component a FakeProbe that runs on every status:check.
 */
function withFakeProbe(string $component, string $group = 'platform', array $extra = []): void
{
    config()->set("system-status.groups.{$group}.{$component}", array_merge([
        'probes' => [['class' => FakeProbe::class, 'with' => ['key' => $component], 'every' => 1]],
    ], $extra));
}

function probeReturns(string $component, ?ProbeResult $result): void
{
    FakeProbe::$results[$component] = $result ?? ProbeResult::ok();
}

/**
 * Runs status:check $times times, one minute apart, starting at the current test time.
 */
function runChecks(int $times = 1): array
{
    $result = [];

    for ($i = 0; $i < $times; $i++) {
        $result = app(StatusService::class)->run();
        Carbon::setTestNow(now()->addMinute());
    }

    return $result;
}

function addTraffic(string $component, int $requests, int $errors, string $route = 'GET api/orders', ?Carbon $at = null, array $extra = []): void
{
    DB::table('status_route_buckets')->insert(array_merge([
        'component' => $component,
        'route' => $route,
        'bucket_at' => ($at ?? now()->subMinutes(5))->format('Y-m-d H:i:s'),
        'requests' => $requests,
        'errors' => $errors,
        'slow' => 0,
        'total_ms' => $requests * 100,
    ], $extra));
}
