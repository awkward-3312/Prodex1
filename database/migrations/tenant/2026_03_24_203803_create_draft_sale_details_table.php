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
        Schema::create('draft_sale_details', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->date('date');
            $table->integer('draft_sale_id')->index('draft_sale_details_draft_sale_id');
            $table->integer('product_id')->index('draft_sale_details_product_id');
            $table->integer('product_variant_id')->nullable()->index('draft_sale_details_product_variant_id');
            $table->text('imei_number')->nullable();
            $table->decimal('price', 15);
            $table->integer('sale_unit_id')->nullable()->index('draft_sale_details_sale_unit_id');
            $table->decimal('TaxNet', 15)->nullable();
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable();
            $table->string('discount_method', 192)->nullable()->default('1');
            $table->string('price_type', 32)->default('retail');
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
        Schema::dropIfExists('draft_sale_details');
    }
};
