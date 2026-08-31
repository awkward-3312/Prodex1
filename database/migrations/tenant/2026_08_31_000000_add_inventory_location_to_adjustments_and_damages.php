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
 * adjustments.inventory_effect_snapshot / damages.inventory_effect_snapshot,
 * nullable JSON: para registros location-aware guarda el PLAN FÍSICO EXACTO ya
 * EXPANDIDO (componentes de combo incluidos) aplicado en el CREATE. UPDATE y
 * DESTROY revierten ESE snapshot histórico y nunca reconstruyen desde la
 * composición actual del combo. NULL => registro legacy.
 *
 * NO se migran registros viejos ni se adivina su ubicación histórica.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['adjustments', 'damages'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'inventory_location_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->integer('inventory_location_id')->nullable()->index()->after('warehouse_id');
                });
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
        foreach (['adjustments', 'damages'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['inventory_effect_snapshot', 'inventory_location_id'] as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }
};
