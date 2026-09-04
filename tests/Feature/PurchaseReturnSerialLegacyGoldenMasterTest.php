<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesReturnController;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — LEGACY serial characterization for PurchasesReturnController
 * (store / update / destroy) on a non location_primary warehouse.
 *
 *  §12 completed return: user-selected `available` serials -> `returned_supplier`,
 *      warehouse_id + inventory_location_id UNCHANGED, `purchase_returned`
 *      movement, count checked against detail.quantity (DOCUMENT unit).
 *  §13 LEGACY BUG (characterized, NOT fixed): update() never reverses/reapplies
 *      the serial ledger, so an edit leaves it stale.
 *  §14 destroy(): `returned_supplier` -> `available` via reverseForPurchaseReturn
 *      (+ a `status_changed` movement); a serial that already moved on is left
 *      untouched (best-effort).
 */
class PurchaseReturnSerialLegacyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $unit1;
    private int $unit5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('LEGACY-WH-R');
        $this->unit1 = $this->makeUnit('*', 1);
        $this->unit5 = $this->makeUnit('*', 5);
    }

    private function controller(): PurchasesReturnController
    {
        return new PurchasesReturnController;
    }

    private function imeiProduct(string $code): int
    {
        return (int) $this->makeProduct(['code' => $code, 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 2]);
    }

    /** seed an origin (legacy-received) serial. */
    private function seedSerial(string $sn, int $productId, int $originPurchaseId = 0, string $status = ProductSerial::STATUS_AVAILABLE, ?int $variantId = null): int
    {
        return (int) DB::table('product_serials')->insertGetId([
            'serial_number' => $sn,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'warehouse_id' => $this->wh,
            'inventory_location_id' => null,
            'status' => $status,
            'purchase_id' => $originPurchaseId ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function line(int $productId, int $unitId, float $qty, array $serials, $id = null): array
    {
        $row = [
            'product_id' => $productId,
            'product_variant_id' => null,
            'purchase_unit_id' => $unitId,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'imei_number' => implode(',', $serials),
            'no_unit' => 1,
            'serial_numbers' => $serials,
        ];
        if ($id !== null) {
            $row['id'] = $id;
        }

        return $row;
    }

    private function payload(array $details, string $statut = 'completed', ?int $purchaseId = null): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $this->wh,
            'purchase_id' => $purchaseId,
            'date' => '2026-09-06',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 50,
            'details' => $details,
        ];
    }

    private function createReturn(array $details, string $statut = 'completed', ?int $purchaseId = null): array
    {
        $this->controller()->store($this->makeRequest($this->payload($details, $statut, $purchaseId)));
        $rid = (int) DB::table('purchase_returns')->latest('id')->value('id');
        $did = (int) DB::table('purchase_return_details')->where('purchase_return_id', $rid)->value('id');

        return [$rid, $did];
    }

    // =====================================================================
    // §12 — completed store
    // =====================================================================

    public function test_completed_return_marks_selected_serials_returned_to_supplier(): void
    {
        $p = $this->imeiProduct('IM-RS');
        $this->seedStock($this->wh, $p, 10);
        $this->seedSerial('RS-1', $p, 100);
        $this->seedSerial('RS-2', $p, 100);
        $keepId = $this->seedSerial('RS-3', $p, 100); // not returned

        [$rid, $did] = $this->createReturn([$this->line($p, $this->unit1, 2, ['RS-1', 'RS-2'])], 'completed', 100);

        foreach (['RS-1', 'RS-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $row->status);
            $this->assertSame($this->wh, (int) $row->warehouse_id, 'warehouse_id unchanged');
            $this->assertNull($row->inventory_location_id, 'inventory_location_id unchanged (null)');

            $moves = $this->serialMovements($sn);
            $last = end($moves);
            $this->assertSame(ProductSerialMovement::ACTION_PURCHASE_RETURNED, $last['action']);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $last['from_status']);
            $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $last['to_status']);
            $this->assertSame('PurchaseReturn', $last['reference_type']);
            $this->assertSame($rid, (int) $last['reference_id']);
        }
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, DB::table('product_serials')->where('id', $keepId)->value('status'));
        $this->assertSame(8.0, $this->stockOf($this->wh, $p)); // 10 - 2
    }

    public function test_completed_return_serial_count_uses_document_unit_not_base(): void
    {
        // unit x5 : returning quantity 2 moves 10 base units of stock, but the
        // legacy serial count still demands exactly 2 serials.
        $p = (int) $this->makeProduct(['code' => 'IM-RS5', 'is_imei' => 1, 'unit_purchase_id' => $this->unit5, 'cost' => 2]);
        $this->seedStock($this->wh, $p, 50);
        $this->seedSerial('R5-1', $p, 200);
        $this->seedSerial('R5-2', $p, 200);

        [$rid] = $this->createReturn([$this->line($p, $this->unit5, 2, ['R5-1', 'R5-2'])], 'completed', 200);

        $this->assertSame(2, $this->serialCount(['status' => ProductSerial::STATUS_RETURNED_SUPPLIER]));
        $this->assertSame(40.0, $this->stockOf($this->wh, $p)); // 50 - (2 * 5)
    }

    public function test_completed_return_rejects_a_non_available_serial(): void
    {
        $p = $this->imeiProduct('IM-RSX');
        $this->seedStock($this->wh, $p, 10);
        $this->seedSerial('RX-1', $p, 300, ProductSerial::STATUS_SOLD);
        $this->seedSerial('RX-2', $p, 300);

        try {
            $this->createReturn([$this->line($p, $this->unit1, 2, ['RX-1', 'RX-2'])], 'completed', 300);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_numbers', $e->errors());
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('RX-1')->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RX-2')->status);
    }

    public function test_pending_return_does_not_touch_the_serial_ledger(): void
    {
        $p = $this->imeiProduct('IM-RSP');
        $this->seedStock($this->wh, $p, 10);
        $this->seedSerial('RP-1', $p, 400);

        $this->createReturn([$this->line($p, $this->unit1, 1, ['RP-1'])], 'pending', 400);

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RP-1')->status);
        $this->assertSame(0, $this->serialMovementCount(), 'no purchase_returned movement for a pending return');
    }

    // =====================================================================
    // §13 — LEGACY BUG: update() leaves the serial ledger stale
    // =====================================================================

    public function test_update_legacy_behavior_characterization_serial_ledger_is_left_stale(): void
    {
        $p = $this->imeiProduct('IM-RUPD');
        $this->seedStock($this->wh, $p, 20);
        $this->seedSerial('U-1', $p, 500);
        $this->seedSerial('U-2', $p, 500);
        $this->seedSerial('U-3', $p, 500);

        [$rid, $did] = $this->createReturn([$this->line($p, $this->unit1, 2, ['U-1', 'U-2'])], 'completed', 500);
        $this->assertSame(2, $this->serialCount(['status' => ProductSerial::STATUS_RETURNED_SUPPLIER]));
        $movesBefore = $this->serialMovementCount();

        // Edit: change the line to quantity 1 and a DIFFERENT serial selection.
        $this->controller()->update(
            $this->makeRequest($this->payload([$this->line($p, $this->unit1, 1, ['U-3'], $did)], 'completed', 500), 'PUT'),
            $rid
        );

        // The controller reversed/re-applied product_warehouse for the qty change...
        $this->assertSame(19.0, $this->stockOf($this->wh, $p)); // 20 - 2 (reverse) + 2 ... -1 net vs original: 20 -> 18 -> +2 -> -1 => 19

        // ...but the serial ledger is UNTOUCHED: U-1/U-2 stay returned_supplier,
        // U-3 stays available, no new movements.
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('U-1')->status);
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('U-2')->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('U-3')->status);
        $this->assertSame($movesBefore, $this->serialMovementCount(), 'no reverse, no reapply on the serial ledger');
    }

    // =====================================================================
    // §14 — destroy() brings serials back to available
    // =====================================================================

    public function test_destroy_completed_return_restores_serials_to_available(): void
    {
        $p = $this->imeiProduct('IM-RDEL');
        $this->seedStock($this->wh, $p, 10);
        $this->seedSerial('D-1', $p, 600);
        $this->seedSerial('D-2', $p, 600);

        [$rid] = $this->createReturn([$this->line($p, $this->unit1, 2, ['D-1', 'D-2'])], 'completed', 600);
        $this->assertSame(2, $this->serialCount(['status' => ProductSerial::STATUS_RETURNED_SUPPLIER]));

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $rid);

        foreach (['D-1', 'D-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status, 'serial back to available');
            $moves = $this->serialMovements($sn);
            $last = end($moves);
            $this->assertSame(ProductSerialMovement::ACTION_STATUS_CHANGED, $last['action']);
            $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $last['from_status']);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $last['to_status']);
        }
        $this->assertSame(10.0, $this->stockOf($this->wh, $p)); // 8 -> back to 10
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
    }

    public function test_destroy_is_best_effort_and_skips_a_serial_that_moved_on(): void
    {
        $p = $this->imeiProduct('IM-RBE');
        $this->seedStock($this->wh, $p, 10);
        $this->seedSerial('BE-1', $p, 700);
        $this->seedSerial('BE-2', $p, 700);

        [$rid] = $this->createReturn([$this->line($p, $this->unit1, 2, ['BE-1', 'BE-2'])], 'completed', 700);

        // BE-1 was somehow re-received / manually flipped away from returned_supplier.
        DB::table('product_serials')->where('serial_number', 'BE-1')->update([
            'status' => ProductSerial::STATUS_AVAILABLE, 'updated_at' => now(),
        ]);

        $be1MovesBefore = $this->serialMovements('BE-1'); // just the purchase_returned one

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $rid);

        // BE-1: reverseForPurchaseReturn only acts on rows STILL returned_supplier,
        // so it is skipped — no extra movement, status left as-is.
        $be1MovesAfter = $this->serialMovements('BE-1');
        $this->assertCount(count($be1MovesBefore), $be1MovesAfter, 'BE-1 got no reverse movement');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BE-1')->status);

        // BE-2: normally restored, with a status_changed movement.
        $be2Moves = $this->serialMovements('BE-2');
        $be2Last = $be2Moves[count($be2Moves) - 1];
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BE-2')->status);
        $this->assertSame(ProductSerialMovement::ACTION_STATUS_CHANGED, $be2Last['action']);
    }
}
