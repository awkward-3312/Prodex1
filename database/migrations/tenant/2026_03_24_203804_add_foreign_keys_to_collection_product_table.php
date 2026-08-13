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
        Schema::table('collection_product', function (Blueprint $table) {
            $table->foreign(['collection_id'])->references(['id'])->on('collections')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['product_id'])->references(['id'])->on('products')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_product', function (Blueprint $table) {
            $table->dropForeign('collection_product_collection_id_foreign');
            $table->dropForeign('collection_product_product_id_foreign');
        });
    }
};
