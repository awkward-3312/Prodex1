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
        Schema::table('products', function (Blueprint $table) {
            $table->foreign(['brand_id'])->references(['id'])->on('brands')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['category_id'])->references(['id'])->on('categories')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['sub_category_id'])->references(['id'])->on('subcategories')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['unit_id'])->references(['id'])->on('units')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['unit_sale_id'])->references(['id'])->on('units')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['unit_purchase_id'])->references(['id'])->on('units')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_brand_id_foreign');
            $table->dropForeign('products_category_id_foreign');
            $table->dropForeign('products_sub_category_id_foreign');
            $table->dropForeign('products_unit_id_foreign');
            $table->dropForeign('products_unit_sale_id_foreign');
            $table->dropForeign('products_unit_purchase_id_foreign');
        });
    }
};
