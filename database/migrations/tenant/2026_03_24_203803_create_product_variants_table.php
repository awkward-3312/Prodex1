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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('product_id')->nullable()->index('product_id_variant');
            $table->string('name', 192)->nullable();
            $table->decimal('cost', 15);
            $table->decimal('price', 15);
            $table->decimal('wholesale', 15)->nullable()->default(0);
            $table->decimal('min_price', 15)->nullable()->default(0);
            $table->string('code', 192);
            $table->string('image')->default('no-image.png');
            $table->decimal('qty')->nullable()->default(0);
            $table->timestamps(6);
            $table->softDeletes();
            $table->unsignedBigInteger('woocommerce_variation_id')->nullable()->index('pv_woocommerce_variation_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
