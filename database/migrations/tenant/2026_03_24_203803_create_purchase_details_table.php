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
        Schema::create('purchase_details', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->decimal('cost', 15);
            $table->integer('purchase_unit_id')->nullable()->index('purchase_unit_id_purchase');
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->string('discount_method', 192)->nullable()->default('1');
            $table->integer('purchase_id')->index('purchase_id');
            $table->integer('product_id')->index('product_id');
            $table->integer('product_variant_id')->nullable()->index('purchase_product_variant_id');
            $table->text('imei_number')->nullable();
            $table->decimal('total', 15);
            $table->decimal('quantity', 12, 3);
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_details');
    }
};
