<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INCIDENT_COLUMNS = ['title', 'source', 'state', 'postmortem', 'created_by', 'reminded_at'];

    public function up(): void
    {
        // Incidents posted by hand, their timeline and a postmortem note.
        Schema::table('status_incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('status_incidents', 'title')) {
                $table->string('title', 200)->nullable();
            }
            if (! Schema::hasColumn('status_incidents', 'source')) {
                // auto: opened by status:check; manual: posted from the page.
                $table->string('source', 16)->default('auto');
            }
            if (! Schema::hasColumn('status_incidents', 'state')) {
                $table->string('state', 32)->nullable();
            }
            if (! Schema::hasColumn('status_incidents', 'postmortem')) {
                $table->text('postmortem')->nullable();
            }
            if (! Schema::hasColumn('status_incidents', 'created_by')) {
                $table->string('created_by', 191)->nullable();
            }
            if (! Schema::hasColumn('status_incidents', 'reminded_at')) {
                // Last "still down" reminder (alerts.reminder_minutes).
                $table->timestamp('reminded_at')->nullable();
            }
        });

        if (! Schema::hasTable('status_incident_updates')) {
            Schema::create('status_incident_updates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('status_incidents')->cascadeOnDelete();
                $table->string('state', 32);
                $table->text('message');
                $table->string('author', 191)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['incident_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_incident_updates');

        $columns = array_values(array_filter(self::INCIDENT_COLUMNS, fn ($c) => Schema::hasColumn('status_incidents', $c)));
        if ($columns !== []) {
            Schema::table('status_incidents', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
