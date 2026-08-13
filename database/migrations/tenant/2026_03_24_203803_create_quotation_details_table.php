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
        Schema::create('quotation_details', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->decimal('price', 15);
            $table->integer('sale_unit_id')->nullable()->index('sale_unit_id_quotation');
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->string('discount_method', 192)->nullable()->default('1');
            $table->decimal('total', 15);
            $table->decimal('quantity', 12, 3);
            $table->integer('product_id')->index('product_id_quotation_details');
            $table->integer('product_variant_id')->nullable()->index('quote_product_variant_id');
            $table->text('imei_number')->nullable();
            $table->integer('quotation_id')->index('quotation_id');
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_details');
    }
};
