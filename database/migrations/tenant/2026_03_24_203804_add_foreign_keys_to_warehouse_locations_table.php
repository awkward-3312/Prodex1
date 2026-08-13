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
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->foreign(['warehouse_id'])->references(['id'])->on('warehouses')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->dropForeign('warehouse_locations_warehouse_id_foreign');
        });
    }
};
