<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many-to-many pivot for promotions limited to specific products.
     * No DB-level foreign keys; cleanup is at the application layer.
     */
    public function up(): void
    {
        Schema::create('promotion_products', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id')->index();
            $table->integer('product_id')->index();
            $table->timestamps(6);

            $table->unique(['promotion_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('promotion_products');
    }
};
