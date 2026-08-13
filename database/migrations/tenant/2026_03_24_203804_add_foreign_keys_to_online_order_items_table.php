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
        Schema::table('online_order_items', function (Blueprint $table) {
            $table->foreign(['order_id'])->references(['id'])->on('online_orders')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['product_id'])->references(['id'])->on('products')->onUpdate('restrict')->onDelete('set null');
            $table->foreign(['product_variant_id'])->references(['id'])->on('product_variants')->onUpdate('restrict')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('online_order_items', function (Blueprint $table) {
            $table->dropForeign('online_order_items_order_id_foreign');
            $table->dropForeign('online_order_items_product_id_foreign');
            $table->dropForeign('online_order_items_product_variant_id_foreign');
        });
    }
};
