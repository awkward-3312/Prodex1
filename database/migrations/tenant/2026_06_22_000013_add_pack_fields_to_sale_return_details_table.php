<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Pack Selling: per-line snapshot of the selected pack on
 * sale_return_details. Mirrors the sale_details columns so a return can restock
 * the same number of base units that the original sale removed. All columns are
 * nullable for full backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_return_details', 'product_pack_id')) {
                $table->unsignedBigInteger('product_pack_id')->nullable()->after('sale_unit_id');
            }
            if (! Schema::hasColumn('sale_return_details', 'pack_multiplier')) {
                $table->double('pack_multiplier')->nullable()->after('product_pack_id');
            }
            if (! Schema::hasColumn('sale_return_details', 'pack_name')) {
                $table->string('pack_name', 191)->nullable()->after('pack_multiplier');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            foreach (['product_pack_id', 'pack_multiplier', 'pack_name'] as $column) {
                if (Schema::hasColumn('sale_return_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
