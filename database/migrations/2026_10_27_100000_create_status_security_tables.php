<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suspicious activity findings (security.*).
        if (! Schema::hasTable('status_security_events')) {
            Schema::create('status_security_events', function (Blueprint $table) {
                $table->id();
                // request, login, signal or action.
                $table->string('category', 20);
                // The rule: auth_errors, scanner, failed_logins, new_login_country, signal_volume, …
                $table->string('type', 64);
                // The rule name of a request rule, the signal component or the action.
                $table->string('rule', 100)->nullable();
                $table->string('ip', 45)->nullable();
                // A masked account name, a user ("Sara #12") or the last scanned path.
                $table->string('subject', 191)->nullable();
                $table->string('country', 2)->nullable();
                $table->unsignedInteger('hits')->default(1);
                $table->unsignedInteger('window_minutes')->nullable();
                $table->unsignedInteger('max')->nullable();
                $table->json('details')->nullable();
                $table->timestamp('detected_at');
                $table->timestamp('last_seen_at');
                $table->timestamps();
                $table->index('detected_at');
                $table->index(['type', 'detected_at']);
                $table->index('ip');
            });
        }

        // IPs and countries each watched user signed in from (security.logins).
        if (! Schema::hasTable('status_security_logins')) {
            Schema::create('status_security_logins', function (Blueprint $table) {
                $table->id();
                $table->string('user_type', 191);
                $table->string('user_id', 64);
                $table->string('ip', 45);
                $table->string('country', 2)->nullable();
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->unique(['user_type', 'user_id', 'ip']);
                $table->index('last_seen_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_security_logins');
        Schema::dropIfExists('status_security_events');
    }
};
