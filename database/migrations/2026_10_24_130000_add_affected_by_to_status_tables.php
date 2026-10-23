<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The failing component another one depends on ('depends_on'), e.g. "database".
    public function up(): void
    {
        foreach (['status_components', 'status_incidents'] as $table) {
            if (! Schema::hasColumn($table, 'affected_by')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('affected_by', 64)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['status_components', 'status_incidents'] as $table) {
            if (Schema::hasColumn($table, 'affected_by')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('affected_by'));
            }
        }
    }
};
