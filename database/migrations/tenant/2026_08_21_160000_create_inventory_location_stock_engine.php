<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_location_stocks')) {
            Schema::create('inventory_location_stocks', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('inventory_location_id')->index();
                $table->integer('product_id')->index();
                $table->integer('product_variant_id')->nullable()->index();
                // MySQL UNIQUE permits multiple NULL values. variant_key gives us a
                // deterministic uniqueness key: 0 = simple product, >0 = variant id.
                $table->integer('variant_key')->default(0);
                $table->decimal('quantity', 12, 3)->default(0);
                $table->decimal('reserved_quantity', 12, 3)->default(0);
                $table->boolean('manage_stock')->default(true);
                $table->timestamps(6);

                $table->unique(
                    ['inventory_location_id', 'product_id', 'variant_key'],
                    'inventory_location_stock_unique'
                );
                $table->index(['product_id', 'variant_key'], 'inventory_location_stock_product_variant');
            });
        }

        if (! Schema::hasTable('inventory_location_movements')) {
            Schema::create('inventory_location_movements', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('movement_type', 40)->index();
                $table->integer('product_id')->index();
                $table->integer('product_variant_id')->nullable()->index();
                $table->integer('from_inventory_location_id')->nullable()->index();
                $table->integer('to_inventory_location_id')->nullable()->index();
                $table->decimal('quantity', 12, 3);
                $table->integer('user_id')->nullable()->index();
                $table->string('reference_type', 80)->nullable()->index();
                $table->string('reference_id', 120)->nullable()->index();
                $table->string('idempotency_key', 120)->nullable()->unique();
                $table->string('idempotency_fingerprint', 64)->nullable()->index();
                $table->string('notes', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps(6);

                $table->index(
                    ['reference_type', 'reference_id'],
                    'inventory_location_movements_reference'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_location_movements');
        Schema::dropIfExists('inventory_location_stocks');
    }
};
