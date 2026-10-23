<?php

use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Support\StatusSql;

it('adds to existing rows on conflict', function () {
    $row = ['component' => 'x', 'day' => '2026-10-20', 'runs' => 2, 'degraded_runs' => 1, 'outage_runs' => 0];
    $update = fn () => StatusSql::incrementOnConflict('status_daily', ['runs', 'degraded_runs', 'outage_runs']);

    DB::table('status_daily')->upsert([$row], ['component', 'day'], $update());
    DB::table('status_daily')->upsert([$row], ['component', 'day'], $update());

    $stored = DB::table('status_daily')->sole();
    expect((int) $stored->runs)->toBe(4)->and((int) $stored->degraded_runs)->toBe(2);
});

it('formats an hour on the current driver', function () {
    DB::table('status_checks')->insert(['component' => 'x', 'probe' => 'p', 'checked_at' => '2026-10-20 13:45:12']);

    $hour = DB::table('status_checks')->selectRaw(StatusSql::hour('checked_at').' AS h')->value('h');

    expect($hour)->toBe('2026-10-20 13:00');
});
