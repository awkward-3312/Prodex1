<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\product_warehouse;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Punto ÚNICO de entrada para STOCK INICIAL (opening stock).
 *
 * Cierra el origen del caso Iphone X: un producto/stock inicial NUNCA puede
 * volver a quedar con product_warehouse > 0 e inventory_location_stocks = 0.
 * Toda ruta que introduzca cantidad inicial (>0) DEBE pasar por aquí:
 *   - ProductsController::store (producto simple)
 *   - opening_stock_import_single
 *   - opening_stock_import_variants
 *
 * Escribe la MISMA cantidad, de forma ATÓMICA, en:
 *   - legacy: product_warehouse.qte  (+= qty, saveQuietly — sin disparar el
 *     mirror dual_write del saved hook, porque la escritura location la hace
 *     este servicio explícitamente);
 *   - motor por ubicación: inventory_location_stocks (InventoryService::increase,
 *     nunca adjustTo).
 *
 * DEBE llamarse DENTRO de la transacción de negocio del caller: si Inventory
 * service falla, re-lanza y el caller hace rollback de AMBAS escrituras.
 *
 * NO cubre en este PR: batch/serial (is_batch_tracked / is_imei con qty>0 se
 * RECHAZAN); Purchases/Sales/Adjustments/Damages (roadmap posterior).
 */
class OpeningStockInventoryService
{
    /**
     * Movimiento de reconciliación legacy→location POR CLAVE (product+variant),
     * igual que backfill / incremental reconciliation. Se registra en
     * InventoryProvenanceAuditService::RECONCILIATION_REFS: suma
     * baseline_quantity de su clave, queda fuera de post_baseline_native_net y
     * NO mueve el baseline temporal global ni last_reconciled_at.
     */
    public const REFERENCE_TYPE = 'legacy_product_warehouse_opening_stock_sync';

    private const EPS = 0.0005;

    /**
     * Aplica `qty` de stock inicial para (warehouse, product, variant).
     *
     * @param  array{user_id?:int|null,source?:string}  $context
     *
     * @throws ValidationException  qty<=0; producto batch/IMEI con qty>0; sin
     *         ubicación principal apta; ubicación destino inelegible.
     * @throws Throwable            fallo de InventoryService (el caller hace rollback).
     */
    public function applyOpeningStock(int $warehouseId, int $productId, ?int $variantId, float $qty, array $context = []): void
    {
        // (1) qty > 0.
        $qty = round((float) $qty, 3);
        if ($qty <= self::EPS) {
            throw ValidationException::withMessages([
                'opening_stock' => 'La cantidad de stock inicial debe ser mayor que cero.',
            ]);
        }

        // (G) batch / IMEI: opening stock quantity-only NO puede crear stock sin
        // los artifacts completos (product_batch_location_stocks / product_serials).
        if ($this->productIsArtifactTracked($productId)) {
            throw ValidationException::withMessages([
                'opening_stock' => 'El producto lleva control de lote o serie/IMEI: el stock inicial por cantidad no está soportado '
                    .'(no migra product_batch_location_stocks ni product_serials). Ingresa el inventario inicial mediante el flujo '
                    .'asistido de lotes/seriales.',
            ]);
        }

        $variantKey = (int) ($variantId ?: 0);

        // (2) product_warehouse: materializar (a 0) si no existe, y lockForUpdate.
        $seed = product_warehouse::firstOrNew([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
        ]);
        if (! $seed->exists) {
            $seed->manage_stock = 1;
            $seed->qte = 0;
            $seed->saveQuietly(); // fila semilla a 0 — sin disparar hooks/mirror
        }
        $locked = product_warehouse::whereKey($seed->getKey())->lockForUpdate()->first();
        $legacyBefore = round((float) $locked->getRawOriginal('qte'), 3);

        // (3)(4) ubicación principal del almacén — DEBE ser apta.
        $warehouse = Warehouse::whereNull('deleted_at')->whereKey($warehouseId)->firstOrFail();
        $location = $this->eligibleDefaultLocation($warehouse);
        // (H) sin default apta: ABORTAR. Nunca fallback a legacy-only, nunca
        //     elegir silenciosamente otra ubicación.
        if ($location === null) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'El almacén necesita una ubicación principal activa de tipo almacenamiento (no cuarentena, del propio almacén) '
                    .'para registrar stock inicial. Configura la ubicación principal del almacén antes de continuar.',
            ]);
        }

        // (5) inventory_location_stocks: materializar (a 0) si no existe, y lockForUpdate.
        InventoryLocationStock::firstOrCreate(
            ['inventory_location_id' => $location->id, 'product_id' => $productId, 'variant_key' => $variantKey],
            ['product_variant_id' => $variantId, 'quantity' => 0, 'reserved_quantity' => 0, 'manage_stock' => true]
        );
        InventoryLocationStock::where('inventory_location_id', $location->id)
            ->where('product_id', $productId)
            ->where('variant_key', $variantKey)
            ->lockForUpdate()
            ->first();

        // (6) legacy += qty  — saveQuietly: la escritura location la hace ESTE
        //     servicio; no debe además dispararse un segundo mirror dual_write.
        $locked->setAttribute('qte', round($legacyBefore + $qty, 3));
        $locked->saveQuietly();

        // (7)(8) location += EXACTAMENTE el mismo qty. Si falla, re-lanza: el
        //        caller envuelve todo en una transacción y hace rollback de ambas.
        try {
            app(InventoryService::class)->increase(
                $location->id,
                $productId,
                $qty,
                $variantId,
                [
                    'user_id' => $context['user_id'] ?? (function_exists('auth') ? auth()->id() : null),
                    'reference_type' => self::REFERENCE_TYPE,
                    'reference_id' => $warehouseId.':'.$productId.':'.$variantKey,
                    'notes' => 'Stock inicial: sincronización atómica legacy → ubicación principal.',
                    'metadata' => [
                        'operation' => 'opening_stock',
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'qty' => $qty,
                        'legacy_before' => $legacyBefore,
                        'source' => $context['source'] ?? null,
                    ],
                ]
            );
        } catch (Throwable $e) {
            // Atomicidad: el caller (DB::transaction) revierte el legacy += qty.
            throw $e;
        }
    }

    /**
     * La default del almacén sólo sirve si: existe, no borrada, activa, del
     * mismo almacén, type=storage, no cuarentena. Se toma lockForUpdate para que
     * no cambie durante la transacción.
     */
    private function eligibleDefaultLocation(Warehouse $warehouse): ?InventoryLocation
    {
        if (! $warehouse->default_inventory_location_id) return null;

        $location = InventoryLocation::whereKey($warehouse->default_inventory_location_id)
            ->lockForUpdate()
            ->first();

        if ($location === null) return null;
        if ($location->deleted_at !== null) return null;
        if (! (bool) $location->is_active) return null;
        if ((int) $location->warehouse_id !== (int) $warehouse->id) return null;
        if ($location->type !== InventoryLocation::TYPE_STORAGE) return null;
        if ($location->is_quarantine) return null;

        return $location;
    }

    /** ¿El producto lleva control de lote o serie/IMEI? (schema-guarded). */
    private function productIsArtifactTracked(int $productId): bool
    {
        if (! Schema::hasTable('products')) return false;

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        if (! $hasBatch && ! $hasImei) return false;

        $row = DB::table('products')->where('id', $productId)->whereNull('deleted_at')
            ->first(array_merge($hasBatch ? ['is_batch_tracked'] : [], $hasImei ? ['is_imei'] : []));
        if (! $row) return false;

        return ($hasBatch && (int) ($row->is_batch_tracked ?? 0) === 1)
            || ($hasImei && (int) ($row->is_imei ?? 0) === 1);
    }
}
