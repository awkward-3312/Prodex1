<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MS7-B1 — Admin Sale / Sale Return location-native (schema only).
 *
 * sales.inventory_location_id / sale_returns.inventory_location_id already
 * exist (2026_08_21_182000 / 2026_08_21_180000, built for the POS cutover).
 * This adds the ONE missing piece — the snapshot column — following exactly
 * the same pattern as purchases/purchase_returns
 * (2026_09_02_000000_add_inventory_location_to_purchases_and_returns):
 *
 * sales.inventory_effect_snapshot / sale_returns.inventory_effect_snapshot,
 * nullable JSON: for a location-native document (inventory_location_id NOT
 * NULL) holds the PHYSICAL PLAN, already converted to BASE unit, applied at
 * the last successful apply (revision-tracked). update/destroy revert THIS
 * historical snapshot and never reconstruct the quantity from the current
 * Unit. NULL => legacy document (product_warehouse still authoritative).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales', 'sale_returns'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'inventory_effect_snapshot')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->json('inventory_effect_snapshot')->nullable()->after('inventory_location_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['sales', 'sale_returns'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'inventory_effect_snapshot')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('inventory_effect_snapshot'));
            }
        }
    }
};
