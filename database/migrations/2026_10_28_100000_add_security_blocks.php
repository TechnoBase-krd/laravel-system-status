<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // warning, or critical when an attack may have worked (a scanned file was served, a
        // payload caused a server error, a honeypot or canary was touched, impossible travel).
        if (Schema::hasTable('status_security_events') && ! Schema::hasColumn('status_security_events', 'severity')) {
            Schema::table('status_security_events', function (Blueprint $table) {
                $table->string('severity', 10)->default('warning')->after('type');
            });
        }

        // IPs blocked by security.block (auto-block), with the Cloudflare rule when one was made.
        if (! Schema::hasTable('status_security_blocks')) {
            Schema::create('status_security_blocks', function (Blueprint $table) {
                $table->id();
                $table->string('ip', 45)->unique();
                $table->foreignId('event_id')->nullable();
                $table->string('reason', 191)->nullable();
                $table->timestamp('blocked_until');
                $table->string('cloudflare_rule_id', 64)->nullable();
                $table->timestamps();
                $table->index('blocked_until');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_security_blocks');

        if (Schema::hasTable('status_security_events') && Schema::hasColumn('status_security_events', 'severity')) {
            Schema::table('status_security_events', function (Blueprint $table) {
                $table->dropColumn('severity');
            });
        }
    }
};
