<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_transition_states')) {
            Schema::create('inventory_transition_states', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('warehouse_id')->unique();
                $table->integer('inventory_location_id')->nullable()->index();
                $table->string('mode', 40)->default('legacy_only')->index();
                $table->string('status', 40)->default('pending')->index();
                $table->unsignedInteger('mismatch_count')->default(0);
                $table->timestamp('last_audited_at')->nullable();
                $table->timestamp('last_reconciled_at')->nullable();
                $table->timestamp('shadow_enabled_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps(6);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transition_states');
    }
};
