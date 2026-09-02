<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MS1 — Compras / Devoluciones de compra location-native (schema inactivo).
 *
 * Mismo patrón que PR #81 (2026_08_31_000000_add_inventory_location_to_adjustments_and_damages):
 *
 * purchases.inventory_location_id / purchase_returns.inventory_location_id, nullable, index.
 *
 *   NULL      => documento LEGACY: store/update/destroy conservan la lógica
 *               histórica (product_warehouse + BatchService + SerialNumberService).
 *   NOT NULL  => documento location-native (a partir de MS2): NUNCA toca
 *               product_warehouse; los movimientos viven en
 *               inventory_location_stocks / inventory_location_movements.
 *
 * purchases.inventory_effect_snapshot / purchase_returns.inventory_effect_snapshot,
 * nullable JSON: para documentos location-native guarda el PLAN FÍSICO EXACTO
 * ya convertido a UNIDAD BASE aplicado en el CREATE. UPDATE/DESTROY revierten
 * ESE snapshot histórico y nunca reconstruyen la cantidad usando la Unit actual.
 * NULL => documento legacy.
 *
 * Sin columna de sucursal (se deriva del almacén cuando se necesite para
 * scope/reportes). Sin backfill: los documentos históricos quedan con
 * inventory_location_id NULL. Sin clave foránea (el patrón de Ajustes/Daños
 * tampoco la usa).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchases', 'purchase_returns'] as $table) {
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
        foreach (['purchases', 'purchase_returns'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Drop the auto-created index before its column. MySQL drops it
            // implicitly with the column; SQLite (test) errors unless it goes
            // first. Guarded so a missing / differently-named index is harmless.
            if (Schema::hasColumn($table, 'inventory_location_id')) {
                try {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($table.'_inventory_location_id_index'));
                } catch (\Throwable $e) {
                    // index absent or named otherwise — the column drop still runs.
                }
            }

            foreach (['inventory_effect_snapshot', 'inventory_location_id'] as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }
};
