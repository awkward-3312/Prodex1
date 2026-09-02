<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\product_warehouse;
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
 * CONTRATO EJECUTABLE: debe llamarse DENTRO de una transacción de negocio
 * (DB::transactionLevel() > 0). Si InventoryService falla, re-lanza y el caller
 * hace rollback de AMBAS escrituras.
 *
 * SOFT-DELETE: trabaja EXCLUSIVAMENTE con la fila product_warehouse ACTIVA
 * (deleted_at IS NULL) de la clave. Si no hay ninguna, crea una NUEVA activa a
 * qte=0 — nunca resucita una soft-deleted (provenance/legacyMap la ignoraría y
 * reaparecería la divergencia). Si hay >1 ACTIVAS => ValidationException (no
 * elige una arbitrariamente).
 *
 * ORDEN FIJO DE LOCKS (evitar carreras): Warehouse -> Product -> ProductVariant
 * (si aplica) -> product_warehouse activo de la clave -> default InventoryLocation
 * -> InventoryLocationStock -> writes. La default_inventory_location_id y los
 * flags is_batch_tracked/is_imei se leen de las filas BLOQUEADas.
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
     * @throws ValidationException  fuera de transacción; qty<=0; producto
     *         batch/IMEI con qty>0; almacén/producto/variante inválidos; >1 fila
     *         product_warehouse activa para la clave; sin ubicación principal apta.
     * @throws Throwable            fallo de InventoryService (el caller hace rollback).
     */
    public function applyOpeningStock(int $warehouseId, int $productId, ?int $variantId, float $qty, array $context = []): void
    {
        // (BLOCKER 3) contrato EJECUTABLE: siempre dentro de una transacción de
        // negocio — Product + product_warehouse + stock inicial + Adjustment/COGS
        // confirman o revierten JUNTOS.
        if (DB::transactionLevel() <= 0) {
            throw ValidationException::withMessages([
                'opening_stock' => 'OpeningStockInventoryService debe ejecutarse dentro de una transacción de negocio.',
            ]);
        }

        // (1) qty > 0.
        $qty = round((float) $qty, 3);
        if ($qty <= self::EPS) {
            throw ValidationException::withMessages([
                'opening_stock' => 'La cantidad de stock inicial debe ser mayor que cero.',
            ]);
        }

        $variantKey = (int) ($variantId ?: 0);

        // ---- ORDEN FIJO DE LOCKS ------------------------------------------

        // 1. Warehouse — la default_inventory_location_id se lee de ESTA fila
        //    bloqueada (no puede cambiar entre validación y write).
        $warehouse = DB::table('warehouses')
            ->where('id', $warehouseId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        if (! $warehouse) {
            throw ValidationException::withMessages([
                'opening_stock' => "El almacén {$warehouseId} no existe o está eliminado.",
            ]);
        }

        // 2. Product — is_batch_tracked / is_imei / deleted_at se leen de ESTA
        //    fila bloqueada. El lock además serializa dos opening-stock
        //    concurrentes de la misma product key cuando aún no existe
        //    product_warehouse, evitando dos filas activas separadas.
        $product = DB::table('products')
            ->where('id', $productId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        if (! $product) {
            throw ValidationException::withMessages([
                'opening_stock' => "El producto {$productId} no existe o está eliminado.",
            ]);
        }

        // 3. ProductVariant (si aplica): existe, no borrada, del mismo producto.
        if ($variantId !== null) {
            $variant = null;
            if (Schema::hasTable('product_variants')) {
                $variant = DB::table('product_variants')
                    ->where('id', $variantId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
            }
            if (! $variant || (int) $variant->product_id !== $productId) {
                throw ValidationException::withMessages([
                    'opening_stock' => "La variante {$variantId} no existe, está eliminada o no pertenece al producto {$productId}.",
                ]);
            }
        }

        // (G) batch / IMEI — desde la fila Product BLOQUEADA.
        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        if (($hasBatch && (int) ($product->is_batch_tracked ?? 0) === 1)
            || ($hasImei && (int) ($product->is_imei ?? 0) === 1)) {
            throw ValidationException::withMessages([
                'opening_stock' => 'El producto lleva control de lote o serie/IMEI: el stock inicial por cantidad no está soportado '
                    .'(no migra product_batch_location_stocks ni product_serials). Ingresa el inventario inicial mediante el flujo '
                    .'asistido de lotes/seriales.',
            ]);
        }

        // 4. product_warehouse ACTIVO de la clave (whereNull deleted_at), bajo
        //    lock. NUNCA se resucita una soft-deleted.
        $activeRows = DB::table('product_warehouse')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'))
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->get();

        if ($activeRows->count() > 1) {
            throw ValidationException::withMessages([
                'opening_stock' => 'Existen '.$activeRows->count().' filas product_warehouse ACTIVAS para la misma clave '
                    ."(warehouse {$warehouseId}, product {$productId}, variant ".($variantId ?? 'null').'). '
                    .'Requiere revisión manual antes de registrar stock inicial: no se elige una arbitrariamente.',
            ]);
        }

        if ($activeRows->count() === 1) {
            $pw = product_warehouse::whereKey($activeRows->first()->id)->lockForUpdate()->first();
        } else {
            // Ninguna activa: NUEVA fila activa a qte=0 (deleted_at = null).
            $pw = new product_warehouse;
            $pw->warehouse_id = $warehouseId;
            $pw->product_id = $productId;
            $pw->product_variant_id = $variantId;
            $pw->qte = 0;
            $pw->manage_stock = 1;
            $pw->saveQuietly();
            $pw = product_warehouse::whereKey($pw->getKey())->lockForUpdate()->first();
        }
        $legacyBefore = round((float) $pw->getRawOriginal('qte'), 3);

        // 5. default InventoryLocation — id LEÍDO de la fila Warehouse BLOQUEADA.
        $defaultLocationId = $warehouse->default_inventory_location_id
            ? (int) $warehouse->default_inventory_location_id
            : null;
        $location = $this->eligibleDefaultLocation($defaultLocationId, $warehouseId);
        // (H) sin default apta: ABORTAR. Nunca fallback a legacy-only, nunca
        //     elegir silenciosamente otra ubicación.
        if ($location === null) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'El almacén necesita una ubicación principal activa de tipo almacenamiento (no cuarentena, del propio almacén) '
                    .'para registrar stock inicial. Configura la ubicación principal del almacén antes de continuar.',
            ]);
        }

        // 6. inventory_location_stocks: materializar (a 0) si no existe, y lockForUpdate.
        InventoryLocationStock::firstOrCreate(
            ['inventory_location_id' => $location->id, 'product_id' => $productId, 'variant_key' => $variantKey],
            ['product_variant_id' => $variantId, 'quantity' => 0, 'reserved_quantity' => 0, 'manage_stock' => true]
        );
        InventoryLocationStock::where('inventory_location_id', $location->id)
            ->where('product_id', $productId)
            ->where('variant_key', $variantKey)
            ->lockForUpdate()
            ->first();

        // 7. writes ----------------------------------------------------------
        // legacy += qty  — saveQuietly: la escritura location la hace ESTE
        // servicio; no debe además dispararse un segundo mirror dual_write.
        $pw->setAttribute('qte', round($legacyBefore + $qty, 3));
        $pw->saveQuietly();

        // location += EXACTAMENTE el mismo qty. Si falla, re-lanza: el caller
        // envuelve todo en una transacción y hace rollback de ambas.
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
                        'product_warehouse_id' => (int) $pw->getKey(),
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
     * La default del almacén (id ya leído de la fila Warehouse bloqueada) sólo
     * sirve si: existe, no borrada, activa, del mismo almacén, type=storage, no
     * cuarentena. Se toma lockForUpdate para que no cambie durante la transacción.
     */
    private function eligibleDefaultLocation(?int $defaultLocationId, int $warehouseId): ?InventoryLocation
    {
        if (! $defaultLocationId) return null;

        $location = InventoryLocation::whereKey($defaultLocationId)
            ->lockForUpdate()
            ->first();

        if ($location === null) return null;
        if ($location->deleted_at !== null) return null;
        if (! (bool) $location->is_active) return null;
        if ((int) $location->warehouse_id !== $warehouseId) return null;
        if ($location->type !== InventoryLocation::TYPE_STORAGE) return null;
        if ($location->is_quarantine) return null;

        return $location;
    }
}
