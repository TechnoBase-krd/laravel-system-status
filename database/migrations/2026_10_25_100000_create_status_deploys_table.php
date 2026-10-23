<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deploy markers (status:deploy or POST {path}/deploys).
        if (! Schema::hasTable('status_deploys')) {
            Schema::create('status_deploys', function (Blueprint $table) {
                $table->id();
                $table->string('version', 100);
                $table->string('commit', 64)->nullable();
                $table->string('note', 500)->nullable();
                $table->string('deployed_by', 191)->nullable();
                $table->timestamp('deployed_at');
                // Set when the before/after comparison ran (deploys.compare).
                $table->timestamp('compared_at')->nullable();
                $table->json('comparison')->nullable();
                $table->timestamps();
                $table->index('deployed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('status_deploys');
    }
};
