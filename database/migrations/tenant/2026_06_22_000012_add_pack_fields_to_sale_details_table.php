<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Pack Selling: per-line snapshot of the selected pack on sale_details.
 *
 * All columns are nullable so existing sales and the feature-off path are
 * completely unaffected. The multiplier and name are snapshotted onto the line
 * so historical sales stay accurate even if the product's pack config later
 * changes. When product_pack_id is null the line is sold in the base unit, i.e.
 * exactly the current behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_details', 'product_pack_id')) {
                $table->unsignedBigInteger('product_pack_id')->nullable()->after('sale_unit_id');
            }
            if (! Schema::hasColumn('sale_details', 'pack_multiplier')) {
                $table->double('pack_multiplier')->nullable()->after('product_pack_id');
            }
            if (! Schema::hasColumn('sale_details', 'pack_name')) {
                $table->string('pack_name', 191)->nullable()->after('pack_multiplier');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            foreach (['product_pack_id', 'pack_multiplier', 'pack_name'] as $column) {
                if (Schema::hasColumn('sale_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
