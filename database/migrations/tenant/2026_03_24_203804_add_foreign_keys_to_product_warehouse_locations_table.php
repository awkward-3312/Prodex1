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
        Schema::table('product_warehouse_locations', function (Blueprint $table) {
            $table->foreign(['warehouse_location_id'])->references(['id'])->on('warehouse_locations')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['product_id'])->references(['id'])->on('products')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['warehouse_id'])->references(['id'])->on('warehouses')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_warehouse_locations', function (Blueprint $table) {
            $table->dropForeign('product_warehouse_locations_warehouse_location_id_foreign');
            $table->dropForeign('product_warehouse_locations_product_id_foreign');
            $table->dropForeign('product_warehouse_locations_warehouse_id_foreign');
        });
    }
};
