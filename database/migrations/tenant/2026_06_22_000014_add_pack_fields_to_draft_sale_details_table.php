<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Pack Selling: per-line snapshot of the selected pack on
 * draft_sale_details, so a POS cart saved as a draft keeps its pack selection
 * when reloaded. All columns are nullable for full backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('draft_sale_details')) {
            return;
        }

        Schema::table('draft_sale_details', function (Blueprint $table) {
            if (! Schema::hasColumn('draft_sale_details', 'product_pack_id')) {
                $table->unsignedBigInteger('product_pack_id')->nullable()->after('sale_unit_id');
            }
            if (! Schema::hasColumn('draft_sale_details', 'pack_multiplier')) {
                $table->double('pack_multiplier')->nullable()->after('product_pack_id');
            }
            if (! Schema::hasColumn('draft_sale_details', 'pack_name')) {
                $table->string('pack_name', 191)->nullable()->after('pack_multiplier');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('draft_sale_details')) {
            return;
        }

        Schema::table('draft_sale_details', function (Blueprint $table) {
            foreach (['product_pack_id', 'pack_multiplier', 'pack_name'] as $column) {
                if (Schema::hasColumn('draft_sale_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
