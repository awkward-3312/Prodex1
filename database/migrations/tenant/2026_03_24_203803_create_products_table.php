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
        Schema::create('products', function (Blueprint $table) {
            $table->collation = 'utf8mb4_unicode_ci';
            $table->charset = 'utf8mb4';

            $table->integer('id', true);
            $table->unsignedBigInteger('woocommerce_id')->nullable();
            $table->boolean('woocommerce_missing_sku')->default(false)->index();
            $table->string('type');
            $table->integer('warranty_period')->nullable();
            $table->string('warranty_unit')->nullable();
            $table->text('warranty_terms')->nullable();
            $table->boolean('has_guarantee')->default(false);
            $table->integer('guarantee_period')->nullable();
            $table->string('guarantee_unit')->nullable();
            $table->string('code', 192);
            $table->decimal('points', 15)->default(0);
            $table->string('Type_barcode', 192);
            $table->string('name', 192);
            $table->decimal('cost', 15);
            $table->decimal('price', 15);
            $table->decimal('wholesale_price', 15);
            $table->decimal('min_price', 15);
            $table->integer('category_id')->index('category_id');
            $table->integer('sub_category_id')->nullable()->index('sub_category_id');
            $table->integer('brand_id')->nullable()->index('brand_id_products');
            $table->integer('unit_id')->nullable()->index('unit_id_products');
            $table->integer('unit_sale_id')->nullable()->index('unit_id_sales');
            $table->integer('unit_purchase_id')->nullable()->index('unit_purchase_products');
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable();
            $table->string('discount_method', 192)->nullable()->default('1');
            $table->text('image')->nullable();
            $table->text('note')->nullable();
            $table->decimal('stock_alert', 15)->nullable()->default(0);
            $table->decimal('weight', 15)->nullable();
            $table->double('length')->nullable();
            $table->double('width')->nullable();
            $table->double('height')->nullable();
            $table->boolean('is_variant')->default(false);
            $table->boolean('is_imei')->default(false);
            $table->boolean('not_selling')->default(false);
            $table->boolean('is_active')->nullable()->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('hide_from_online_store')->default(false);
            $table->string('quickbooks_id', 64)->nullable()->index();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
