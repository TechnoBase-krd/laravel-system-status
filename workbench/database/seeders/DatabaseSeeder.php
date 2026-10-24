<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Enums\IncidentState;
use Technobase\SystemStatus\Models\StatusDeploy;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Support\LatencyHistogram;
use Technobase\SystemStatus\Support\StatusRegistry;

/**
 * Sample data for the demo: 90 days of history, live traffic with latency, an active and an
 * upcoming maintenance window, open and resolved incidents (manual and automatic).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(7);
        $now = now();
        $components = array_keys(app(StatusRegistry::class)->components());
        $current = [
            'redis' => [ComponentStatus::Maintenance, 'Redis 7 upgrade'],
            'api_cart_checkout' => [ComponentStatus::Degraded, 'Payment provider is slow, we are on it'],
            'search' => [ComponentStatus::MajorOutage, 'Could not connect to typesense:8108'],
            'telegram' => [ComponentStatus::Degraded, 'p95 response time 2380 ms in the last 15 min (limit 1500 ms)'],
        ];

        // Current state.
        foreach ($components as $component) {
            [$status, $message] = $current[$component] ?? [ComponentStatus::Operational, null];
            DB::table('status_components')->insert([
                'component' => $component, 'status' => $status->value, 'message' => $message, 'configured' => true,
                'changed_at' => $now->copy()->subMinutes(mt_rand(5, 300)), 'checked_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // 90 days of daily history.
        $daily = [];
        $monthly = [];
        for ($d = 89; $d >= 0; $d--) {
            $day = $now->copy()->subDays($d);
            foreach ($components as $component) {
                $runs = $d === 0 ? $now->hour * 60 + $now->minute : 1440;
                $roll = mt_rand(1, 100);
                $outage = $roll > 97 ? mt_rand(5, 80) : 0;
                $degraded = $roll > 90 ? mt_rand(10, 120) : 0;
                $maintenance = ($d === 12 && in_array($component, ['database', 'api_orders'], true)) || ($d === 0 && $component === 'redis') ? 25 : 0;
                $row = ['component' => $component, 'day' => $day->toDateString(), 'runs' => $runs, 'degraded_runs' => $degraded, 'outage_runs' => $outage, 'maintenance_runs' => $maintenance];
                $daily[] = $row;

                $key = $component.$day->format('Y-m');
                $monthly[$key] ??= ['component' => $component, 'month' => $day->format('Y-m'), 'runs' => 0, 'degraded_runs' => 0, 'outage_runs' => 0, 'maintenance_runs' => 0];
                foreach (['runs', 'degraded_runs', 'outage_runs', 'maintenance_runs'] as $column) {
                    $monthly[$key][$column] += $row[$column];
                }
            }
        }
        foreach (array_chunk($daily, 500) as $chunk) {
            DB::table('status_daily')->insert($chunk);
        }
        DB::table('status_monthly')->insert(array_values($monthly));

        // A database check every minute for a day and every 2 hours for a week (marks the runs).
        $checks = [];
        for ($m = 0; $m < 1440; $m++) {
            $checks[] = ['component' => 'database', 'probe' => '0|Database', 'status' => 'operational', 'response_ms' => mt_rand(2, 9), 'message' => null, 'checked_at' => $now->copy()->subMinutes($m)];
        }
        for ($h = 24; $h < 168; $h += 2) {
            $checks[] = ['component' => 'database', 'probe' => '0|Database', 'status' => 'operational', 'response_ms' => mt_rand(2, 9), 'message' => null, 'checked_at' => $now->copy()->subHours($h)];
        }
        $checks[] = ['component' => 'search', 'probe' => '0|GET /health', 'status' => 'major_outage', 'response_ms' => 10004, 'message' => 'Could not connect to typesense:8108', 'checked_at' => $now];
        $checks[] = ['component' => 'storage', 'probe' => '0|Disk space storage', 'status' => 'operational', 'response_ms' => 1, 'message' => '62.4% free (118 GB of 189 GB)', 'checked_at' => $now];
        foreach (array_chunk($checks, 500) as $chunk) {
            DB::table('status_checks')->insert($chunk);
        }

        // Traffic with response times for the last 24 hours.
        $routes = [
            'api_auth' => ['POST api/v1/auth/login', 'POST api/v1/auth/otp'],
            'api_catalogue' => ['GET api/v1/products', 'GET api/v1/products/{product}'],
            'api_cart_checkout' => ['POST api/v1/checkout', 'GET api/v1/cart'],
            'api_orders' => ['GET api/v1/orders', 'GET api/v1/orders/{order}'],
            'bo_orders' => ['GET admin/orders', 'POST admin/orders/{order}/ship'],
            'telegram' => ['POST telegram/webhook'],
        ];
        $buckets = [];
        $start = intdiv($now->timestamp, 300) * 300 - 86400;
        for ($t = $start; $t < $now->timestamp; $t += 300) {
            foreach ($routes as $component => $list) {
                foreach ($list as $route) {
                    $requests = mt_rand(20, 160);
                    $slowish = $component === 'api_cart_checkout' || $component === 'telegram' ? 0.35 : 0.05;
                    $errors = $component === 'api_cart_checkout' && $t > $now->timestamp - 3600 ? intdiv($requests, 12) : (mt_rand(1, 50) === 1 ? mt_rand(1, 4) : 0);
                    $histogram = array_fill(0, 8, 0);
                    for ($i = 0; $i < $requests; $i++) {
                        $ms = mt_rand(1, 100) / 100 < $slowish ? mt_rand(900, 4200) : mt_rand(20, 380);
                        $histogram[LatencyHistogram::index($ms)]++;
                    }
                    $buckets[] = array_merge([
                        'component' => $component, 'route' => $route, 'bucket_at' => date('Y-m-d H:i:s', $t),
                        'requests' => $requests, 'errors' => $errors, 'slow' => $histogram[6] + $histogram[7],
                        'total_ms' => $requests * mt_rand(90, 400),
                    ], array_combine(LatencyHistogram::COLUMNS, $histogram));
                }
            }
        }
        foreach (array_chunk($buckets, 400) as $chunk) {
            DB::table('status_route_buckets')->insert($chunk);
        }

        // Maintenance: one running now, one tomorrow night.
        StatusMaintenance::query()->create([
            'title' => 'Redis 7 upgrade', 'message' => 'Cache and sessions are moved to the new cluster.',
            'components' => ['redis'], 'starts_at' => $now->copy()->subMinutes(10), 'ends_at' => $now->copy()->addMinutes(35),
            'announced_at' => $now->copy()->subMinutes(10), 'created_by' => 'Demo Admin',
        ]);
        StatusMaintenance::query()->create([
            'title' => 'Database version upgrade', 'components' => ['database', 'api_orders', 'api_cart_checkout'],
            'starts_at' => $now->copy()->addDay()->setTime(2, 0), 'ends_at' => $now->copy()->addDay()->setTime(3, 0), 'created_by' => 'Demo Admin',
        ]);

        // Deploys: one three days ago (before the database incident), one this morning, one just now.
        StatusDeploy::query()->create([
            'version' => 'v1.8.0', 'commit' => '4f2a9c1', 'deployed_by' => 'ci', 'note' => 'Order export',
            'deployed_at' => $now->copy()->subDays(3)->setTime(13, 58), 'compared_at' => $now->copy()->subDays(3)->setTime(14, 13), 'comparison' => [],
        ]);
        StatusDeploy::query()->create([
            'version' => 'v1.8.1', 'commit' => '9b7e3d2', 'deployed_by' => 'ci',
            'deployed_at' => $now->copy()->subHours(6), 'compared_at' => $now->copy()->subHours(6)->addMinutes(15), 'comparison' => [],
        ]);
        StatusDeploy::query()->create([
            'version' => 'v1.8.2', 'commit' => 'a1b2c3d', 'deployed_by' => 'Demo Admin', 'note' => 'New checkout',
            'deployed_at' => $now->copy()->subMinutes(20), 'compared_at' => $now->copy()->subMinutes(5),
            'comparison' => [['component' => 'api_cart_checkout', 'error_rate_before' => 0.4, 'error_rate_after' => 3.1,
                'p95_before' => 410, 'p95_after' => 1240, 'errors_worse' => true, 'slower' => true]],
        ]);

        // Incidents.
        $checkout = StatusIncident::query()->create([
            'component' => 'api_cart_checkout', 'severity' => ComponentStatus::Degraded, 'source' => 'manual',
            'title' => 'Payment provider is slow, we are on it', 'message' => 'Card payments take up to 20 seconds.',
            'state' => IncidentState::Identified, 'created_by' => 'Demo Admin', 'started_at' => $now->copy()->subMinutes(48),
        ]);
        $checkout->updates()->create(['state' => IncidentState::Investigating, 'message' => 'Card payments take up to 20 seconds.', 'author' => 'Demo Admin', 'created_at' => $now->copy()->subMinutes(48)]);
        $checkout->updates()->create(['state' => IncidentState::Identified, 'message' => 'The payment provider confirmed a problem on their side.', 'author' => 'Demo Admin', 'created_at' => $now->copy()->subMinutes(20)]);

        StatusIncident::query()->create([
            'component' => 'search', 'severity' => ComponentStatus::MajorOutage, 'message' => 'Could not connect to typesense:8108',
            'started_at' => $now->copy()->subMinutes(14),
        ]);
        StatusIncident::query()->create([
            'component' => 'telegram', 'severity' => ComponentStatus::Degraded, 'message' => 'p95 response time 2380 ms in the last 15 min (limit 1500 ms)',
            'started_at' => $now->copy()->subMinutes(31),
        ]);

        $past = StatusIncident::query()->create([
            'component' => 'database', 'severity' => ComponentStatus::MajorOutage, 'source' => 'manual', 'title' => 'Orders could not be saved',
            'message' => 'The database ran out of disk space.', 'state' => IncidentState::Resolved, 'created_by' => 'Demo Admin',
            'started_at' => $now->copy()->subDays(3)->setTime(14, 5), 'resolved_at' => $now->copy()->subDays(3)->setTime(14, 47),
            'postmortem' => "The binary logs filled the disk after a failed cleanup job.\nWe freed space, fixed the cleanup job and added a DiskSpaceProbe that warns at 15% free.",
        ]);
        $past->updates()->create(['state' => IncidentState::Investigating, 'message' => 'Orders fail with a database error.', 'author' => 'Demo Admin', 'created_at' => $now->copy()->subDays(3)->setTime(14, 6)]);
        $past->updates()->create(['state' => IncidentState::Resolved, 'message' => 'Disk space freed; orders work again.', 'author' => 'Demo Admin', 'created_at' => $now->copy()->subDays(3)->setTime(14, 47)]);

        StatusIncident::query()->create([
            'component' => 'api_orders', 'severity' => ComponentStatus::MajorOutage, 'message' => 'Affected by Database',
            'affected_by' => 'database', 'started_at' => $now->copy()->subDays(3)->setTime(14, 6), 'resolved_at' => $now->copy()->subDays(3)->setTime(14, 46),
        ]);
    }
}
