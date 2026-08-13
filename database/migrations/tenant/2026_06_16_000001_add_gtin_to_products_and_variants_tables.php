<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a standardized barcode identifier (GTIN / UPC / EAN / ISBN) to products
 * and product variants. This is distinct from the internal `code` (SKU) and the
 * `Type_barcode` symbology. Maps to WooCommerce's native `global_unique_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'gtin')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('gtin', 64)->nullable()->after('code')->index();
            });
        }

        if (! Schema::hasColumn('product_variants', 'gtin')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->string('gtin', 64)->nullable()->after('code')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'gtin')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['gtin']);
                $table->dropColumn('gtin');
            });
        }

        if (Schema::hasColumn('product_variants', 'gtin')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropIndex(['gtin']);
                $table->dropColumn('gtin');
            });
        }
    }
};
