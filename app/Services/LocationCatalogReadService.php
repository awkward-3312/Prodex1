<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de productos/variantes para el flujo location-aware de Ajustes y
 * Daños (PR #81, BLOCKER 3).
 *
 * NO reutiliza PosLocationCatalogController (exige Sales_pos, is_sellable). Los
 * Ajustes/Daños operan sobre cualquier inventory_location del almacén
 * (storage / quarantine / etc.).
 *
 * Devuelve TODOS los productos con manage_stock (incl. combos) — también los que
 * tienen 0 de stock, porque un Adjustment ADD puede crear existencia desde cero.
 * Las cantidades vienen de inventory_location_stocks de LA ubicación pedida:
 *   available_quantity = physical_quantity - reserved_quantity
 *   stock_source = "inventory_location"
 */
class LocationCatalogReadService
{
    /**
     * @return array{
     *   inventory_location_id:int, warehouse_id:int,
     *   products: array<int,array{
     *     product_id:int, product_variant_id:?int, code:string, name:string,
     *     product_type:string, unit:?string, is_batch_tracked:bool, is_imei:bool,
     *     physical_quantity:float, reserved_quantity:float, available_quantity:float,
     *     stock_source:string
     *   }>
     * }
     */
    public function forLocation(int $locationId): array
    {
        $location = DB::table('inventory_locations')->where('id', $locationId)->whereNull('deleted_at')->first();
        if (! $location) {
            return ['inventory_location_id' => $locationId, 'warehouse_id' => 0, 'products' => []];
        }

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        $hasUnit = Schema::hasTable('units') && Schema::hasColumn('products', 'unit_id');

        $stockByKey = [];
        foreach (DB::table('inventory_location_stocks')->where('inventory_location_id', $locationId)->get() as $s) {
            $stockByKey[((int) $s->product_id).':'.((int) ($s->variant_key ?? ($s->product_variant_id ?: 0)))] = [
                'physical' => round((float) $s->quantity, 3),
                'reserved' => round((float) $s->reserved_quantity, 3),
            ];
        }

        $productQ = DB::table('products')->whereNull('deleted_at');
        // Sólo productos con inventario relevante (no servicios).
        if (Schema::hasColumn('products', 'type')) {
            $productQ->whereIn('type', ['is_single', 'is_variant', 'is_combo']);
        }
        $products = $productQ->orderBy('id')->get();

        $units = [];
        if ($hasUnit) {
            foreach (DB::table('units')->get(['id', 'ShortName', 'name']) as $u) {
                $units[(int) $u->id] = $u->ShortName ?: $u->name;
            }
        }

        $variantsByProduct = [];
        if (Schema::hasTable('product_variants')) {
            foreach (DB::table('product_variants')->whereNull('deleted_at')->orderBy('id')->get(['id', 'product_id', 'name', 'code']) as $v) {
                $variantsByProduct[(int) $v->product_id][] = $v;
            }
        }

        $rows = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            $unit = $hasUnit && isset($p->unit_id) ? ($units[(int) $p->unit_id] ?? null) : null;
            $batch = $hasBatch ? ((int) ($p->is_batch_tracked ?? 0) === 1) : false;
            $imei = $hasImei ? ((int) ($p->is_imei ?? 0) === 1) : false;

            $isVariant = ($p->type ?? 'is_single') === 'is_variant' || ! empty($variantsByProduct[$pid]);
            if ($isVariant && ! empty($variantsByProduct[$pid])) {
                foreach ($variantsByProduct[$pid] as $v) {
                    $rows[] = $this->row($pid, (int) $v->id, $v->code ?: $p->code, '['.$v->name.'] '.$p->name, (string) ($p->type ?? 'is_variant'), $unit, $batch, $imei, $stockByKey[$pid.':'.((int) $v->id)] ?? null);
                }
            } else {
                $rows[] = $this->row($pid, null, (string) $p->code, (string) $p->name, (string) ($p->type ?? 'is_single'), $unit, $batch, $imei, $stockByKey[$pid.':0'] ?? null);
            }
        }

        return [
            'inventory_location_id' => $locationId,
            'warehouse_id' => (int) $location->warehouse_id,
            'products' => $rows,
        ];
    }

    private function row(int $productId, ?int $variantId, string $code, string $name, string $type, ?string $unit, bool $batch, bool $imei, ?array $stock): array
    {
        $physical = $stock['physical'] ?? 0.0;
        $reserved = $stock['reserved'] ?? 0.0;

        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'code' => $code,
            'name' => $name,
            'product_type' => $type,
            'unit' => $unit,
            'is_batch_tracked' => $batch,
            'is_imei' => $imei,
            'physical_quantity' => $physical,
            'reserved_quantity' => $reserved,
            'available_quantity' => round($physical - $reserved, 3),
            'stock_source' => 'inventory_location',
        ];
    }
}
