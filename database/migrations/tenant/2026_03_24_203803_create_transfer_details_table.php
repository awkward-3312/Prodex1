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
        Schema::create('transfer_details', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->integer('transfer_id')->index('transfer_id');
            $table->integer('product_id')->index('product_id_transfers');
            $table->integer('product_variant_id')->nullable()->index('product_variant_id_transfer');
            $table->decimal('cost', 15);
            $table->integer('purchase_unit_id')->nullable()->index('unit_sale_id_transfer');
            $table->decimal('TaxNet', 15)->nullable();
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable();
            $table->string('discount_method', 192)->nullable()->default('1');
            $table->decimal('quantity', 12, 3);
            $table->decimal('total', 15);
            $table->timestamps(6);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_details');
    }
};
