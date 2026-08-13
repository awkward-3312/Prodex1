<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetails;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * SerialNumberService
 *
 * Handles serial / IMEI side-effects for stock movements.
 *
 * Designed to be 100% opt-in:
 * - If the migration hasn't run, every method short-circuits without error.
 * - If a product doesn't have is_imei = true, its rows are ignored.
 * - The existing product_warehouse.qte accounting is left to the callers — this
 *   service only mirrors per-unit serial rows and writes the movement log.
 *
 * Enforcement is strict: when a serialized product line carries serials, the
 * count must equal the line quantity, every serial must be globally unique on
 * purchase, and every serial must be in-stock & available on sale. Any failure
 * throws a ValidationException, which rolls back the surrounding DB::transaction
 * and renders a 422 to the client.
 */
class SerialNumberService
{
    protected ?bool $supported = null;

    public function isSupported(): bool
    {
        if ($this->supported !== null) {
            return $this->supported;
        }

        $this->supported = Schema::hasTable('product_serials')
            && Schema::hasTable('product_serial_movements')
            && Schema::hasColumn('products', 'is_imei');

        return $this->supported;
    }

    /**
     * Whether a single product opted in to serial / IMEI tracking.
     */
    public function productIsTracked($productId): bool
    {
        if (! $this->isSupported() || ! $productId) {
            return false;
        }

        return (bool) Product::whereKey($productId)->value('is_imei');
    }

    /**
     * Normalise a raw row value into a clean list of serial strings.
     * Accepts an array, or a newline/comma/semicolon/space separated string
     * (barcode scanners and bulk paste both land here). Trims, drops blanks,
     * and rejects duplicates inside the same submission.
     */
    public function normalizeSerials($raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $raw = preg_split('/[\r\n,;\t]+/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $s) {
            $s = trim((string) $s);
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Duplicate serial number in this entry: {$s}"],
                ]);
            }
            $seen[$key] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * Create serial rows for a freshly-stored purchase detail.
     *
     * @param  array  $serials  Raw serial list from the request row.
     */
    public function receiveOnPurchase(Purchase $purchase, PurchaseDetail $detail, $serials): void
    {
        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) {
            return;
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        $this->assertCountMatches($serials, $detail->quantity);
        $this->assertGloballyUnique($serials);

        $warehouseId = (int) $purchase->warehouse_id;
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = ProductSerial::create([
                'serial_number' => $serial,
                'product_id' => (int) $detail->product_id,
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'status' => ProductSerial::STATUS_AVAILABLE,
                'purchase_id' => (int) $purchase->id,
                'purchase_detail_id' => (int) $detail->id,
                'provider_id' => $purchase->provider_id ? (int) $purchase->provider_id : null,
                'cost' => $detail->cost !== null ? (float) $detail->cost : null,
            ]);

            $this->logMovement($row, ProductSerialMovement::ACTION_PURCHASED, null, ProductSerial::STATUS_AVAILABLE, [
                'warehouse_id' => $warehouseId,
                'reference_type' => 'Purchase',
                'reference_id' => (int) $purchase->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Reverse serial rows for a set of PurchaseDetail rows about to be re-applied
     * by an update. Only `available` serials may be reversed; if any unit has
     * already moved on (sold / returned) we refuse the edit so the ledger can't
     * be corrupted — the user must reverse the downstream document first.
     */
    public function reverseForPurchaseDetails($purchaseDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        foreach ($purchaseDetails as $detail) {
            if (! $detail || ! isset($detail->id)) {
                continue;
            }

            $serials = ProductSerial::where('purchase_detail_id', $detail->id)->get();
            foreach ($serials as $s) {
                if ($s->status !== ProductSerial::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => [
                            "Cannot edit this purchase: serial {$s->serial_number} has already moved (status: {$s->status}). Reverse the related sale/return first.",
                        ],
                    ]);
                }
            }

            $ids = $serials->pluck('id')->all();
            if (! empty($ids)) {
                ProductSerialMovement::whereIn('product_serial_id', $ids)->delete();
                ProductSerial::whereIn('id', $ids)->delete();
            }
        }
    }

    /**
     * Re-create serial rows for an updated purchase. Expects the input rows and
     * persisted PurchaseDetail rows aligned by position (caller builds the pairs).
     */
    public function resyncPurchaseSerials(Purchase $purchase, array $alignedInput, $alignedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $alignedDetails = collect($alignedDetails)->values();
        foreach (array_values($alignedInput) as $i => $row) {
            $detail = $alignedDetails->get($i);
            if (! $detail) {
                continue;
            }
            $this->receiveOnPurchase($purchase, $detail, $row['serial_numbers'] ?? null);
        }
    }

    /**
     * Mark selected serials as sold for a freshly-stored sale detail.
     *
     * @param  array  $serials  Raw serial list from the request row.
     */
    public function sellOnSale(Sale $sale, SaleDetail $detail, $serials): void
    {
        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) {
            return;
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        $this->assertCountMatches($serials, $detail->quantity);

        $warehouseId = (int) $sale->warehouse_id;
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = $this->findSellableSerial($serial, (int) $detail->product_id, $variantId, $warehouseId);

            $from = $row->status;
            $row->status = ProductSerial::STATUS_SOLD;
            $row->sale_id = (int) $sale->id;
            $row->sale_detail_id = (int) $detail->id;
            $row->client_id = $sale->client_id ? (int) $sale->client_id : null;
            $row->save();

            $this->logMovement($row, ProductSerialMovement::ACTION_SOLD, $from, ProductSerial::STATUS_SOLD, [
                'warehouse_id' => $warehouseId,
                'reference_type' => 'Sale',
                'reference_id' => (int) $sale->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Release serials that were sold by a set of SaleDetail rows, back to
     * `available`, before an update re-applies the new selection. Serials that
     * have since moved on (e.g. already sale-returned) are left untouched.
     */
    public function reverseForSaleDetails($saleDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $userId = Auth::id();
        foreach ($saleDetails as $detail) {
            if (! $detail || ! isset($detail->id)) {
                continue;
            }

            $serials = ProductSerial::where('sale_detail_id', $detail->id)->get();
            foreach ($serials as $s) {
                if ($s->status !== ProductSerial::STATUS_SOLD) {
                    continue;
                }
                $from = $s->status;
                $s->status = ProductSerial::STATUS_AVAILABLE;
                $s->sale_id = null;
                $s->sale_detail_id = null;
                $s->client_id = null;
                $s->save();

                $this->logMovement($s, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, ProductSerial::STATUS_AVAILABLE, [
                    'warehouse_id' => $s->warehouse_id,
                    'user_id' => $userId,
                    'notes' => 'Released by sale edit',
                ]);
            }
        }
    }

    /**
     * Re-sell serials for an updated sale. Input rows and persisted SaleDetail
     * rows are aligned by position (caller builds the pairs).
     */
    public function resellSaleSerials(Sale $sale, array $alignedInput, $alignedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $alignedDetails = collect($alignedDetails);
        foreach (array_values($alignedInput) as $i => $row) {
            $detail = $alignedDetails->get($i);
            if (! $detail) {
                continue;
            }
            $this->sellOnSale($sale, $detail, $row['serial_numbers'] ?? null);
        }
    }

    /**
     * Locate a serial that may be sold from the given product/variant/warehouse,
     * or throw a 422 describing exactly why it can't.
     */
    protected function findSellableSerial(string $serial, int $productId, ?int $variantId, int $warehouseId): ProductSerial
    {
        $row = ProductSerial::where('serial_number', $serial)->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number not found in stock: {$serial}"],
            ]);
        }

        if ((int) $row->product_id !== $productId
            || ($variantId !== null && (int) $row->product_variant_id !== $variantId)) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
            ]);
        }

        if ((int) $row->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number is not in the selected warehouse: {$serial}"],
            ]);
        }

        if ($row->status !== ProductSerial::STATUS_AVAILABLE) {
            throw ValidationException::withMessages([
                'serial_numbers' => ["Serial number is not available (status: {$row->status}): {$serial}"],
            ]);
        }

        return $row;
    }

    /**
     * Available serials for a product (+ variant + warehouse), for the sale picker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableSerialsForSale(int $productId, ?int $variantId, int $warehouseId, ?int $includeSaleId = null): array
    {
        if (! $this->isSupported() || $productId <= 0 || $warehouseId <= 0) {
            return [];
        }

        $query = ProductSerial::query()
            ->forProduct($productId)
            ->forWarehouse($warehouseId)
            ->where(function ($q) use ($includeSaleId) {
                // In-stock units, plus (on edit) the ones already sold on this sale
                // so they show up pre-selected in the picker.
                $q->where('status', ProductSerial::STATUS_AVAILABLE);
                if ($includeSaleId) {
                    $q->orWhere(function ($w) use ($includeSaleId) {
                        $w->where('status', ProductSerial::STATUS_SOLD)
                            ->where('sale_id', $includeSaleId);
                    });
                }
            });

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->orderBy('id', 'asc')
            ->get(['id', 'serial_number', 'status'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'serial_number' => (string) $r->serial_number,
                'status' => (string) $r->status,
            ])
            ->all();
    }

    // ============================================================
    // Sale Return — customer returns units back to available stock
    // ============================================================

    /**
     * Apply serial returns for a freshly-stored sale return. Input rows and
     * persisted SaleReturnDetails are aligned by key (caller builds the pairs).
     */
    public function applyForSaleReturn(SaleReturn $return, array $inputDetails, $persistedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $persisted = collect($persistedDetails);
        foreach ($inputDetails as $key => $row) {
            $detail = $persisted->get($key);
            if (! $detail) {
                continue;
            }
            $this->returnFromSale($return, $detail, $row['serial_numbers'] ?? null);
        }
    }

    /**
     * Return the selected serials of a sale-return line back to available stock.
     */
    public function returnFromSale(SaleReturn $return, SaleReturnDetails $detail, $serials): void
    {
        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) {
            return;
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        $this->assertCountMatches($serials, $detail->quantity);

        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $warehouseId = (int) $return->warehouse_id;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = ProductSerial::where('serial_number', $serial)->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number not found: {$serial}"],
                ]);
            }
            if ((int) $row->product_id !== (int) $detail->product_id
                || ($variantId !== null && (int) $row->product_variant_id !== $variantId)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
                ]);
            }
            if ($row->status !== ProductSerial::STATUS_SOLD) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number was not sold and cannot be returned (status: {$row->status}): {$serial}"],
                ]);
            }
            // If the return is linked to a specific sale, the serial must have been sold on it.
            if ($return->sale_id && (int) $row->sale_id !== (int) $return->sale_id) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number was not sold on the referenced sale: {$serial}"],
                ]);
            }

            $from = $row->status;
            // Back to available stock in the return warehouse; keep sale linkage as
            // "last sold on" so a deleted return can be reversed and for history.
            $row->status = ProductSerial::STATUS_AVAILABLE;
            $row->warehouse_id = $warehouseId;
            $row->save();

            $this->logMovement($row, ProductSerialMovement::ACTION_SALE_RETURNED, $from, ProductSerial::STATUS_AVAILABLE, [
                'warehouse_id' => $warehouseId,
                'reference_type' => 'SaleReturn',
                'reference_id' => (int) $return->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Reverse a sale return (e.g. on delete): re-mark its returned serials as sold.
     * Best-effort — a serial that has since moved on is left untouched.
     */
    public function reverseForSaleReturn(SaleReturn $return): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $serialIds = ProductSerialMovement::where('reference_type', 'SaleReturn')
            ->where('reference_id', (int) $return->id)
            ->where('action', ProductSerialMovement::ACTION_SALE_RETURNED)
            ->pluck('product_serial_id')
            ->unique()
            ->all();

        $userId = Auth::id();
        foreach (ProductSerial::whereIn('id', $serialIds)->get() as $row) {
            if ($row->status !== ProductSerial::STATUS_AVAILABLE) {
                continue; // already re-sold or otherwise moved on
            }
            $from = $row->status;
            $row->status = ProductSerial::STATUS_SOLD;
            $row->save();
            $this->logMovement($row, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, ProductSerial::STATUS_SOLD, [
                'warehouse_id' => $row->warehouse_id,
                'user_id' => $userId,
                'notes' => 'Sale return reversed',
            ]);
        }
    }

    // ============================================================
    // Purchase Return — units returned to supplier, out of inventory
    // ============================================================

    /**
     * Apply serial returns for a freshly-stored purchase return. Input rows and
     * persisted PurchaseReturnDetails are aligned by key (caller builds the pairs).
     */
    public function applyForPurchaseReturn(PurchaseReturn $return, array $inputDetails, $persistedDetails): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $persisted = collect($persistedDetails);
        foreach ($inputDetails as $key => $row) {
            $detail = $persisted->get($key);
            if (! $detail) {
                continue;
            }
            $this->returnToSupplier($return, $detail, $row['serial_numbers'] ?? null);
        }
    }

    /**
     * Mark the selected serials of a purchase-return line as returned to supplier.
     */
    public function returnToSupplier(PurchaseReturn $return, PurchaseReturnDetails $detail, $serials): void
    {
        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) {
            return;
        }

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            return;
        }

        $this->assertCountMatches($serials, $detail->quantity);

        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $userId = Auth::id();

        foreach ($serials as $serial) {
            $row = ProductSerial::where('serial_number', $serial)->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number not found: {$serial}"],
                ]);
            }
            if ((int) $row->product_id !== (int) $detail->product_id
                || ($variantId !== null && (int) $row->product_variant_id !== $variantId)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number does not belong to this product: {$serial}"],
                ]);
            }
            if ($row->status !== ProductSerial::STATUS_AVAILABLE) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Only available serials can be returned to supplier (status: {$row->status}): {$serial}"],
                ]);
            }
            // If the return is linked to a specific purchase, the serial must originate from it.
            if ($return->purchase_id && (int) $row->purchase_id !== (int) $return->purchase_id) {
                throw ValidationException::withMessages([
                    'serial_numbers' => ["Serial number does not belong to the referenced purchase: {$serial}"],
                ]);
            }

            $from = $row->status;
            $row->status = ProductSerial::STATUS_RETURNED_SUPPLIER;
            $row->save();

            $this->logMovement($row, ProductSerialMovement::ACTION_PURCHASE_RETURNED, $from, ProductSerial::STATUS_RETURNED_SUPPLIER, [
                'warehouse_id' => $row->warehouse_id,
                'reference_type' => 'PurchaseReturn',
                'reference_id' => (int) $return->id,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Reverse a purchase return (e.g. on delete): bring its serials back to available.
     * Best-effort — a serial that has since moved on is left untouched.
     */
    public function reverseForPurchaseReturn(PurchaseReturn $return): void
    {
        if (! $this->isSupported()) {
            return;
        }

        $serialIds = ProductSerialMovement::where('reference_type', 'PurchaseReturn')
            ->where('reference_id', (int) $return->id)
            ->where('action', ProductSerialMovement::ACTION_PURCHASE_RETURNED)
            ->pluck('product_serial_id')
            ->unique()
            ->all();

        $userId = Auth::id();
        foreach (ProductSerial::whereIn('id', $serialIds)->get() as $row) {
            if ($row->status !== ProductSerial::STATUS_RETURNED_SUPPLIER) {
                continue;
            }
            $from = $row->status;
            $row->status = ProductSerial::STATUS_AVAILABLE;
            $row->save();
            $this->logMovement($row, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, ProductSerial::STATUS_AVAILABLE, [
                'warehouse_id' => $row->warehouse_id,
                'user_id' => $userId,
                'notes' => 'Purchase return reversed',
            ]);
        }
    }

    /**
     * Strict check: number of serials must equal the line quantity.
     */
    protected function assertCountMatches(array $serials, $quantity): void
    {
        $expected = (int) round((float) $quantity);
        if (count($serials) !== $expected) {
            throw ValidationException::withMessages([
                'serial_numbers' => [
                    'The number of serial numbers ('.count($serials).') must match the quantity ('.$expected.').',
                ],
            ]);
        }
    }

    /**
     * Strict check: none of these serials may already exist anywhere in the ledger.
     */
    protected function assertGloballyUnique(array $serials): void
    {
        $existing = ProductSerial::whereIn('serial_number', $serials)
            ->pluck('serial_number')
            ->all();

        if (! empty($existing)) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['Serial number(s) already exist in the system: '.implode(', ', $existing)],
            ]);
        }
    }

    /**
     * Manually change a serial's status among the in-stock states
     * (available / damaged / reserved). Serials driven by documents
     * (sold / returned_supplier) can't be changed here.
     */
    public function changeStatus(ProductSerial $serial, string $newStatus, ?string $note = null): void
    {
        $manual = [
            ProductSerial::STATUS_AVAILABLE,
            ProductSerial::STATUS_DAMAGED,
            ProductSerial::STATUS_RESERVED,
        ];

        if (! in_array($newStatus, $manual, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid target status.'],
            ]);
        }

        if (! in_array($serial->status, $manual, true)) {
            throw ValidationException::withMessages([
                'status' => ["A {$serial->status} serial can't be changed manually; it is controlled by sales/returns."],
            ]);
        }

        if ($serial->status === $newStatus) {
            return;
        }

        $from = $serial->status;
        $serial->status = $newStatus;
        $serial->save();

        $this->logMovement($serial, ProductSerialMovement::ACTION_STATUS_CHANGED, $from, $newStatus, [
            'warehouse_id' => $serial->warehouse_id,
            'user_id' => Auth::id(),
            'notes' => $note,
        ]);
    }

    /**
     * Whether an update payload actually carries serial data on any line. Used to
     * avoid touching the serial ledger on edits from forms that don't yet collect
     * serials (which would otherwise wipe previously-captured serials).
     */
    public function payloadHasSerials($details): bool
    {
        if (! is_array($details)) {
            return false;
        }
        foreach ($details as $row) {
            if (is_array($row) && array_key_exists('serial_numbers', $row) && ! empty($row['serial_numbers'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append an audit row to the movement log.
     */
    public function logMovement(ProductSerial $serial, string $action, ?string $fromStatus, ?string $toStatus, array $meta = []): void
    {
        ProductSerialMovement::create([
            'product_serial_id' => (int) $serial->id,
            'serial_number' => (string) $serial->serial_number,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'warehouse_id' => $meta['warehouse_id'] ?? $serial->warehouse_id,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'user_id' => $meta['user_id'] ?? Auth::id(),
            'notes' => $meta['notes'] ?? null,
            'created_at' => now(),
        ]);
    }
}
