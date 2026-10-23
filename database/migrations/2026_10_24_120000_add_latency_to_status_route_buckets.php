<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Requests per response-time bucket (≤ 50 ms, ≤ 100 ms, … , slower than 5 s): p50 / p95.
    private const COLUMNS = ['le_50', 'le_100', 'le_250', 'le_500', 'le_1000', 'le_2500', 'le_5000', 'le_inf'];

    public function up(): void
    {
        $missing = array_values(array_filter(self::COLUMNS, fn ($c) => ! Schema::hasColumn('status_route_buckets', $c)));

        if ($missing !== []) {
            Schema::table('status_route_buckets', function (Blueprint $table) use ($missing) {
                foreach ($missing as $column) {
                    $table->unsignedInteger($column)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('status_route_buckets', $c)));

        if ($present !== []) {
            Schema::table('status_route_buckets', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
