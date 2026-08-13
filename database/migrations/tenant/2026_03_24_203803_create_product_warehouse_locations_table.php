<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_warehouse_locations', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('product_id')->index('pwl_product_id');
            $table->integer('warehouse_id')->index('pwl_warehouse_id');
            $table->integer('warehouse_location_id')->nullable()->index('pwl_location_id');
            $table->timestamps(6);

            $table->unique(['product_id', 'warehouse_id'], 'pwl_product_wh_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_warehouse_locations');
    }
};
