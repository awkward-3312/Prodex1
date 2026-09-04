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

    // MS5-B2 — batch-artifact ledger reference types (one row per allocation).
    public const REF_PURCHASE_BATCH = 'PurchaseBatch';
    public const REF_PURCHASE_BATCH_REVERSAL = 'PurchaseBatchReversal';
    public const REF_PURCHASE_RETURN_BATCH = 'PurchaseReturnBatch';
    public const REF_PURCHASE_RETURN_BATCH_REVERSAL = 'PurchaseReturnBatchReversal';

    // MS6-B0 — serial-artifact ledger reference types (one movement per unit).
    // (The serial set service also uses these strings.)
    public const REF_SERIAL_PURCHASE = 'Purchase';
    public const REF_SERIAL_PURCHASE_REVERSAL = 'PurchaseReversal';
    public const REF_SERIAL_PURCHASE_RETURN = 'PurchaseReturn';
    public const REF_SERIAL_PURCHASE_RETURN_REVERSAL = 'PurchaseReturnReversal';

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
    public function validateAndLock(string $documentType, int $warehouseId, ?int $locationId, array $lines, array $extraProductIds = [], array $options = []): array
    {
        $documentType = $this->assertDocumentType($documentType);
        $this->assertInTransaction();

        // MS5-B2 — backward-compatible opt-in. allow_batch === false (default):
        // EXACTLY as before, a batch-tracked line fails closed. allow_batch ===
        // true: a batch-tracked line is allowed and marked requires_batch, and
        // the caller MUST then run the batch layer.
        $allowBatch = (bool) ($options['allow_batch'] ?? false);
        // MS6-B0 — same opt-in for serials. allow_serial === false (default): an
        // IMEI line fails closed EXACTLY as before. allow_serial === true: the
        // line is marked requires_serial, its quantity_base MUST be an integer,
        // and the caller MUST then run the serial layer. A batch+IMEI product
        // ALWAYS fails closed (no dual artifact tracking).
        $allowSerial = (bool) ($options['allow_serial'] ?? false);

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
        $imeiIds = [];
        $batchIds = [];
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

            // (ARTIFACT SAFETY) Batch fails closed UNLESS allow_batch. IMEI
            // fails closed UNLESS allow_serial. A product that is BOTH always
            // fails closed (no dual artifact tracking).
            $isImei = $hasImei && (int) ($product->is_imei ?? 0) === 1;
            $isBatch = $hasBatch && (int) ($product->is_batch_tracked ?? 0) === 1;

            if ($isImei && $isBatch) {
                throw ValidationException::withMessages([
                    "details.$i" => 'El producto de la línea '.($i + 1).' lleva control de lote Y serie/IMEI a la vez. La combinación no está soportada.',
                ]);
            }

            $requiresBatch = false;
            if ($isBatch) {
                if ($allowBatch) {
                    $requiresBatch = true;
                } else {
                    $batchIds[$pid] = true;
                }
            }

            $requiresSerial = false;
            $qtyBase = $this->toBaseQuantity($qty, (string) $unit->operator, $operatorValue);
            if ($isImei) {
                if ($allowSerial) {
                    $requiresSerial = true;
                    if (abs($qtyBase - round($qtyBase)) > self::EPS) {
                        throw ValidationException::withMessages([
                            "details.$i.quantity" => 'La línea '.($i + 1).' usa serie/IMEI y sólo admite una cantidad base entera (calculada: '.$qtyBase.').',
                        ]);
                    }
                } else {
                    $imeiIds[$pid] = true;
                }
            }

            $normalized[] = [
                'source_detail_id' => $detailId,
                'product_id' => $pid,
                'product_variant_id' => $vid,
                'quantity' => $qty,
                'quantity_base' => $qtyBase,
                'requires_batch' => $requiresBatch,
                'requires_serial' => $requiresSerial,
                'purchase_unit_id' => $unitId,
                'unit_operator' => (string) $unit->operator,
                'unit_operator_value' => $operatorValue,
            ];
        }

        if ($imeiIds) {
            throw ValidationException::withMessages([
                'details' => 'La compra/devolución por ubicación de productos con serie/IMEI se habilitará mediante el flujo artifact-aware (MS6). '
                    .'Productos afectados: '.implode(', ', array_keys($imeiIds)).'.',
            ]);
        }
        if ($batchIds) {
            throw ValidationException::withMessages([
                'details' => 'La compra/devolución por ubicación de productos con control de lote se habilitará mediante el flujo artifact-aware (allow_batch). '
                    .'Productos afectados: '.implode(', ', array_keys($batchIds)).'.',
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

    /**
     * MS5-B2 — normalize + validate a batch_allocation list for ONE effect.
     * FAIL CLOSED: empty, product_batch_id <= 0, quantity_base <= 0, duplicate
     * bidx, or SUM(quantity_base) != $effectQtyBase (EPS). Returns the list
     * sorted by bidx, each entry {bidx, product_batch_id, batch_no, expiry_date,
     * mfg_date, quantity_base, unit_cost}.
     *
     * @throws ValidationException
     */
    private function normalizeBatchAllocation($raw, float $effectQtyBase): array
    {
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'Un efecto de lote no tiene asignación de lotes.',
            ]);
        }

        $entries = [];
        $seenBidx = [];
        $sum = 0.0;
        foreach ($raw as $k => $a) {
            if (! is_array($a)) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Asignación de lote malformada.']);
            }
            $bidx = array_key_exists('bidx', $a) ? (int) $a['bidx'] : (int) $k;
            $pbid = (int) ($a['product_batch_id'] ?? 0);
            $qtyBase = round((float) ($a['quantity_base'] ?? 0), 3);
            if ($pbid <= 0) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Asignación de lote sin product_batch_id.']);
            }
            if ($qtyBase <= self::EPS) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Asignación de lote con cantidad base no positiva.']);
            }
            if (isset($seenBidx[$bidx])) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'bidx duplicado en la asignación de lotes.']);
            }
            $seenBidx[$bidx] = true;
            $sum = round($sum + $qtyBase, 3);

            $entries[] = [
                'bidx' => $bidx,
                'product_batch_id' => $pbid,
                'batch_no' => isset($a['batch_no']) ? (string) $a['batch_no'] : '',
                'expiry_date' => $this->dateOrNull($a['expiry_date'] ?? null),
                'mfg_date' => $this->dateOrNull($a['mfg_date'] ?? null),
                'quantity_base' => $qtyBase,
                'unit_cost' => isset($a['unit_cost']) && $a['unit_cost'] !== null && $a['unit_cost'] !== '' ? (float) $a['unit_cost'] : null,
            ];
        }

        if (abs($sum - round($effectQtyBase, 3)) > self::EPS) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'La suma de la asignación de lotes ('.$sum.') no coincide con la cantidad física del efecto ('.round($effectQtyBase, 3).').',
            ]);
        }

        usort($entries, fn ($a, $b) => $a['bidx'] <=> $b['bidx']);

        return $entries;
    }

    /** Alias kept for buildSnapshot() readability. */
    private function buildBatchAllocation($raw, float $effectQtyBase): array
    {
        return $this->normalizeBatchAllocation($raw, $effectQtyBase);
    }

    private function dateOrNull($v): ?string
    {
        if ($v === null || $v === '' || $v === 'null') {
            return null;
        }

        return (string) $v;
    }

    /**
     * MS6-B0 — normalize + validate a serial_allocation list for ONE effect.
     * FAIL CLOSED: empty, count != quantity_base, product_serial_id <= 0,
     * blank serial_number, or a duplicate sidx / serial_number WITHIN the
     * effect. Returns the list sorted by sidx with sidx re-indexed 0..N-1.
     *
     * @throws ValidationException
     */
    private function normalizeSerialAllocation($raw, float $effectQtyBase): array
    {
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'Un efecto serializado no tiene asignación de series.',
            ]);
        }

        $base = (int) round($effectQtyBase);
        if (abs($effectQtyBase - $base) > self::EPS) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'Un efecto serializado tiene cantidad base no entera ('.$effectQtyBase.').',
            ]);
        }

        $entries = [];
        $seenSidx = [];
        $seenSerial = [];
        foreach ($raw as $k => $a) {
            if (! is_array($a)) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Asignación de serie malformada.']);
            }
            $sidx = array_key_exists('sidx', $a) ? (int) $a['sidx'] : (int) $k;
            $psid = (int) ($a['product_serial_id'] ?? 0);
            $serialNumber = trim((string) ($a['serial_number'] ?? ''));
            if ($psid <= 0) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Asignación de serie sin product_serial_id.']);
            }
            if ($serialNumber === '') {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Asignación de serie sin número de serie.']);
            }
            if (isset($seenSidx[$sidx])) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'sidx duplicado en la asignación de series.']);
            }
            $sk = mb_strtolower($serialNumber);
            if (isset($seenSerial[$sk])) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Número de serie duplicado en la asignación del efecto.']);
            }
            $seenSidx[$sidx] = true;
            $seenSerial[$sk] = true;
            $entries[] = ['sidx' => $sidx, 'product_serial_id' => $psid, 'serial_number' => $serialNumber];
        }

        if (count($entries) !== $base) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'La asignación de series ('.count($entries).') no coincide con la cantidad base del efecto ('.$base.').',
            ]);
        }

        usort($entries, fn ($a, $b) => $a['sidx'] <=> $b['sidx']);
        foreach ($entries as $n => &$e) {
            $e['sidx'] = $n;
        }
        unset($e);

        return $entries;
    }

    private function buildSerialAllocation($raw, float $effectQtyBase): array
    {
        return $this->normalizeSerialAllocation($raw, $effectQtyBase);
    }

    /** No serial_number may repeat across the WHOLE document's effects. */
    private function assertSnapshotSerialNumbersUniqueDocumentWide(array $effects): void
    {
        $seen = [];
        foreach ($effects as $e) {
            foreach ($e['serial_allocation'] ?? [] as $a) {
                $k = mb_strtolower((string) $a['serial_number']);
                if (isset($seen[$k])) {
                    throw ValidationException::withMessages([
                        'inventory_effect_snapshot' => "El número de serie '{$a['serial_number']}' se repite en el documento.",
                    ]);
                }
                $seen[$k] = true;
            }
        }
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
            $effect = [
                'source_detail_id' => $line['source_detail_id'] !== null ? (int) $line['source_detail_id'] : null,
                'product_id' => (int) $line['product_id'],
                'product_variant_id' => $line['product_variant_id'] !== null ? (int) $line['product_variant_id'] : null,
                'quantity_base' => $qtyBase,
                'delta' => round($sign * $qtyBase, 3),
            ];

            // MS5-B2 — a requires_batch line MUST carry a non-empty
            // batch_allocation (frozen by LocationAwarePurchaseBatchPlanner);
            // a non-batch line must NOT carry one.
            $requiresBatch = (bool) ($line['requires_batch'] ?? false);
            $requiresSerial = (bool) ($line['requires_serial'] ?? false);
            $rawAlloc = $line['batch_allocation'] ?? null;
            $rawSerial = $line['serial_allocation'] ?? null;

            if ($requiresBatch && $requiresSerial) {
                throw ValidationException::withMessages([
                    'inventory_effect_snapshot' => 'Un efecto no puede llevar asignación de lotes Y de series a la vez.',
                ]);
            }

            if ($requiresBatch) {
                $effect['batch_allocation'] = $this->buildBatchAllocation($rawAlloc, $qtyBase);
            } elseif (! empty($rawAlloc)) {
                throw ValidationException::withMessages([
                    'inventory_effect_snapshot' => 'Se recibió una asignación de lote para un producto que el plan no marcó como batch.',
                ]);
            }

            // MS6-B0 — a requires_serial line MUST carry a serial_allocation
            // (frozen by LocationAwarePurchaseSerialPlanner) with exactly
            // quantity_base entries; a non-serial line must NOT carry one.
            if ($requiresSerial) {
                $effect['serial_allocation'] = $this->buildSerialAllocation($rawSerial, $qtyBase);
            } elseif (! empty($rawSerial)) {
                throw ValidationException::withMessages([
                    'inventory_effect_snapshot' => 'Se recibió una asignación de series para un producto que el plan no marcó como serializado.',
                ]);
            }

            $effects[] = $effect;
        }

        // document-wide serial_number uniqueness across ALL effects.
        $this->assertSnapshotSerialNumbersUniqueDocumentWide($effects);

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

            $effect = [
                'source_detail_id' => isset($e['source_detail_id']) && $e['source_detail_id'] !== null ? (int) $e['source_detail_id'] : null,
                'product_id' => $pid,
                'product_variant_id' => isset($e['product_variant_id']) && $e['product_variant_id'] !== null ? (int) $e['product_variant_id'] : null,
                'quantity_base' => $qtyBase,
                'delta' => $delta,
            ];

            // MS5-B2 — accept effects with OR without batch_allocation. When
            // present it is normalized + validated (sum, unique bidx, sorted).
            $hasBatchAlloc = array_key_exists('batch_allocation', $e) && $e['batch_allocation'] !== null && $e['batch_allocation'] !== [];
            $hasSerialAlloc = array_key_exists('serial_allocation', $e) && $e['serial_allocation'] !== null && $e['serial_allocation'] !== [];
            if ($hasBatchAlloc && $hasSerialAlloc) {
                throw ValidationException::withMessages([
                    'inventory_effect_snapshot' => 'Un efecto del snapshot lleva asignación de lotes Y de series a la vez. FAIL CLOSED.',
                ]);
            }
            if ($hasBatchAlloc) {
                $effect['batch_allocation'] = $this->normalizeBatchAllocation($e['batch_allocation'], $qtyBase);
            }
            // MS6-B0 — accept effects with OR without serial_allocation. An old
            // quantity-only snapshot is NEVER reinterpreted as serial even if
            // the product later became is_imei (see assertSnapshotArtifactSafeAndLock).
            if ($hasSerialAlloc) {
                $effect['serial_allocation'] = $this->normalizeSerialAllocation($e['serial_allocation'], $qtyBase);
            }

            $effects[] = $effect;
        }

        $this->assertSnapshotSerialNumbersUniqueDocumentWide($effects);

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
    public function assertSnapshotArtifactSafeAndLock(array $snapshot, array $options = []): void
    {
        $this->assertInTransaction();
        $snapshot = $this->normalizeSnapshot($snapshot);
        $allowBatch = (bool) ($options['allow_batch'] ?? false);
        $allowSerial = (bool) ($options['allow_serial'] ?? false);

        $ids = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $snapshot['effects'])));
        sort($ids);
        if (! $ids) {
            return;
        }

        $rows = DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');

        $missing = [];
        $nowImei = [];
        $nowBatch = [];
        foreach ($ids as $id) {
            $p = $rows->get($id);
            if (! $p) {
                $missing[] = $id;

                continue;
            }
            if ($hasImei && (int) ($p->is_imei ?? 0) === 1) {
                $nowImei[] = $id;                       // IMEI ALWAYS fails closed.
            } elseif ($hasBatch && (int) ($p->is_batch_tracked ?? 0) === 1) {
                $nowBatch[] = $id;
            }
        }

        if ($missing) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'No se puede revertir el documento: productos del snapshot ya no existen ('.implode(', ', $missing).'). FAIL CLOSED.',
            ]);
        }
        if ($nowImei) {
            if (! $allowSerial) {
                throw ValidationException::withMessages([
                    'inventory_effect_snapshot' => 'No se puede revertir con una operación quantity-only: los productos '.implode(', ', $nowImei).' ahora llevan serie/IMEI. Requiere el flujo artifact-aware (MS6).',
                ]);
            }
            // allow_serial: a snapshot effect for a now-serialized product MUST
            // carry a serial_allocation. An old quantity-only effect cannot be
            // reverted for a product that became is_imei — FAIL CLOSED.
            $serialProducts = array_flip($nowImei);
            foreach ($snapshot['effects'] as $e) {
                if (isset($serialProducts[(int) $e['product_id']]) && empty($e['serial_allocation'])) {
                    throw ValidationException::withMessages([
                        'inventory_effect_snapshot' => 'El snapshot del producto '.$e['product_id'].' no trae asignación de series y el producto ahora lleva serie/IMEI. No se puede adivinar. FAIL CLOSED.',
                    ]);
                }
            }
            $this->assertSnapshotSerialAllocationSafeAndLock($snapshot);
        }
        if ($nowBatch) {
            if (! $allowBatch) {
                throw ValidationException::withMessages([
                    'inventory_effect_snapshot' => 'No se puede revertir con una operación quantity-only: los productos '.implode(', ', $nowBatch).' ahora llevan control de lote. Requiere el flujo artifact-aware.',
                ]);
            }
            // allow_batch: a snapshot effect for a now-batch product MUST carry
            // a batch_allocation. A pre-MS5 quantity-only effect cannot be
            // reverted for a product that became batch-tracked — FAIL CLOSED.
            $batchProducts = array_flip($nowBatch);
            foreach ($snapshot['effects'] as $e) {
                if (isset($batchProducts[(int) $e['product_id']]) && empty($e['batch_allocation'])) {
                    throw ValidationException::withMessages([
                        'inventory_effect_snapshot' => 'El snapshot del producto '.$e['product_id'].' no trae asignación de lotes y el producto ahora lleva control de lote. No se puede adivinar. FAIL CLOSED.',
                    ]);
                }
            }
            $this->assertSnapshotBatchAllocationSafeAndLock($snapshot);
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

        // The whole snapshot has ONE delta sign (all purchase effects > 0, all
        // return effects < 0). After apply/reverse it is $effectiveSign.
        $signApply = $documentType === self::DOC_PURCHASE ? 1 : -1;
        $effectiveSign = $apply ? $signApply : -$signApply;

        // ===== PHASE A — ALL BATCH ARTIFACTS (one receiveMany/issueMany) =====
        // Canonical order: batch artifacts BEFORE general inventory. Never
        // interleave general effect 0 -> batch effect 0 -> general effect 1.
        $allocations = [];
        foreach ($snapshot['effects'] as $effect) {
            $sdid = (int) ($effect['source_detail_id'] ?? 0);
            foreach ($effect['batch_allocation'] ?? [] as $a) {
                $bidx = (int) $a['bidx'];
                $allocations[] = [
                    'product_batch_id' => (int) $a['product_batch_id'],
                    'quantity' => round((float) $a['quantity_base'], 3),
                    'idempotency_key' => $this->batchIdempotencyKey($documentType, $documentId, $revision, $sdid, $bidx, $operation),
                    'expected_product_id' => (int) $effect['product_id'],
                    'expected_variant_id' => $effect['product_variant_id'] !== null ? (int) $effect['product_variant_id'] : null,
                    'reference_type' => $this->batchReferenceType($documentType, $apply),
                    'reference_id' => (string) $documentId,
                    'notes' => $this->batchReferenceType($documentType, $apply).' '.$operation.' (rev '.$revision.', detail '.$sdid.', b '.$bidx.')',
                    'metadata' => [
                        'document_type' => $documentType,
                        'document_id' => $documentId,
                        'revision' => $revision,
                        'source_detail_id' => $effect['source_detail_id'],
                        'bidx' => $bidx,
                        'batch_no' => $a['batch_no'],
                        'inventory_location_id' => $locationId,
                    ],
                ];
            }
        }
        if ($allocations) {
            $batch = app(BatchLocationService::class);
            if ($effectiveSign > 0) {
                $batch->receiveMany($locationId, $allocations);
            } else {
                $batch->issueMany($locationId, $allocations);
            }
        }

        // ===== PHASE B — ALL SERIAL ARTIFACTS (one atomic set) ===============
        // Canonical order: batch -> serial -> general. One set call for the
        // whole document; the set is replay-safe and FAIL CLOSED.
        $serialAllocations = [];
        foreach ($snapshot['effects'] as $effect) {
            $sdid = (int) ($effect['source_detail_id'] ?? 0);
            foreach ($effect['serial_allocation'] ?? [] as $a) {
                $sidx = (int) $a['sidx'];
                $entry = [
                    'product_serial_id' => (int) $a['product_serial_id'],
                    'serial_number' => (string) $a['serial_number'],
                    'idempotency_key' => $this->serialIdempotencyKey($documentType, $documentId, $revision, $sdid, $sidx, $operation),
                    'expected_product_id' => (int) $effect['product_id'],
                    'expected_variant_id' => $effect['product_variant_id'] !== null ? (int) $effect['product_variant_id'] : null,
                ];
                if ($documentType === self::DOC_PURCHASE && $apply) {
                    // receive: stamp the per-line purchase linkage.
                    $entry['link'] = [
                        'purchase_id' => $documentId,
                        'purchase_detail_id' => $sdid ?: null,
                    ];
                }
                $serialAllocations[] = $entry;
            }
        }
        if ($serialAllocations) {
            $serial = app(LocationAwareSerialNumberService::class);
            $serialContext = ['warehouse_id' => $warehouseId, 'inventory_location_id' => $locationId, 'reference_id' => $documentId];
            if ($documentType === self::DOC_PURCHASE) {
                $apply
                    ? $serial->receivePurchaseMany($serialAllocations, $serialContext)
                    : $serial->voidPurchaseMany($serialAllocations, $serialContext);
            } else {
                $apply
                    ? $serial->returnToSupplierMany($serialAllocations, $serialContext)
                    : $serial->reversePurchaseReturnMany($serialAllocations, $serialContext);
            }
        }

        // ===== PHASE C — ALL GENERAL INVENTORY (unchanged per-effect keys) ====
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

    /**
     * Batch-artifact idempotency key (one per allocation). Coexists with the
     * general effect key; same revision + operation => same key (replay-safe).
     * < 120 chars.
     */
    public function batchIdempotencyKey(string $documentType, int $documentId, int $revision, int $sourceDetailId, int $bidx, string $operation): string
    {
        return $documentType.':'.$documentId.':rev:'.$revision.':detail:'.$sourceDetailId.':b:'.$bidx.':'.$operation;
    }

    public function batchReferenceType(string $documentType, bool $apply): string
    {
        if ($documentType === self::DOC_PURCHASE) {
            return $apply ? self::REF_PURCHASE_BATCH : self::REF_PURCHASE_BATCH_REVERSAL;
        }

        return $apply ? self::REF_PURCHASE_RETURN_BATCH : self::REF_PURCHASE_RETURN_BATCH_REVERSAL;
    }

    /**
     * MS6-B0 — serial-artifact idempotency key (one per unit). Same shape as the
     * batch key but `:s:` instead of `:b:`; same revision + operation => same
     * key (set-level replay-safe). < 120 chars.
     */
    public function serialIdempotencyKey(string $documentType, int $documentId, int $revision, int $sourceDetailId, int $sidx, string $operation): string
    {
        return $documentType.':'.$documentId.':rev:'.$revision.':detail:'.$sourceDetailId.':s:'.$sidx.':'.$operation;
    }

    /**
     * MS5-B2 — lock + validate the batch identity of every batch_allocation
     * entry against snapshot.warehouse_id / snapshot.inventory_location_id and
     * the effect's product/variant, BEFORE reverseSnapshot()/applySnapshot()
     * mutates anything. FAIL CLOSED on: batch missing / soft-deleted / wrong
     * product / wrong variant / wrong warehouse / non-operable status.
     * Physical sufficiency for an ISSUE is enforced afterwards by issueMany().
     *
     * @throws ValidationException
     */
    private function assertSnapshotBatchAllocationSafeAndLock(array $snapshot): void
    {
        if (! Schema::hasTable('product_batches')) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'El esquema de lotes no está disponible en este tenant. FAIL CLOSED.',
            ]);
        }

        $warehouseId = (int) $snapshot['warehouse_id'];

        // effect product/variant per batch id (a batch id may repeat across effects).
        $expectBy = [];
        foreach ($snapshot['effects'] as $e) {
            foreach ($e['batch_allocation'] ?? [] as $a) {
                $expectBy[(int) $a['product_batch_id']] = [
                    'product_id' => (int) $e['product_id'],
                    'variant_id' => $e['product_variant_id'] !== null ? (int) $e['product_variant_id'] : null,
                ];
            }
        }
        if (! $expectBy) {
            return;
        }

        $batchIds = array_keys($expectBy);
        sort($batchIds, SORT_NUMERIC);

        $rows = DB::table('product_batches')->whereIn('id', $batchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (Schema::hasTable('product_batch_location_stocks')) {
            DB::table('product_batch_location_stocks')
                ->whereIn('product_batch_id', $batchIds)
                ->where('inventory_location_id', (int) $snapshot['inventory_location_id'])
                ->orderBy('product_batch_id')->orderBy('id')
                ->lockForUpdate()->get();
        }

        $operable = \App\Services\BatchLocationService::OPERABLE_STATUSES;
        foreach ($batchIds as $bid) {
            $b = $rows->get($bid);
            $expect = $expectBy[$bid];
            if (! $b || $b->deleted_at !== null) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El lote '.$bid.' del snapshot ya no existe o fue eliminado. FAIL CLOSED.']);
            }
            if ((int) $b->product_id !== $expect['product_id']) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El lote '.$bid.' no corresponde al producto del efecto. FAIL CLOSED.']);
            }
            $bVariant = $b->product_variant_id !== null ? (int) $b->product_variant_id : null;
            if ($bVariant !== $expect['variant_id']) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El lote '.$bid.' no corresponde a la variante del efecto. FAIL CLOSED.']);
            }
            if ($b->warehouse_id !== null && (int) $b->warehouse_id !== $warehouseId) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El lote '.$bid.' pertenece a otro almacén. FAIL CLOSED.']);
            }
            if (! in_array((string) $b->status, $operable, true)) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El lote '.$bid.' está en un estado no operable ('.$b->status.'). FAIL CLOSED.']);
            }
        }
    }

    /**
     * MS6-B0 — lock + validate the ProductSerial identity of every
     * serial_allocation entry: id exists, serial_number matches EXACTLY, and
     * product/variant match the effect. The specific set operation
     * (receive/void/return/reverse) then validates the exact status + location.
     * Locks by product_serial_id ASC.
     *
     * @throws ValidationException
     */
    private function assertSnapshotSerialAllocationSafeAndLock(array $snapshot): void
    {
        if (! Schema::hasTable('product_serials')) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'El esquema de series/IMEI no está disponible en este tenant. FAIL CLOSED.',
            ]);
        }

        $expectBy = [];
        foreach ($snapshot['effects'] as $e) {
            foreach ($e['serial_allocation'] ?? [] as $a) {
                $expectBy[(int) $a['product_serial_id']] = [
                    'serial_number' => (string) $a['serial_number'],
                    'product_id' => (int) $e['product_id'],
                    'variant_id' => $e['product_variant_id'] !== null ? (int) $e['product_variant_id'] : null,
                ];
            }
        }
        if (! $expectBy) {
            return;
        }

        $ids = array_keys($expectBy);
        sort($ids, SORT_NUMERIC);
        $rows = DB::table('product_serials')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($ids as $sid) {
            $r = $rows->get($sid);
            $expect = $expectBy[$sid];
            if (! $r) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El serial '.$sid.' del snapshot ya no existe. FAIL CLOSED.']);
            }
            if ((string) $r->serial_number !== $expect['serial_number']) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El serial '.$sid.' no coincide con el número de serie del snapshot. FAIL CLOSED.']);
            }
            if ((int) $r->product_id !== $expect['product_id']) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El serial '.$sid.' no corresponde al producto del efecto. FAIL CLOSED.']);
            }
            $rVariant = $r->product_variant_id !== null ? (int) $r->product_variant_id : null;
            if ($rVariant !== $expect['variant_id']) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'El serial '.$sid.' no corresponde a la variante del efecto. FAIL CLOSED.']);
            }
        }
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
