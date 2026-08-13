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
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreign(['provider_id'])->references(['id'])->on('providers')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['purchase_id'])->references(['id'])->on('purchases')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['warehouse_id'])->references(['id'])->on('warehouses')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign('purchase_returns_provider_id_foreign');
            $table->dropForeign('purchase_returns_purchase_id_foreign');
            $table->dropForeign('purchase_returns_user_id_foreign');
            $table->dropForeign('purchase_returns_warehouse_id_foreign');
        });
    }
};
