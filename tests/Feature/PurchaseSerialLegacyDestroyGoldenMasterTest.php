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
 * MS6-B0A — LEGACY serial characterization for PurchasesController::destroy
 * and ::delete_by_selection (non location_primary warehouse). Pins current
 * behaviour; NOT a fix.
 *
 *  §11.A single destroy, all serials `available` -> serial rows AND their
 *        movements are HARD-DELETED, purchase soft-deleted.
 *  §11.B single destroy, one serial `sold` -> 422, TOTAL rollback (purchase,
 *        serial, stock all intact).
 *  §11.C bulk delete_by_selection: LATENT BUG — the legacy branch does NOT
 *        call SerialNumberService at all, so serials are NEITHER guarded NOR
 *        deleted. Both purchases are removed; serial rows are left orphaned.
 *        (Single destroy and bulk delete disagree — pinned as-is.)
 */
class PurchaseSerialLegacyDestroyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $unit1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('LEGACY-WH-D');
        $this->unit1 = $this->makeUnit('*', 1);
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function imeiProduct(string $code): int
    {
        return (int) $this->makeProduct(['code' => $code, 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 2]);
    }

    private function line(int $productId, float $qty, array $serials): array
    {
        return [
            'product_id' => $productId,
            'product_variant_id' => null,
            'purchase_unit_id' => $this->unit1,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'no_unit' => 1,
            'serial_numbers' => $serials,
        ];
    }

    private function payload(array $details): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $this->wh,
            'date' => '2026-09-05',
            'statut' => 'received',
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    private function createReceived(int $productId, float $qty, array $serials): int
    {
        $this->controller()->store($this->makeRequest($this->payload([$this->line($productId, $qty, $serials)])));

        return (int) DB::table('purchases')->latest('id')->value('id');
    }

    // =====================================================================
    // §11.A — single destroy, all available
    // =====================================================================

    public function test_destroy_hard_deletes_available_serials_and_their_movements(): void
    {
        $p = $this->imeiProduct('IM-DEL-A');
        $this->seedStock($this->wh, $p, 0);
        $pid = $this->createReceived($p, 2, ['DA-1', 'DA-2']);
        $this->assertSame(2, $this->serialCount());
        $this->assertSame(2, $this->serialMovementCount());
        $this->assertSame(2.0, $this->stockOf($this->wh, $p));

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);

        $this->assertSame(0, $this->serialCount(), 'serial rows hard-deleted');
        $this->assertSame(0, $this->serialMovementCount(), 'serial movements hard-deleted');
        $this->assertSame(0.0, $this->stockOf($this->wh, $p), 'legacy product_warehouse reversed');
        $this->assertNotNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'), 'purchase soft-deleted');
    }

    // =====================================================================
    // §11.B — single destroy blocked by a downstream-moved serial
    // =====================================================================

    public function test_destroy_is_blocked_and_fully_rolled_back_when_a_serial_is_sold(): void
    {
        $p = $this->imeiProduct('IM-DEL-B');
        $this->seedStock($this->wh, $p, 4);
        $pid = $this->createReceived($p, 2, ['DB-1', 'DB-2']);
        $this->assertSame(6.0, $this->stockOf($this->wh, $p)); // 4 + 2

        // Simulate a POS/Sale that consumed DB-1.
        DB::table('product_serials')->where('serial_number', 'DB-1')->update([
            'status' => ProductSerial::STATUS_SOLD, 'sale_id' => 555, 'updated_at' => now(),
        ]);

        try {
            $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('already moved', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }

        // TOTAL rollback.
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'), 'purchase NOT deleted');
        $this->assertSame(1, (int) DB::table('purchase_details')->where('purchase_id', $pid)->count());
        $this->assertSame(2, $this->serialCount(), 'no serial row deleted');
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('DB-1')->status);
        $this->assertSame(6.0, $this->stockOf($this->wh, $p), 'stock reversal rolled back');
    }

    // =====================================================================
    // §11.C — bulk delete: LATENT BUG — serials NOT touched at all
    // =====================================================================

    public function test_bulk_delete_legacy_behavior_characterization_does_not_touch_serials(): void
    {
        $p = $this->imeiProduct('IM-BULK');
        $this->seedStock($this->wh, $p, 10);
        $reversible = $this->createReceived($p, 2, ['BK-1', 'BK-2']);
        $withSold = $this->createReceived($p, 2, ['BK-3', 'BK-4']);
        $this->assertSame(14.0, $this->stockOf($this->wh, $p)); // 10 + 2 + 2

        // BK-3 has already been sold downstream.
        DB::table('product_serials')->where('serial_number', 'BK-3')->update([
            'status' => ProductSerial::STATUS_SOLD, 'sale_id' => 777, 'updated_at' => now(),
        ]);

        // Bulk delete BOTH purchases.
        $this->controller()->delete_by_selection(
            $this->makeRequest(['selectedIds' => [$reversible, $withSold]], 'POST')
        );

        // Current legacy behaviour: NO serial guard, NO serial delete on the
        // bulk path. Both purchases are soft-deleted; product_warehouse is
        // reversed for both; the serial ledger is completely untouched.
        $this->assertNotNull(DB::table('purchases')->where('id', $reversible)->value('deleted_at'));
        $this->assertNotNull(DB::table('purchases')->where('id', $withSold)->value('deleted_at'));
        $this->assertSame(10.0, $this->stockOf($this->wh, $p), 'both product_warehouse reversals applied');

        $this->assertSame(4, $this->serialCount(), 'ALL serial rows still present — bulk delete never touched them');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BK-1')->status);
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('BK-3')->status);
        $this->assertSame(4, $this->serialMovementCount(), 'purchased movements untouched');
        // The orphan: serials still point at now-deleted purchases.
        $this->assertSame($reversible, (int) $this->serialRow('BK-1')->purchase_id);
    }
}
