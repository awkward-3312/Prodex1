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
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->foreign(['product_id'])->references(['id'])->on('products')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign(['purchase_id'])->references(['id'])->on('purchases')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['product_variant_id'])->references(['id'])->on('product_variants')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['purchase_unit_id'])->references(['id'])->on('units')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropForeign('purchase_details_product_id_foreign');
            $table->dropForeign('purchase_details_purchase_id_foreign');
            $table->dropForeign('purchase_details_product_variant_id_foreign');
            $table->dropForeign('purchase_details_purchase_unit_id_foreign');
        });
    }
};
