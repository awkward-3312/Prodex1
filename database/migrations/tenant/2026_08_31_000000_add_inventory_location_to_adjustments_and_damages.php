<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #81 — Ajustes y Daños location-aware.
 *
 * adjustments.inventory_location_id / damages.inventory_location_id, nullable.
 *
 *   NULL      => registro LEGACY (anterior a #81): update/destroy conservan la
 *               lógica histórica (product_warehouse + BatchService).
 *   NOT NULL  => registro creado por el flujo location-aware: NUNCA toca
 *               product_warehouse; los movimientos viven en
 *               inventory_location_stocks / inventory_location_movements.
 *
 * NO se migran registros viejos ni se adivina su ubicación histórica.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['adjustments', 'damages'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'inventory_location_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->integer('inventory_location_id')->nullable()->index()->after('warehouse_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['adjustments', 'damages'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'inventory_location_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('inventory_location_id');
                });
            }
        }
    }
};
