<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_batch_location_movements')) return;

        Schema::create('product_batch_location_movements', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('product_batch_id')->index();
            $table->integer('from_inventory_location_id')->nullable()->index('batch_loc_moves_from_location_idx');
            $table->integer('to_inventory_location_id')->nullable()->index();
            $table->decimal('quantity', 12, 3);
            $table->integer('user_id')->nullable()->index();
            $table->string('reference_type', 80)->nullable()->index();
            $table->string('reference_id', 120)->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('notes', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->index(['reference_type', 'reference_id'], 'batch_location_move_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batch_location_movements');
    }
};
