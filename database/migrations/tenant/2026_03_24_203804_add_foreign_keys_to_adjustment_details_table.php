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
        Schema::table('adjustment_details', function (Blueprint $table) {
            $table->foreign(['adjustment_id'])->references(['id'])->on('adjustments')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['product_id'])->references(['id'])->on('products')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['product_variant_id'])->references(['id'])->on('product_variants')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adjustment_details', function (Blueprint $table) {
            $table->dropForeign('adjustment_details_adjustment_id_foreign');
            $table->dropForeign('adjustment_details_product_id_foreign');
            $table->dropForeign('adjustment_details_product_variant_id_foreign');
        });
    }
};
