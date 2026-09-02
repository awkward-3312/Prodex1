<?php

namespace App\Services;

use App\Models\InventoryLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Motor location-native para RECEPCIÓN DE COMPRA y DEVOLUCIÓN DE COMPRA (MS1).
 *
 * Un único núcleo para ambos documentos:
 *
 *   PURCHASE (recepción)        → stock +   (delta > 0)
 *   PURCHASE_RETURN (a proveedor) → stock − (delta < 0)
 *
 * Aplica y revierte el efecto físico SOBRE UNA inventory_location_id de cabecera
 * (una ubicación por documento — la redistribución entre ubicaciones se hace con
 * movimientos internos), EXCLUSIVAMENTE vía InventoryService increase/decrease.
 *
 * NUNCA toca el modelo/tabla legacy de existencias por almacén, ni el mirror
 * de transición dual-write, ni la sincronización de snapshots legacy. Es un
 * writer location-native puro: sólo inventory_location_stocks /
 * inventory_location_movements vía InventoryService.increase / decrease.
 *
 * SNAPSHOT (versionado): buildSnapshot() congela el PLAN FÍSICO EXACTO en
 * UNIDAD BASE (revision 1 en el CREATE; revision = old.revision + 1 en cada
 * UPDATE). applySnapshot()/reverseSnapshot() operan sobre ESE snapshot histórico
 * y jamás reconstruyen la cantidad con la Unit actual.
 *
 * ARTIFACT SAFETY (MS1): sólo is_single / is_variant. Cualquier línea con
 * is_batch_tracked = 1 o is_imei = 1 => FAIL CLOSED (ValidationException). El
 * flujo artifact-aware (lotes/seriales por ubicación) llega en MS5/MS6.
 *
 * MODE-AWARE: este servicio NO decide nada por modo de transición. El switch
 * legacy_only|shadow_compare|dual_write (legacy) vs location_primary (este
 * motor) lo hará el controller en MS2. MS1 sólo deja el motor listo.
 *
 * CONTRATO EJECUTABLE: todos los métodos que mutan/bloquean exigen
 * DB::transactionLevel() > 0 (la transacción del caller confirma o revierte
 * TODO junto).
 */
class LocationAwarePurchaseStockService
{
    public const DOC_PURCHASE = 'purchase';
    public const DOC_PURCHASE_RETURN = 'purchase_return';

    public const REF_PURCHASE = 'Purchase';
    public const REF_PURCHASE_REVERSAL = 'PurchaseReversal';
    public const REF_PURCHASE_RETURN = 'PurchaseReturn';
    public const REF_PURCHASE_RETURN_REVERSAL = 'PurchaseReturnReversal';

    public const SNAPSHOT_VERSION = 1;

    /** MS1: sólo productos con inventario físico simple / variante. */
    public const SUPPORTED_PRODUCT_TYPES = ['is_single', 'is_variant'];

    private const EPS = 0.0005;

    public function __construct(private InventoryService $inventory)
    {
    }

    // =====================================================================
    // 1 · Validación + locks (DENTRO de la transacción del caller)
    // =====================================================================

    /**
     * @param  string  $documentType  self::DOC_PURCHASE | self::DOC_PURCHASE_RETURN
     * @param  array<int,array{product_id:mixed,product_variant_id?:mixed,quantity:mixed,purchase_unit_id:mixed,source_detail_id?:mixed}>  $lines
     * @param  int[]  $extraProductIds  ids extra a bloquear (p. ej. los del snapshot viejo en un UPDATE futuro)
     * @return array{
     *   document_type:string, warehouse_id:int, inventory_location_id:int, location: InventoryLocation,
     *   lines: array<int,array{source_detail_id:?int, product_id:int, product_variant_id:?int, quantity:float, quantity_base:float}>
     * }
     *
     * @throws ValidationException
     */
    public function validateAndLock(string $documentType, int $warehouseId, ?int $locationId, array $lines, array $extraProductIds = []): array
    {
        $documentType = $this->assertDocumentType($documentType);
        $this->assertInTransaction();

        // 1. Warehouse (bloqueado, no eliminado).
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->whereNull('deleted_at')
            ->lockForUpdate()->first();
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'El almacén no existe o está eliminado.']);
        }

        if (! $locationId) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Debes seleccionar una ubicación de inventario para este almacén.',
            ]);
        }

        // 2. InventoryLocation (bloqueada). Debe seguir apta DENTRO de la tx.
        $location = InventoryLocation::whereKey($locationId)->lockForUpdate()->first();
        if (! $location || $location->deleted_at !== null || ! (bool) $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación de inventario no existe, está inactiva o fue eliminada.',
            ]);
        }
        if ((int) $location->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación de inventario no pertenece al almacén seleccionado.',
            ]);
        }

        if (empty($lines)) {
            throw ValidationException::withMessages(['details' => 'El documento no tiene líneas.']);
        }

        // Recolectar ids.
        $lineProductIds = array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines);
        $lineVariantIds = [];
        $lineUnitIds = [];
        foreach ($lines as $l) {
            $v = $l['product_variant_id'] ?? null;
            if ($v !== null && $v !== '' && (int) $v > 0) {
                $lineVariantIds[] = (int) $v;
            }
            $u = $l['purchase_unit_id'] ?? null;
            if ($u !== null && $u !== '' && (int) $u > 0) {
                $lineUnitIds[] = (int) $u;
            }
        }

        // 3. Products ASC (bloqueados) — incl. extra ids.
        $allProductIds = array_values(array_unique(array_filter(array_merge(
            $lineProductIds, array_map('intval', $extraProductIds)
        ))));
        sort($allProductIds);
        $products = $allProductIds
            ? DB::table('products')->whereIn('id', $allProductIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        // 4. ProductVariants ASC (bloqueadas).
        $variants = collect();
        if ($lineVariantIds && Schema::hasTable('product_variants')) {
            $ids = array_values(array_unique($lineVariantIds));
            sort($ids);
            $variants = DB::table('product_variants')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        }

        // 5. Units ASC (bloqueadas).
        $units = collect();
        if ($lineUnitIds) {
            $ids = array_values(array_unique($lineUnitIds));
            sort($ids);
            $units = DB::table('units')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        }

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');

        $normalized = [];
        $trackedIds = [];
        foreach ($lines as $i => $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $vid = isset($line['product_variant_id']) && $line['product_variant_id'] !== null && $line['product_variant_id'] !== '' && (int) $line['product_variant_id'] > 0
                ? (int) $line['product_variant_id'] : null;
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            $unitId = (int) ($line['purchase_unit_id'] ?? 0);
            $detailId = isset($line['source_detail_id']) && $line['source_detail_id'] !== null && $line['source_detail_id'] !== ''
                ? (int) $line['source_detail_id'] : null;

            $product = $products->get($pid);
            if (! $product || $product->deleted_at !== null) {
                throw ValidationException::withMessages(["details.$i.product_id" => 'El producto de la línea '.($i + 1).' no existe o fue eliminado.']);
            }
            if (! in_array((string) $product->type, self::SUPPORTED_PRODUCT_TYPES, true)) {
                throw ValidationException::withMessages([
                    "details.$i.product_id" => 'El producto de la línea '.($i + 1).' no está soportado por el flujo de compra por ubicación (tipo: '.($product->type ?: 'desconocido').'). Sólo productos simples o de variante.',
                ]);
            }
            if ($qty <= self::EPS) {
                throw ValidationException::withMessages(["details.$i.quantity" => 'La cantidad de la línea '.($i + 1).' debe ser mayor que cero.']);
            }

            // producto ↔ variante.
            if ((string) $product->type === 'is_variant' && $vid === null) {
                throw ValidationException::withMessages([
                    "details.$i.product_variant_id" => 'El producto de la línea '.($i + 1).' es de variantes: falta product_variant_id.',
                ]);
            }
            if ((string) $product->type === 'is_single' && $vid !== null) {
                throw ValidationException::withMessages([
                    "details.$i.product_variant_id" => 'El producto de la línea '.($i + 1).' no admite variante.',
                ]);
            }
            if ($vid !== null) {
                $variant = $variants->get($vid);
                if (! $variant || $variant->deleted_at !== null || (int) $variant->product_id !== $pid) {
                    throw ValidationException::withMessages([
                        "details.$i.product_variant_id" => 'La variante de la línea '.($i + 1).' no existe, fue eliminada o no pertenece al producto.',
                    ]);
                }
            }

            // unidad de compra.
            $unit = $units->get($unitId);
            if (! $unit) {
                throw ValidationException::withMessages([
                    "details.$i.purchase_unit_id" => 'La unidad de compra de la línea '.($i + 1).' no existe.',
                ]);
            }
            $operatorValue = (float) $unit->operator_value;
            if ($operatorValue <= 0) {
                throw ValidationException::withMessages([
                    "details.$i.purchase_unit_id" => 'La unidad de compra de la línea '.($i + 1).' tiene un factor de conversión inválido.',
                ]);
            }

            // (ARTIFACT SAFETY) fail closed batch / IMEI.
            if (($hasBatch && (int) ($product->is_batch_tracked ?? 0) === 1)
                || ($hasImei && (int) ($product->is_imei ?? 0) === 1)) {
                $trackedIds[$pid] = true;
            }

            $normalized[] = [
                'source_detail_id' => $detailId,
                'product_id' => $pid,
                'product_variant_id' => $vid,
                'quantity' => $qty,
                'quantity_base' => $this->toBaseQuantity($qty, (string) $unit->operator, $operatorValue),
            ];
        }

        if ($trackedIds) {
            throw ValidationException::withMessages([
                'details' => 'La compra/devolución por ubicación de productos con control de lote o serie/IMEI se habilitará mediante el flujo artifact-aware. '
                    .'Productos afectados: '.implode(', ', array_keys($trackedIds)).'.',
            ]);
        }

        return [
            'document_type' => $documentType,
            'warehouse_id' => $warehouseId,
            'inventory_location_id' => (int) $location->id,
            'location' => $location,
            'lines' => $normalized,
        ];
    }

    /**
     * Regla de conversión legacy AUDITADA: operator '/' => qty / value ;
     * cualquier otro operador => qty * value. Se congela en el snapshot.
     */
    public function toBaseQuantity(float $quantity, string $operator, float $operatorValue): float
    {
        $base = $operator === '/'
            ? $quantity / $operatorValue
            : $quantity * $operatorValue;

        return round($base, 3);
    }

    // =====================================================================
    // 2 · Construcción del snapshot (plan físico histórico, versionado)
    // =====================================================================

    /**
     * @param  array  $validated  resultado de validateAndLock()
     * @param  int  $revision  1 en el CREATE; old.revision + 1 en cada UPDATE
     * @return array{version:int,revision:int,document_type:string,warehouse_id:int,inventory_location_id:int,effects:array}
     */
    public function buildSnapshot(array $validated, int $revision = 1): array
    {
        $documentType = $this->assertDocumentType($validated['document_type'] ?? '');
        $revision = max(1, (int) $revision);
        $sign = $documentType === self::DOC_PURCHASE ? 1.0 : -1.0;

        $effects = [];
        foreach ($validated['lines'] as $line) {
            $qtyBase = round((float) $line['quantity_base'], 3);
            if ($qtyBase <= self::EPS) {
                continue;
            }
            $effects[] = [
                'source_detail_id' => $line['source_detail_id'] !== null ? (int) $line['source_detail_id'] : null,
                'product_id' => (int) $line['product_id'],
                'product_variant_id' => $line['product_variant_id'] !== null ? (int) $line['product_variant_id'] : null,
                'quantity_base' => $qtyBase,
                'delta' => round($sign * $qtyBase, 3),
            ];
        }

        // Orden determinístico y ESTABLE — define el índice de efecto que va en
        // la idempotency_key para siempre.
        usort($effects, function ($a, $b) {
            return [$a['product_id'], (int) ($a['product_variant_id'] ?? 0), (int) ($a['source_detail_id'] ?? 0)]
                <=> [$b['product_id'], (int) ($b['product_variant_id'] ?? 0), (int) ($b['source_detail_id'] ?? 0)];
        });

        return [
            'version' => self::SNAPSHOT_VERSION,
            'revision' => $revision,
            'document_type' => $documentType,
            'warehouse_id' => (int) $validated['warehouse_id'],
            'inventory_location_id' => (int) $validated['inventory_location_id'],
            'effects' => array_values($effects),
        ];
    }

    /**
     * Normaliza un snapshot persistido (JSON string o array). FAIL CLOSED si
     * falta / está corrupto / la versión o revisión no son válidas. NO reordena
     * los efectos: el orden persistido define el índice de idempotencia.
     *
     * @throws ValidationException
     */
    public function normalizeSnapshot($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'El documento location-native no tiene un snapshot de efectos válido.',
            ]);
        }

        if ((int) ($raw['version'] ?? 0) !== self::SNAPSHOT_VERSION) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'Versión de snapshot no soportada (esperada '.self::SNAPSHOT_VERSION.').',
            ]);
        }
        $revision = (int) ($raw['revision'] ?? 0);
        if ($revision < 1) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Revisión de snapshot inválida.']);
        }
        $documentType = $this->assertDocumentType($raw['document_type'] ?? '');
        $warehouseId = (int) ($raw['warehouse_id'] ?? 0);
        $locationId = (int) ($raw['inventory_location_id'] ?? 0);
        if ($warehouseId <= 0 || $locationId <= 0) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot sin almacén/ubicación válidos.']);
        }

        $effectsRaw = $raw['effects'] ?? null;
        if (! is_array($effectsRaw) || $effectsRaw === []) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot sin efectos.']);
        }

        $expectedSign = $documentType === self::DOC_PURCHASE ? 1 : -1;
        $effects = [];
        foreach ($effectsRaw as $e) {
            if (! is_array($e) || ! isset($e['product_id']) || ! array_key_exists('delta', $e) || ! array_key_exists('quantity_base', $e)) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot de efectos corrupto.']);
            }
            $pid = (int) $e['product_id'];
            $delta = round((float) $e['delta'], 3);
            $qtyBase = round((float) $e['quantity_base'], 3);
            if ($pid <= 0 || $qtyBase <= self::EPS) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Efecto con producto o cantidad inválidos.']);
            }
            if (abs(abs($delta) - $qtyBase) > self::EPS) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Efecto inconsistente: |delta| != quantity_base.']);
            }
            if (($delta > 0 ? 1 : -1) !== $expectedSign) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Signo de delta incompatible con el tipo de documento.']);
            }
            $effects[] = [
                'source_detail_id' => isset($e['source_detail_id']) && $e['source_detail_id'] !== null ? (int) $e['source_detail_id'] : null,
                'product_id' => $pid,
                'product_variant_id' => isset($e['product_variant_id']) && $e['product_variant_id'] !== null ? (int) $e['product_variant_id'] : null,
                'quantity_base' => $qtyBase,
                'delta' => $delta,
            ];
        }

        return [
            'version' => self::SNAPSHOT_VERSION,
            'revision' => $revision,
            'document_type' => $documentType,
            'warehouse_id' => $warehouseId,
            'inventory_location_id' => $locationId,
            'effects' => $effects,
        ];
    }

    /**
     * Guard histórico (mismo espíritu que LocationAwareStockDocumentService):
     * un snapshot simple/variant no puede revertirse quantity-only si algún
     * producto AHORA es batch-tracked / IMEI, está HARD-MISSING, o su variante
     * dejó de ser válida. Se ejecuta DENTRO de la transacción y ANTES de
     * reverseSnapshot(). NO exige whereNull('deleted_at') en products: un
     * producto simple soft-deleted debe poder revertirse.
     *
     * @throws ValidationException
     */
    public function assertSnapshotArtifactSafeAndLock(array $snapshot): void
    {
        $this->assertInTransaction();
        $snapshot = $this->normalizeSnapshot($snapshot);

        $ids = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $snapshot['effects'])));
        sort($ids);
        if (! $ids) {
            return;
        }

        $rows = DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');

        $missing = [];
        $nowTracked = [];
        foreach ($ids as $id) {
            $p = $rows->get($id);
            if (! $p) {
                $missing[] = $id;

                continue;
            }
            if (($hasBatch && (int) ($p->is_batch_tracked ?? 0) === 1) || ($hasImei && (int) ($p->is_imei ?? 0) === 1)) {
                $nowTracked[] = $id;
            }
        }

        if ($missing) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'No se puede revertir el documento: productos del snapshot ya no existen ('.implode(', ', $missing).'). FAIL CLOSED.',
            ]);
        }
        if ($nowTracked) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'No se puede revertir con una operación quantity-only: los productos '.implode(', ', $nowTracked).' ahora llevan control de lote o serie/IMEI. Requiere el flujo artifact-aware.',
            ]);
        }

        // Variantes del snapshot: si una línea era de variante, la variante debe
        // seguir existiendo y perteneciendo al producto.
        if (Schema::hasTable('product_variants')) {
            $variantIds = array_values(array_unique(array_filter(array_map(
                fn ($e) => $e['product_variant_id'] !== null ? (int) $e['product_variant_id'] : 0,
                $snapshot['effects']
            ))));
            if ($variantIds) {
                sort($variantIds);
                $vrows = DB::table('product_variants')->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($snapshot['effects'] as $e) {
                    $vid = $e['product_variant_id'] !== null ? (int) $e['product_variant_id'] : 0;
                    if ($vid === 0) {
                        continue;
                    }
                    $vr = $vrows->get($vid);
                    if (! $vr || (int) $vr->product_id !== (int) $e['product_id']) {
                        throw ValidationException::withMessages([
                            'inventory_effect_snapshot' => 'No se puede revertir el documento: la variante '.$vid.' ya no existe o no pertenece al producto '.$e['product_id'].'. FAIL CLOSED.',
                        ]);
                    }
                }
            }
        }
    }

    // =====================================================================
    // 3 · Aplicación / reversión (sólo InventoryService)
    // =====================================================================

    /** Aplica el snapshot histórico del documento $documentId. */
    public function applySnapshot(array $snapshot, int $documentId): void
    {
        $this->runSnapshot($snapshot, $documentId, apply: true);
    }

    /** Revierte EXACTAMENTE el snapshot histórico del documento $documentId. */
    public function reverseSnapshot(array $snapshot, int $documentId): void
    {
        $this->runSnapshot($snapshot, $documentId, apply: false);
    }

    private function runSnapshot(array $snapshot, int $documentId, bool $apply): void
    {
        $this->assertInTransaction();
        $snapshot = $this->normalizeSnapshot($snapshot);

        $documentType = $snapshot['document_type'];
        $warehouseId = $snapshot['warehouse_id'];
        $locationId = $snapshot['inventory_location_id'];
        $revision = $snapshot['revision'];
        $operation = $apply ? 'apply' : 'reverse';
        $referenceType = $this->referenceType($documentType, $apply);
        $userId = function_exists('auth') ? auth()->id() : null;

        foreach ($snapshot['effects'] as $n => $effect) {
            // apply: delta tal cual. reverse: delta negado.
            $delta = $apply ? $effect['delta'] : -$effect['delta'];
            $qty = round(abs($delta), 3);
            if ($qty <= self::EPS) {
                continue;
            }

            $variantId = $effect['product_variant_id'] !== null ? (int) $effect['product_variant_id'] : null;
            $context = [
                'user_id' => $userId,
                'reference_type' => $referenceType,
                'reference_id' => (string) $documentId,
                'idempotency_key' => $this->idempotencyKey($documentType, $documentId, $revision, (int) $n, $operation),
                'notes' => $referenceType.' '.$operation.' (rev '.$revision.', efecto '.$n.')',
                'metadata' => [
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'revision' => $revision,
                    'effect_index' => (int) $n,
                    'operation' => $operation,
                    'warehouse_id' => $warehouseId,
                    'inventory_location_id' => $locationId,
                    'source_detail_id' => $effect['source_detail_id'],
                    'product_id' => $effect['product_id'],
                    'product_variant_id' => $variantId,
                    'quantity_base' => $effect['quantity_base'],
                    'delta' => $delta,
                ],
            ];

            if ($delta > 0) {
                $this->inventory->increase($locationId, (int) $effect['product_id'], $qty, $variantId, $context);
            } else {
                // decrease() de InventoryService rechaza si no hay disponible
                // suficiente — NUNCA se hace clamp a cero.
                $this->inventory->decrease($locationId, (int) $effect['product_id'], $qty, $variantId, $context);
            }
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Clave determinística. La MISMA revisión + MISMA operación => MISMA clave
     * (repetir apply/reverse no duplica movimiento). Una revisión nueva =>
     * claves nuevas (una edición no choca con los movimientos originales).
     */
    public function idempotencyKey(string $documentType, int $documentId, int $revision, int $effectIndex, string $operation): string
    {
        return $documentType.':'.$documentId.':rev:'.$revision.':effect:'.$effectIndex.':'.$operation;
    }

    public function referenceType(string $documentType, bool $apply): string
    {
        if ($documentType === self::DOC_PURCHASE) {
            return $apply ? self::REF_PURCHASE : self::REF_PURCHASE_REVERSAL;
        }

        return $apply ? self::REF_PURCHASE_RETURN : self::REF_PURCHASE_RETURN_REVERSAL;
    }

    private function assertDocumentType(string $documentType): string
    {
        if (! in_array($documentType, [self::DOC_PURCHASE, self::DOC_PURCHASE_RETURN], true)) {
            throw ValidationException::withMessages([
                'document_type' => "Tipo de documento no soportado: '{$documentType}'.",
            ]);
        }

        return $documentType;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() <= 0) {
            throw ValidationException::withMessages([
                'inventory' => 'LocationAwarePurchaseStockService debe ejecutarse dentro de la transacción del documento.',
            ]);
        }
    }
}
