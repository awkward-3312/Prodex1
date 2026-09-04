<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — LEGACY serial / IMEI characterization for PurchasesController::store.
 *
 * Pins EXACTLY what HEAD bf56686 does on a NON location_primary (legacy)
 * warehouse when a received purchase carries `serial_numbers`. Nothing here is
 * a fix — several assertions pin behaviour MS6 will DELIBERATELY change:
 *
 *   - serial count is checked against the DOCUMENT-unit quantity, not the base
 *     quantity → a "10 boxes x12" IMEI purchase demands 10 serials while
 *     product_warehouse gains 120 (see the x12 divergence test);
 *   - a decimal quantity is round()-ed for the count check;
 *   - ProductSerial rows are created with warehouse_id set and
 *     inventory_location_id = NULL (no location-native creation path exists).
 *
 * A location_primary warehouse is NOT exercised here — it 422s in
 * LocationAwarePurchaseStockService before any row is written
 * (PurchasesLocationNativeTest::test_store_imei_tracked_product_fails_closed).
 */
class PurchaseSerialLegacyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $unit1;
    private int $unit12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('LEGACY-WH');       // NO transition state => legacy
        $this->unit1 = $this->makeUnit('*', 1);
        $this->unit12 = $this->makeUnit('*', 12);
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function imeiProduct(string $code, int $unit, array $o = []): int
    {
        return (int) $this->makeProduct(array_merge([
            'code' => $code, 'is_imei' => 1, 'unit_purchase_id' => $unit, 'cost' => 2,
        ], $o));
    }

    private function line(int $productId, int $unitId, float $qty, $serials, ?int $variantId = null): array
    {
        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'purchase_unit_id' => $unitId,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'no_unit' => 1,
            'serial_numbers' => $serials,
        ];
    }

    private function payload(array $details, string $statut = 'received', ?int $wh = null): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $wh ?? $this->wh,
            'date' => '2026-09-05',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    private function store(array $payload): void
    {
        $this->controller()->store($this->makeRequest($payload));
    }

    // =====================================================================
    // §2 — RECEIVE creates the full ledger row + movement
    // =====================================================================

    public function test_received_imei_purchase_creates_available_serials_with_warehouse_and_null_location(): void
    {
        $p = $this->imeiProduct('IM-A', $this->unit1);
        $this->seedStock($this->wh, $p, 0);

        $this->store($this->payload([
            $this->line($p, $this->unit1, 3, ['SN-1', 'SN-2', 'SN-3']),
        ]));

        // Purchase + detail + legacy imei_number text.
        $this->assertSame(1, DB::table('purchases')->count());
        $purchaseId = (int) DB::table('purchases')->value('id');
        $detail = DB::table('purchase_details')->where('purchase_id', $purchaseId)->first();
        $this->assertNotNull($detail);
        $this->assertSame('SN-1,SN-2,SN-3', $detail->imei_number);

        // One ProductSerial per serial.
        $this->assertSame(3, $this->serialCount(['purchase_id' => $purchaseId]));
        foreach (['SN-1', 'SN-2', 'SN-3'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertNotNull($row, "row for {$sn}");
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
            $this->assertSame($this->wh, (int) $row->warehouse_id);
            $this->assertNull($row->inventory_location_id, 'legacy receive never sets a location');
            $this->assertSame($p, (int) $row->product_id);
            $this->assertNull($row->product_variant_id);
            $this->assertSame($purchaseId, (int) $row->purchase_id);
            $this->assertSame((int) $detail->id, (int) $row->purchase_detail_id);
            $this->assertSame(7, (int) $row->provider_id);
            $this->assertSame(2.0, (float) $row->cost);

            // Exactly one 'purchased' movement null -> available, Purchase ref.
            $moves = $this->serialMovements($sn);
            $this->assertCount(1, $moves);
            $this->assertSame(ProductSerialMovement::ACTION_PURCHASED, $moves[0]['action']);
            $this->assertNull($moves[0]['from_status']);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $moves[0]['to_status']);
            $this->assertSame('Purchase', $moves[0]['reference_type']);
            $this->assertSame($purchaseId, (int) $moves[0]['reference_id']);
        }
    }

    // =====================================================================
    // §3 — the "10 boxes x12" divergence: serials counted in the PURCHASE
    //      unit, product_warehouse gains the BASE quantity. Pinned, NOT fixed.
    // =====================================================================

    public function test_x12_unit_serial_count_uses_document_quantity_while_stock_uses_base(): void
    {
        $p = $this->imeiProduct('IM-BOX', $this->unit12, ['cost' => 5]);
        $this->seedStock($this->wh, $p, 0);

        // 10 boxes -> legacy demands exactly 10 serials (NOT 120).
        $this->store($this->payload([
            $this->line($p, $this->unit12, 10, [
                'B01', 'B02', 'B03', 'B04', 'B05', 'B06', 'B07', 'B08', 'B09', 'B10',
            ]),
        ]));

        $this->assertSame(10, $this->serialCount(['product_id' => $p]));           // serial count = DOCUMENT unit
        $this->assertSame(120.0, $this->stockOf($this->wh, $p));                    // product_warehouse = BASE
        $this->assertSame(10.0, (float) DB::table('purchase_details')->value('quantity')); // detail keeps 10
    }

    public function test_x12_unit_rejects_the_base_count_of_serials(): void
    {
        $p = $this->imeiProduct('IM-BOX2', $this->unit12, ['cost' => 5]);
        $this->seedStock($this->wh, $p, 0);

        // Supplying 120 serials (the BASE count) is what MS6 will want, but
        // legacy rejects it: 120 != round(10).
        $many = array_map(fn ($i) => 'X'.$i, range(1, 120));

        try {
            $this->store($this->payload([$this->line($p, $this->unit12, 10, $many)]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_numbers', $e->errors());
        }
        $this->assertSame(0, $this->serialCount());
        $this->assertSame(0, DB::table('purchases')->count());
    }

    // =====================================================================
    // §4 — decimal quantity is round()-ed for the count check
    // =====================================================================

    public function test_decimal_quantity_is_rounded_for_the_serial_count_check(): void
    {
        $p = $this->imeiProduct('IM-DEC', $this->unit1);
        $this->seedStock($this->wh, $p, 0);

        // quantity 2.5 -> round() => 3 serials required.
        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 2.5, ['D1', 'D2'])])); // 2 serials
            $this->fail('expected ValidationException (2 != round(2.5))');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_numbers', $e->errors());
        }
        $this->assertSame(0, $this->serialCount());

        // 3 serials for the same 2.5 quantity is accepted.
        $this->store($this->payload([$this->line($p, $this->unit1, 2.5, ['D1', 'D2', 'D3'])]));
        $this->assertSame(3, $this->serialCount(['product_id' => $p]));
    }

    // =====================================================================
    // §5 — pending: metadata only, NO serial rows / movements
    // =====================================================================

    public function test_pending_purchase_writes_no_serial_rows_even_with_serial_numbers(): void
    {
        $p = $this->imeiProduct('IM-PEND', $this->unit1);
        $this->seedStock($this->wh, $p, 0);

        $this->store($this->payload([
            $this->line($p, $this->unit1, 2, ['P1', 'P2']),
        ], 'pending'));

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        // legacy imei_number text IS still written from the payload.
        $this->assertSame('P1,P2', DB::table('purchase_details')->value('imei_number'));
        // but no physical serial ledger.
        $this->assertSame(0, $this->serialCount());
        $this->assertSame(0, $this->serialMovementCount());
        $this->assertSame(0.0, $this->stockOf($this->wh, $p));
    }

    // =====================================================================
    // §6 — duplicate / global-uniqueness characterization
    // =====================================================================

    public function test_duplicate_serial_within_one_detail_is_422(): void
    {
        $p = $this->imeiProduct('IM-DUP1', $this->unit1);
        $this->seedStock($this->wh, $p, 0);

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 2, ['SAME', 'SAME'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('duplicate', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        $this->assertSame(0, $this->serialCount());
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_same_serial_across_two_details_of_one_purchase_rolls_back(): void
    {
        $p1 = $this->imeiProduct('IM-D2A', $this->unit1);
        $p2 = $this->imeiProduct('IM-D2B', $this->unit1);
        $this->seedStock($this->wh, $p1, 0);
        $this->seedStock($this->wh, $p2, 0);

        try {
            $this->store($this->payload([
                $this->line($p1, $this->unit1, 1, ['COLLIDE']),
                $this->line($p2, $this->unit1, 1, ['COLLIDE']),
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('already exist', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        // whole transaction rolled back.
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_details')->count());
        $this->assertSame(0, $this->serialCount());
    }

    public function test_serial_already_available_blocks_a_new_receipt(): void
    {
        $p = $this->imeiProduct('IM-EXIST', $this->unit1);
        $this->seedStock($this->wh, $p, 0);
        $this->seedSerial('LIVE', $p, ProductSerial::STATUS_AVAILABLE);

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 1, ['LIVE'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('already exist', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        $this->assertSame(1, $this->serialCount());
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_serial_already_sold_blocks_a_new_receipt(): void
    {
        $p = $this->imeiProduct('IM-SOLD', $this->unit1);
        $this->seedStock($this->wh, $p, 0);
        $this->seedSerial('GONE', $p, ProductSerial::STATUS_SOLD);

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 1, ['GONE'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_numbers', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_serial_identity_is_global_across_products(): void
    {
        $other = $this->imeiProduct('IM-OTHER', $this->unit1);
        $target = $this->imeiProduct('IM-TARGET', $this->unit1);
        $this->seedStock($this->wh, $target, 0);
        $this->seedSerial('GLOBAL-1', $other, ProductSerial::STATUS_AVAILABLE);

        try {
            $this->store($this->payload([$this->line($target, $this->unit1, 1, ['GLOBAL-1'])]));
            $this->fail('expected ValidationException — serial_number is globally unique');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('already exist', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        $this->assertSame(1, $this->serialCount());
        $this->assertSame($other, (int) $this->serialRow('GLOBAL-1')->product_id);
    }

    // ---------------------------------------------------------------------

    private function seedSerial(string $sn, int $productId, string $status, array $o = []): int
    {
        return (int) DB::table('product_serials')->insertGetId(array_merge([
            'serial_number' => $sn,
            'product_id' => $productId,
            'warehouse_id' => $this->wh,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ], $o));
    }
}
