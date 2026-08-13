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
        Schema::create('online_order_items', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('order_id')->index();
            $table->integer('product_id')->nullable()->index();
            $table->integer('product_variant_id')->nullable()->index();
            $table->float('TaxNet')->nullable();
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->float('discount')->nullable();
            $table->string('discount_method', 192)->nullable()->default('1');
            $table->decimal('qty', 12, 3)->default(1);
            $table->decimal('price', 15)->default(0);
            $table->decimal('line_total', 15)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('online_order_items');
    }
};
