<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Planned work: covered components show "Maintenance", raise no incident or alert, and
        // the time does not count against uptime.
        if (! Schema::hasTable('status_maintenances')) {
            Schema::create('status_maintenances', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                $table->text('message')->nullable();
                $table->json('components');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                // Set by status:check when it fires MaintenanceStarted / MaintenanceEnded.
                $table->timestamp('announced_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('created_by', 191)->nullable();
                $table->timestamps();
                $table->index(['starts_at', 'ends_at']);
            });
        }

        if (Schema::hasTable('status_daily') && ! Schema::hasColumn('status_daily', 'maintenance_runs')) {
            Schema::table('status_daily', function (Blueprint $table) {
                $table->unsignedInteger('maintenance_runs')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('status_daily', 'maintenance_runs')) {
            Schema::table('status_daily', function (Blueprint $table) {
                $table->dropColumn('maintenance_runs');
            });
        }

        Schema::dropIfExists('status_maintenances');
    }
};
