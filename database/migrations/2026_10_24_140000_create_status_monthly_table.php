<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_monthly');
    }
};
