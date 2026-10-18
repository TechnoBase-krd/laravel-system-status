<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Current (confirmed) status of each System Status component.
        Schema::create('status_components', function (Blueprint $table) {
            $table->id();
            $table->string('component', 64)->unique();
            $table->string('status', 32)->default('operational');
            $table->string('candidate_status', 32)->nullable();
            $table->unsignedSmallInteger('candidate_runs')->default(0);
            $table->string('message', 500)->nullable();
            // false while every probe of the component is skipped (integration disabled or not set up).
            $table->boolean('configured')->default(true);
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        // One row per probe run. A null status means the probe was skipped (not configured).
        Schema::create('status_checks', function (Blueprint $table) {
            $table->id();
            $table->string('component', 64);
            $table->string('probe', 128);
            $table->string('status', 32)->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('checked_at');
            $table->index(['component', 'probe', 'checked_at']);
            $table->index('checked_at');
        });

        // Daily rollup of the confirmed status, one row per component and day (the 90-day bars).
        Schema::create('status_daily', function (Blueprint $table) {
            $table->id();
            $table->string('component', 64);
            $table->date('day');
            $table->unsignedInteger('runs')->default(0);
            $table->unsignedInteger('degraded_runs')->default(0);
            $table->unsignedInteger('outage_runs')->default(0);
            $table->unique(['component', 'day']);
        });

        // Real traffic and code signals in 5-minute buckets. route is "METHOD uri" or "(signal)".
        Schema::create('status_route_buckets', function (Blueprint $table) {
            $table->id();
            $table->string('component', 64);
            $table->string('route', 255);
            $table->timestamp('bucket_at');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('slow')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unique(['component', 'route', 'bucket_at']);
            $table->index('bucket_at');
        });

        // Opened and resolved automatically by status:check.
        Schema::create('status_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('component', 64);
            $table->string('severity', 32);
            $table->string('message', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['component', 'resolved_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_incidents');
        Schema::dropIfExists('status_route_buckets');
        Schema::dropIfExists('status_daily');
        Schema::dropIfExists('status_checks');
        Schema::dropIfExists('status_components');
    }
};
