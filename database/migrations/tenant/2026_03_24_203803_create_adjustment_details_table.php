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
        Schema::create('adjustment_details', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('product_id')->index('adjust_product_id');
            $table->integer('adjustment_id')->index('adjust_adjustment_id');
            $table->integer('product_variant_id')->nullable()->index('adjust_product_variant');
            $table->decimal('quantity', 12, 3);
            $table->string('type', 192);
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjustment_details');
    }
};
