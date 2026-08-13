<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen core-commerce money columns from decimal(15,2) to decimal(15,3) so that
 * 3-decimal prices (e.g. 0.066) are stored exactly instead of being rounded to
 * 2 decimals on save. Scale 3 holds existing 2-decimal values losslessly, so
 * this is safe regardless of the "Enable 3 Decimal Pricing" setting and needs
 * no data backfill.
 *
 * Scope: Products, Sales, and Sale details (core commerce). Percentage columns
 * (tax_rate) and loyalty-point columns (used_points/earned_points/points) are
 * intentionally left unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost', 15, 3)->change();
            $table->decimal('price', 15, 3)->change();
            $table->decimal('TaxNet', 15, 3)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 3)->nullable()->change();
            $table->decimal('wholesale_price', 15, 3)->change();
            $table->decimal('min_price', 15, 3)->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cost', 15, 3)->change();
            $table->decimal('price', 15, 3)->change();
            $table->decimal('wholesale', 15, 3)->nullable()->default(0)->change();
            $table->decimal('min_price', 15, 3)->nullable()->default(0)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('TaxNet', 15, 3)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 3)->nullable()->default(0)->change();
            $table->decimal('shipping', 15, 3)->nullable()->default(0)->change();
            $table->decimal('GrandTotal', 15, 3)->default(0)->change();
            $table->decimal('paid_amount', 15, 3)->default(0)->change();
            $table->decimal('discount_from_points', 15, 3)->default(0)->change();
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('price', 15, 3)->change();
            $table->decimal('TaxNet', 15, 3)->nullable()->change();
            $table->decimal('discount', 15, 3)->nullable()->change();
            $table->decimal('total', 15, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost', 15, 2)->change();
            $table->decimal('price', 15, 2)->change();
            $table->decimal('TaxNet', 15, 2)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 2)->nullable()->change();
            $table->decimal('wholesale_price', 15, 2)->change();
            $table->decimal('min_price', 15, 2)->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cost', 15, 2)->change();
            $table->decimal('price', 15, 2)->change();
            $table->decimal('wholesale', 15, 2)->nullable()->default(0)->change();
            $table->decimal('min_price', 15, 2)->nullable()->default(0)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('TaxNet', 15, 2)->nullable()->default(0)->change();
            $table->decimal('discount', 15, 2)->nullable()->default(0)->change();
            $table->decimal('shipping', 15, 2)->nullable()->default(0)->change();
            $table->decimal('GrandTotal', 15, 2)->default(0)->change();
            $table->decimal('paid_amount', 15, 2)->default(0)->change();
            $table->decimal('discount_from_points', 15, 2)->default(0)->change();
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('TaxNet', 15, 2)->nullable()->change();
            $table->decimal('discount', 15, 2)->nullable()->change();
            $table->decimal('total', 15, 2)->change();
        });
    }
};
