<?php

namespace App\Services;

use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationAwareSerialNumberService extends SerialNumberService
{

    /** Request-attribute prefix for the MS5-B1 POS serial preflight selection. */
    public const POS_SERIAL_PREFLIGHT_ATTR = 'prodex_pos_serial_preflight';

    /**
     * MS5-B1 — resolve, validate and DETERMINISTICALLY row-lock every serial of
     * the whole POS cart WITHOUT mutating anything, BEFORE the general decrease.
     * Runs inside CreatePOS's transaction, from
     * PosLocationSaleStockService::apply(), after the batch preflight.
     *
     * Only for a location-aware POS sale. Non-location sales return [] and their
     * apply path (parent::sellOnSale) is unchanged.
     *
     * Serials are explicit (client-provided), so the rows locked here are
     * exactly the ones sellOnSale() re-touches afterwards: it re-selects the
     * same (serial_number, product, location, status = available) rows, which
     * this transaction already holds — no NEW ProductSerial lock is acquired
     * after InventoryService::decrease.
     *
     * Does NOT change status, does NOT create ProductSerialMovement, does NOT
     * assign sale_id / sale_detail_id.
     *
     * @return array<int, array{product_id:int, product_variant_id:?int,
     *   serial_numbers:array<int,string>, product_serial_ids:array<int,int>}>
     *
     * @throws ValidationException
     */
    public function preflightSaleSerials(Sale $sale, array $inputDetails): array
    {
        if (! $sale->inventory_location_id || ! $this->isSupported()) {
            return [];
        }

        $locationId = (int) $sale->inventory_location_id;
        $plan = [];
        $allSerialIds = [];

        foreach (array_values($inputDetails) as $i => $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0 || ! $this->productIsTracked($productId)) {
                continue;
            }

            $serials = $this->normalizeSerials($row['serial_numbers'] ?? null);
            if (empty($serials)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => 'Debes seleccionar los números de serie/IMEI que se están vendiendo.',
                ]);
            }

            $expected = (int) round((float) ($row['quantity'] ?? 0));
            if ($expected !== count($serials)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => "Debes seleccionar exactamente {$expected} serial(es) para esta línea.",
                ]);
            }

            $variantId = ! empty($row['product_variant_id']) ? (int) $row['product_variant_id'] : null;

            $ids = [];
            foreach ($serials as $serialNumber) {
                $query = ProductSerial::where('serial_number', $serialNumber)
                    ->where('product_id', $productId)
                    ->where('inventory_location_id', $locationId)
                    ->where('status', ProductSerial::STATUS_AVAILABLE);
                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);

                $matchIds = $query->pluck('id')->map(fn ($id) => (int) $id)->all();
                if (empty($matchIds)) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial {$serialNumber} no está disponible en la ubicación de venta seleccionada.",
                    ]);
                }
                foreach ($matchIds as $id) {
                    $ids[] = $id;
                }
            }

            foreach ($ids as $id) {
                $allSerialIds[] = $id;
            }

            $plan[$i] = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'serial_numbers' => $serials,
                'product_serial_ids' => array_values(array_unique($ids)),
            ];
        }

        // Deterministic lock: ProductSerial by id ASC, whole cart at once.
        $allSerialIds = array_values(array_unique($allSerialIds));
        if ($allSerialIds) {
            sort($allSerialIds, SORT_NUMERIC);
            $locked = ProductSerial::whereIn('id', $allSerialIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Revalidate every serial under lock.
            foreach ($plan as $line) {
                foreach ($line['product_serial_ids'] as $id) {
                    $serial = $locked->get($id);
                    if (! $serial
                        || (string) $serial->status !== ProductSerial::STATUS_AVAILABLE
                        || (int) $serial->product_id !== (int) $line['product_id']
                        || (int) $serial->inventory_location_id !== $locationId) {
                        throw ValidationException::withMessages([
                            'serial_numbers' => 'Un serial de la venta ya no está disponible en la ubicación de venta.',
                        ]);
                    }
                }
            }
        }

        return $plan;
    }

    public function sellOnSale(Sale $sale, SaleDetail $detail, $serials): void
    {
        if (! $sale->inventory_location_id) {
            parent::sellOnSale($sale, $detail, $serials);
            return;
        }

        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) return;

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            throw ValidationException::withMessages([
                'serial_numbers' => 'Debes seleccionar los números de serie/IMEI que se están vendiendo.',
            ]);
        }

        $expected = (int) round((float) $detail->quantity);
        if ($expected !== count($serials)) {
            throw ValidationException::withMessages([
                'serial_numbers' => "Debes seleccionar exactamente {$expected} serial(es) para esta línea.",
            ]);
        }

        DB::transaction(function () use ($sale, $detail, $serials) {
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            foreach ($serials as $serialNumber) {
                $query = ProductSerial::where('serial_number', $serialNumber)
                    ->where('product_id', (int) $detail->product_id)
                    ->where('inventory_location_id', (int) $sale->inventory_location_id)
                    ->where('status', ProductSerial::STATUS_AVAILABLE);

                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);

                $serial = $query->lockForUpdate()->first();
                if (! $serial) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial {$serialNumber} no está disponible en la ubicación de venta seleccionada.",
                    ]);
                }

                $from = $serial->status;
                $serial->status = ProductSerial::STATUS_SOLD;
                $serial->sale_id = (int) $sale->id;
                $serial->sale_detail_id = (int) $detail->id;
                $serial->client_id = $sale->client_id ? (int) $sale->client_id : null;
                $serial->save();

                ProductSerialMovement::create([
                    'product_serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'action' => ProductSerialMovement::ACTION_SOLD,
                    'from_status' => $from,
                    'to_status' => ProductSerial::STATUS_SOLD,
                    'warehouse_id' => $serial->warehouse_id,
                    'from_inventory_location_id' => (int) $sale->inventory_location_id,
                    'to_inventory_location_id' => null,
                    'reference_type' => 'Sale',
                    'reference_id' => (int) $sale->id,
                    'user_id' => auth()->id(),
                    'notes' => 'Serial vendido desde la ubicación operativa del POS.',
                    'created_at' => now(),
                ]);
            }
        }, 3);
    }

    public function returnFromSale(SaleReturn $return, SaleReturnDetails $detail, $serials): void
    {
        if (! $return->inventory_location_id) {
            parent::returnFromSale($return, $detail, $serials);
            return;
        }

        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) return;

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) return;

        $expected = (int) round((float) $detail->quantity);
        if ($expected !== count($serials)) {
            throw ValidationException::withMessages([
                'serial_numbers' => "Debes seleccionar exactamente {$expected} serial(es) para esta devolución.",
            ]);
        }

        DB::transaction(function () use ($return, $detail, $serials) {
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            foreach ($serials as $serialNumber) {
                $query = ProductSerial::where('serial_number', $serialNumber)
                    ->where('product_id', (int) $detail->product_id)
                    ->where('status', ProductSerial::STATUS_SOLD);

                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);

                if ($return->sale_id) {
                    $query->where('sale_id', (int) $return->sale_id);
                }

                $serial = $query->lockForUpdate()->first();
                if (! $serial) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial {$serialNumber} no corresponde a la venta original o ya fue devuelto.",
                    ]);
                }

                $from = $serial->status;
                $serial->status = ProductSerial::STATUS_AVAILABLE;
                $serial->inventory_location_id = (int) $return->inventory_location_id;
                // warehouse_id remains as a legacy/logistical reference only.
                $serial->save();

                ProductSerialMovement::create([
                    'product_serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'action' => ProductSerialMovement::ACTION_SALE_RETURNED,
                    'from_status' => $from,
                    'to_status' => ProductSerial::STATUS_AVAILABLE,
                    'warehouse_id' => $serial->warehouse_id,
                    'from_inventory_location_id' => null,
                    'to_inventory_location_id' => (int) $return->inventory_location_id,
                    'reference_type' => 'SaleReturn',
                    'reference_id' => (int) $return->id,
                    'user_id' => auth()->id(),
                    'notes' => 'Serial devuelto a la ubicación física de la venta original.',
                    'created_at' => now(),
                ]);
            }
        }, 3);
    }

    /**
     * MS7-B1 — reverse EVERY serial sold on the given SaleDetail rows.
     * For a LOCATION-NATIVE sale (its Sale row has inventory_location_id) this
     * is FAIL CLOSED: every serial found via `sale_detail_id` MUST still be
     * `sold`, or the whole call throws 422 — no silent skip (unlike the
     * legacy behaviour below, kept byte-for-byte for a non-native sale).
     * Restores `available` at the serial's CURRENT inventory_location_id
     * (its last physical location — never guessed, never moved).
     */
    public function reverseForSaleDetails($saleDetails): void
    {
        $saleDetails = collect($saleDetails);
        $saleIds = $saleDetails->pluck('sale_id')->filter()->unique();
        if ($saleIds->isEmpty()) {
            parent::reverseForSaleDetails($saleDetails);
            return;
        }

        $sales = Sale::whereIn('id', $saleIds)->get()->keyBy('id');

        DB::transaction(function () use ($saleDetails, $sales) {
            foreach ($saleDetails as $detail) {
                $sale = $sales->get($detail->sale_id);
                if (! $sale || ! $sale->inventory_location_id) {
                    parent::reverseForSaleDetails([$detail]);
                    continue;
                }
                if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) {
                    continue;
                }

                $serials = ProductSerial::where('sale_detail_id', $detail->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($serials as $serial) {
                    if ((string) $serial->status !== ProductSerial::STATUS_SOLD) {
                        throw ValidationException::withMessages([
                            'serial_numbers' => "El serial {$serial->serial_number} ya no está en estado 'vendido' (estado actual: {$serial->status}). FAIL CLOSED.",
                        ]);
                    }

                    $from = $serial->status;
                    $serial->status = ProductSerial::STATUS_AVAILABLE;
                    $serial->sale_id = null;
                    $serial->sale_detail_id = null;
                    $serial->client_id = null;
                    $serial->save();

                    ProductSerialMovement::create([
                        'product_serial_id' => $serial->id,
                        'serial_number' => $serial->serial_number,
                        'action' => ProductSerialMovement::ACTION_STATUS_CHANGED,
                        'from_status' => $from,
                        'to_status' => ProductSerial::STATUS_AVAILABLE,
                        'warehouse_id' => $serial->warehouse_id,
                        'from_inventory_location_id' => (int) $serial->inventory_location_id,
                        'to_inventory_location_id' => (int) $serial->inventory_location_id,
                        'reference_type' => 'SaleReversal',
                        'reference_id' => (int) $sale->id,
                        'user_id' => auth()->id(),
                        'notes' => 'Reversa location-native de venta administrativa: unidad liberada.',
                        'created_at' => now(),
                    ]);
                }
            }
        }, 3);
    }

    /**
     * MS7-B1 — reverse a location-native SaleReturn (undo: available -> sold
     * again). FAIL CLOSED — a serial that moved on since the return (sold
     * again, damaged, etc.) blocks the WHOLE call; never best-effort (unlike
     * the legacy behaviour below, kept byte-for-byte for a non-native return).
     */
    public function reverseForSaleReturn(SaleReturn $return): void
    {
        if (! $return->inventory_location_id) {
            parent::reverseForSaleReturn($return);
            return;
        }
        if (! $this->isSupported()) {
            return;
        }

        DB::transaction(function () use ($return) {
            $serialIds = ProductSerialMovement::where('reference_type', 'SaleReturn')
                ->where('reference_id', (int) $return->id)
                ->where('action', ProductSerialMovement::ACTION_SALE_RETURNED)
                ->pluck('product_serial_id')
                ->unique()
                ->all();

            $serials = ProductSerial::whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get();

            foreach ($serials as $serial) {
                if ((string) $serial->status !== ProductSerial::STATUS_AVAILABLE
                    || (int) $serial->inventory_location_id !== (int) $return->inventory_location_id) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial {$serial->serial_number} ya no está disponible en la ubicación de la devolución (estado: {$serial->status}). FAIL CLOSED.",
                    ]);
                }

                $from = $serial->status;
                $serial->status = ProductSerial::STATUS_SOLD;
                $serial->save();

                ProductSerialMovement::create([
                    'product_serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'action' => ProductSerialMovement::ACTION_STATUS_CHANGED,
                    'from_status' => $from,
                    'to_status' => ProductSerial::STATUS_SOLD,
                    'warehouse_id' => $serial->warehouse_id,
                    'from_inventory_location_id' => (int) $serial->inventory_location_id,
                    'to_inventory_location_id' => (int) $serial->inventory_location_id,
                    'reference_type' => 'SaleReturnReversal',
                    'reference_id' => (int) $return->id,
                    'user_id' => auth()->id(),
                    'notes' => 'Reversa location-native de devolución de venta: unidad vuelve a vendida.',
                    'created_at' => now(),
                ]);
            }
        }, 3);
    }

    // =====================================================================
    // MS6-B0 — ATOMIC SET operations (INACTIVE: no productive controller
    // calls them). Consumed by LocationAwarePurchaseStockService::runSnapshot
    // between the BATCH phase and the GENERAL phase.
    //
    // Every method:
    //   - REQUIRES the caller's outer transaction (never opens its own — the
    //     snapshot engine's transaction confirms/rolls back the whole set);
    //   - locks ProductSerial ids ASC and the movement idempotency keys;
    //   - validates the WHOLE set before mutating ANYTHING;
    //   - is replay-safe at set level: all keys present + fingerprints match
    //     => NO-OP; a partial set or a fingerprint mismatch => 422; never a
    //     silent partial replay.
    // =====================================================================

    /**
     * PURCHASE apply. Pre: `voided`, exact product/variant. Post: `available`
     * at the target warehouse+location, purchase linkage stamped.
     *
     * @param  array<int,array{product_serial_id:int, serial_number:string, idempotency_key:string,
     *   expected_product_id:int, expected_variant_id:?int}>  $allocations
     * @param  array{warehouse_id:int, inventory_location_id:int, reference_id:int,
     *   purchase_id?:?int, purchase_detail_id?:?int, provider_id?:?int, cost?:?float}  $context
     */
    public function receivePurchaseMany(array $allocations, array $context): void
    {
        $this->assertSetPreconditions();
        $locationId = (int) $context['inventory_location_id'];
        $warehouseId = (int) $context['warehouse_id'];

        $this->runSerialSet($allocations, [
            'action' => ProductSerialMovement::ACTION_PURCHASED,
            'reference_type' => 'Purchase',
            'reference_id' => (int) $context['reference_id'],
            'pre_status' => ProductSerial::STATUS_VOIDED,
            'pre_location' => null,
            'post_status' => ProductSerial::STATUS_AVAILABLE,
            'post_location' => $locationId,
            'movement_from_location' => null,
            'movement_to_location' => $locationId,
            'coverage_location' => $locationId,
            'link' => [
                'warehouse_id' => $warehouseId,
                'purchase_id' => $context['purchase_id'] ?? null,
                'purchase_detail_id' => $context['purchase_detail_id'] ?? null,
                'provider_id' => $context['provider_id'] ?? null,
                'cost' => $context['cost'] ?? null,
            ],
            'notes' => 'Recepción de compra location-native.',
        ]);
    }

    /**
     * PURCHASE reverse. Pre: `available` at the OLD snapshot location, exact
     * id + serial_number + product/variant. Post: `voided`, location NULL,
     * purchase provenance PRESERVED. FAIL CLOSED (no best-effort) if any unit
     * moved on — the native equivalent of legacy "already moved".
     *
     * @param  array{inventory_location_id:int, reference_id:int}  $context  OLD snapshot location
     */
    public function voidPurchaseMany(array $allocations, array $context): void
    {
        $this->assertSetPreconditions();
        $oldLocationId = (int) $context['inventory_location_id'];

        $this->runSerialSet($allocations, [
            'action' => ProductSerialMovement::ACTION_STATUS_CHANGED,
            'reference_type' => 'PurchaseReversal',
            'reference_id' => (int) $context['reference_id'],
            'pre_status' => ProductSerial::STATUS_AVAILABLE,
            'pre_location' => $oldLocationId,
            'post_status' => ProductSerial::STATUS_VOIDED,
            'post_location' => null,
            'movement_from_location' => $oldLocationId,
            'movement_to_location' => null,
            'coverage_location' => $oldLocationId,
            'link' => [], // purchase_id / purchase_detail_id KEPT for provenance
            'notes' => 'Reversa de compra location-native: unidad anulada.',
        ]);
    }

    /**
     * PURCHASE RETURN apply. Pre: `available` at the exact location. Post:
     * `returned_supplier`; inventory_location_id on the ProductSerial is KEPT
     * (its last physical location) so a reverse can restore it exactly.
     *
     * @param  array{inventory_location_id:int, reference_id:int}  $context
     */
    public function returnToSupplierMany(array $allocations, array $context): void
    {
        $this->assertSetPreconditions();
        $locationId = (int) $context['inventory_location_id'];

        $this->runSerialSet($allocations, [
            'action' => ProductSerialMovement::ACTION_PURCHASE_RETURNED,
            'reference_type' => 'PurchaseReturn',
            'reference_id' => (int) $context['reference_id'],
            'pre_status' => ProductSerial::STATUS_AVAILABLE,
            'pre_location' => $locationId,
            'post_status' => ProductSerial::STATUS_RETURNED_SUPPLIER,
            'post_location' => $locationId, // KEEP — do NOT null the ProductSerial location
            'movement_from_location' => $locationId,
            'movement_to_location' => null,
            'coverage_location' => $locationId,
            'link' => [],
            'notes' => 'Devolución a proveedor location-native.',
        ]);
    }

    /**
     * PURCHASE RETURN reverse. Pre: `returned_supplier` at the exact (OLD
     * snapshot) location. Post: `available` at that same (restored) location.
     * FAIL CLOSED — never best-effort (unlike legacy reverseForPurchaseReturn).
     *
     * MS6-B0.0.1 — the coverage gate applies HERE too: a completed return left
     * `general == COUNT(available serials)` at the location (the returned units
     * are `returned_supplier`, not counted). Before turning them back to
     * `available` the location must still be consistent — otherwise the reverse
     * would silently "cure" (or spread) a drift. general is validated at the
     * OLD snapshot location.
     *
     * @param  array{inventory_location_id:int, reference_id:int}  $context
     */
    public function reversePurchaseReturnMany(array $allocations, array $context): void
    {
        $this->assertSetPreconditions();
        $locationId = (int) $context['inventory_location_id'];

        $this->runSerialSet($allocations, [
            'action' => ProductSerialMovement::ACTION_STATUS_CHANGED,
            'reference_type' => 'PurchaseReturnReversal',
            'reference_id' => (int) $context['reference_id'],
            'pre_status' => ProductSerial::STATUS_RETURNED_SUPPLIER,
            'pre_location' => $locationId,
            'post_status' => ProductSerial::STATUS_AVAILABLE,
            'post_location' => $locationId,
            'movement_from_location' => null,
            'movement_to_location' => $locationId,
            'coverage_location' => $locationId,
            'link' => [],
            'notes' => 'Reversa de devolución a proveedor location-native.',
        ]);
    }

    // ------------------------------------------------------------------
    // Shared set runner
    // ------------------------------------------------------------------

    /**
     * @param  array<int,array{product_serial_id:int, serial_number:string, idempotency_key:string,
     *   expected_product_id?:int, expected_variant_id?:?int}>  $allocations
     */
    private function runSerialSet(array $allocations, array $spec): void
    {
        $allocations = array_values($allocations);
        if (empty($allocations)) {
            return;
        }

        // ---- idempotency: lock the movement keys, decide replay vs fresh ----
        $keys = [];
        foreach ($allocations as $a) {
            $k = (string) ($a['idempotency_key'] ?? '');
            if ($k === '') {
                throw ValidationException::withMessages(['serial_tracking' => 'Falta la clave de idempotencia de un serial.']);
            }
            $keys[] = $k;
        }

        $existing = ProductSerialMovement::whereIn('idempotency_key', $keys)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('idempotency_key');

        $fingerprints = [];
        foreach ($allocations as $a) {
            $fingerprints[(string) $a['idempotency_key']] = $this->serialFingerprint($a, $spec);
        }

        if ($existing->count() > 0) {
            if ($existing->count() !== count($keys)) {
                throw ValidationException::withMessages([
                    'serial_transition' => 'Replay parcial de una operación de series: no se puede continuar. FAIL CLOSED.',
                ]);
            }
            foreach ($keys as $k) {
                $mv = $existing->get($k);
                if (! $mv || (string) $mv->idempotency_fingerprint !== $fingerprints[$k]) {
                    throw ValidationException::withMessages([
                        'serial_transition' => 'La operación de series no coincide con el movimiento ya registrado (fingerprint). FAIL CLOSED.',
                    ]);
                }
            }

            return; // full, consistent replay => NO-OP.
        }

        // ---- fresh operation: lock serial rows ASC, validate ALL ----
        $ids = array_values(array_unique(array_map(fn ($a) => (int) $a['product_serial_id'], $allocations)));
        sort($ids, SORT_NUMERIC);
        $rows = ProductSerial::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->assertSetCoverageReady($allocations, $spec);

        foreach ($allocations as $a) {
            $id = (int) $a['product_serial_id'];
            $row = $rows->get($id);
            if (! $row) {
                throw ValidationException::withMessages(['serial_transition' => "El serial {$id} del snapshot ya no existe. FAIL CLOSED."]);
            }
            if ((string) $row->serial_number !== (string) $a['serial_number']) {
                throw ValidationException::withMessages(['serial_transition' => "El serial {$id} no coincide con el número de serie del snapshot. FAIL CLOSED."]);
            }
            if (isset($a['expected_product_id']) && (int) $row->product_id !== (int) $a['expected_product_id']) {
                throw ValidationException::withMessages(['serial_transition' => "El serial {$a['serial_number']} no corresponde al producto del efecto. FAIL CLOSED."]);
            }
            if (array_key_exists('expected_variant_id', $a)) {
                $rowVariant = $row->product_variant_id !== null ? (int) $row->product_variant_id : null;
                $expVariant = $a['expected_variant_id'] !== null ? (int) $a['expected_variant_id'] : null;
                if ($rowVariant !== $expVariant) {
                    throw ValidationException::withMessages(['serial_transition' => "El serial {$a['serial_number']} no corresponde a la variante del efecto. FAIL CLOSED."]);
                }
            }
            if ((string) $row->status !== $spec['pre_status']) {
                throw ValidationException::withMessages([
                    'serial_transition' => "El serial {$a['serial_number']} está en estado '{$row->status}' (se esperaba '{$spec['pre_status']}'). FAIL CLOSED.",
                ]);
            }
            $rowLoc = $row->inventory_location_id !== null ? (int) $row->inventory_location_id : null;
            if ($rowLoc !== $spec['pre_location']) {
                throw ValidationException::withMessages([
                    'serial_transition' => "El serial {$a['serial_number']} no está en la ubicación esperada para esta operación. FAIL CLOSED.",
                ]);
            }
        }

        // ---- mutate ALL, then movements ALL ----
        $userId = function_exists('auth') ? auth()->id() : null;
        $now = now();
        foreach ($allocations as $a) {
            $row = $rows->get((int) $a['product_serial_id']);
            $from = (string) $row->status;

            $row->status = $spec['post_status'];
            $row->inventory_location_id = $spec['post_location'];
            $link = array_merge($spec['link'] ?? [], $a['link'] ?? []);
            foreach ($link as $col => $val) {
                if ($val !== null) {
                    $row->{$col} = $val;
                }
            }
            $row->save();

            ProductSerialMovement::create([
                'product_serial_id' => (int) $row->id,
                'serial_number' => (string) $row->serial_number,
                'action' => $spec['action'],
                'from_status' => $from,
                'to_status' => $spec['post_status'],
                'warehouse_id' => $row->warehouse_id,
                'from_inventory_location_id' => $spec['movement_from_location'],
                'to_inventory_location_id' => $spec['movement_to_location'],
                'reference_type' => $spec['reference_type'],
                'reference_id' => $spec['reference_id'],
                'user_id' => $userId,
                'notes' => $spec['notes'] ?? null,
                'idempotency_key' => (string) $a['idempotency_key'],
                'idempotency_fingerprint' => $fingerprints[(string) $a['idempotency_key']],
                'created_at' => $now,
            ]);
        }
    }

    private function serialFingerprint(array $alloc, array $spec): string
    {
        return md5(json_encode([
            (int) $alloc['product_serial_id'],
            (string) $alloc['serial_number'],
            $spec['action'],
            $spec['pre_status'],
            $spec['post_status'],
            $spec['movement_from_location'],
            $spec['movement_to_location'],
            $spec['reference_type'],
            (int) $spec['reference_id'],
        ]));
    }

    /**
     * §23 / MS6-B0.0.1 — pre-state coverage FAIL CLOSED for the
     * (product, variant, location) groups this set touches. ALL four set
     * operations validate it (including reversePurchaseReturnMany: a completed
     * return leaves general == COUNT(available serials), so the reverse must
     * not be able to silently cure/spread a drift). The guard only no-ops if a
     * caller explicitly passes no coverage_location.
     */
    private function assertSetCoverageReady(array $allocations, array $spec): void
    {
        if (! array_key_exists('coverage_location', $spec) || $spec['coverage_location'] === null) {
            return;
        }
        $locationId = (int) $spec['coverage_location'];
        $coverage = app(SerialInventoryCoverageService::class);

        $groups = [];
        foreach ($allocations as $a) {
            $pid = (int) ($a['expected_product_id'] ?? 0);
            $vid = array_key_exists('expected_variant_id', $a) && $a['expected_variant_id'] !== null ? (int) $a['expected_variant_id'] : null;
            if ($pid <= 0) {
                continue;
            }
            $groups[$pid.':'.($vid ?? 0)] = [$pid, $vid];
        }

        foreach ($groups as [$pid, $vid]) {
            $c = $coverage->coverageForLocation($locationId, $pid, $vid);
            if (! $c['is_ready']) {
                throw ValidationException::withMessages([
                    'serial_transition' => "Desfase de series para el producto {$pid} en la ubicación {$locationId} "
                        ."(general {$c['general_quantity']} vs {$c['available_serial_count']} seriales disponibles). FAIL CLOSED.",
                ]);
            }
        }
    }

    private function assertSetPreconditions(): void
    {
        if (! $this->isSupported()) {
            throw ValidationException::withMessages(['serial_tracking' => 'El esquema de series/IMEI no está disponible en este tenant.']);
        }
        if (DB::transactionLevel() <= 0) {
            throw new \LogicException('LocationAwareSerialNumberService set operations must run inside the caller\'s transaction.');
        }
    }
}
