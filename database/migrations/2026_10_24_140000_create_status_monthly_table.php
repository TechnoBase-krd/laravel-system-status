<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Monthly rollup for SLA reports. Small (one row per component and month), never pruned.
    public function up(): void
    {
        if (! Schema::hasTable('status_monthly')) {
            Schema::create('status_monthly', function (Blueprint $table) {
                $table->id();
                $table->string('component', 64);
                $table->string('month', 7);
                $table->unsignedInteger('runs')->default(0);
                $table->unsignedInteger('degraded_runs')->default(0);
                $table->unsignedInteger('outage_runs')->default(0);
                $table->unsignedInteger('maintenance_runs')->default(0);
                $table->unique(['component', 'month']);
            });

            $this->backfill();
        }
    }

    /**
     * Months that status_daily still holds (90 days by default), so reports start with history.
     */
    private function backfill(): void
    {
        if (! Schema::hasTable('status_daily')) {
            return;
        }

        $hasMaintenance = Schema::hasColumn('status_daily', 'maintenance_runs');
        $months = [];

        foreach (DB::table('status_daily')->orderBy('id')->cursor() as $row) {
            $key = $row->component.'|'.substr((string) $row->day, 0, 7);
            $months[$key] ??= ['component' => $row->component, 'month' => substr((string) $row->day, 0, 7), 'runs' => 0, 'degraded_runs' => 0, 'outage_runs' => 0, 'maintenance_runs' => 0];
            $months[$key]['runs'] += (int) $row->runs;
            $months[$key]['degraded_runs'] += (int) $row->degraded_runs;
            $months[$key]['outage_runs'] += (int) $row->outage_runs;
            $months[$key]['maintenance_runs'] += $hasMaintenance ? (int) $row->maintenance_runs : 0;
        }

        foreach (array_chunk(array_values($months), 500) as $chunk) {
            DB::table('status_monthly')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_monthly');
    }
};
