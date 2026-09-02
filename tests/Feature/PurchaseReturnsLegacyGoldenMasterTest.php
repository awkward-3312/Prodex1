<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesReturnController;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * PRE-LOCATION-NATIVE BASELINE — MS0 golden master for PurchasesReturnController.
 *
 * Characterizes EXACTLY what `main` does today for store / update / destroy,
 * focused on the legacy `product_warehouse.qte` writers.
 *
 * NOTE the asymmetry with PurchasesController: a purchase return only moves
 * stock when `statut == 'completed'` (purchases use `'received'`). See the MS0
 * report. Batch + serial are OFF (tables absent); the serial-resync gap in
 * update() is NOT exercised or fixed here (MS3/MS6).
 */
class PurchaseReturnsLegacyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->legacyOwner();
    }

    private function controller(): PurchasesReturnController
    {
        return new PurchasesReturnController;
    }

    private function line(array $o = []): array
    {
        return array_merge([
            'product_id' => null,
            'product_variant_id' => null,
            'purchase_unit_id' => null,
            'quantity' => 1,
            'Unit_cost' => 1,
            'tax_percent' => 0,
            'tax_method' => '1',
            'discount' => 0,
            'discount_Method' => '2',
            'subtotal' => 1,
            'imei_number' => null,
            'no_unit' => 1,
        ], $o);
    }

    private function payload(int $warehouseId, array $details, string $statut = 'completed'): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $warehouseId,
            'purchase_id' => null,
            'date' => '2026-09-04',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0,
            'TaxNet' => 0,
            'discount' => 0,
            'shipping' => 0,
            'GrandTotal' => 50,
            'details' => $details,
        ];
    }

    /** create a return, return [returnId, detailId] */
    private function createReturn(int $wh, int $unit, int $product, float $qty, string $statut = 'completed'): array
    {
        $req = $this->makeRequest($this->payload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => $qty]),
        ], $statut));
        $this->controller()->store($req);
        $r = DB::table('purchase_returns')->latest('id')->first();
        $d = DB::table('purchase_return_details')->where('purchase_return_id', $r->id)->first();

        return [(int) $r->id, (int) $d->id];
    }

    // =====================================================================
    // STORE  (supplier <- inventory : stock decreases)
    // =====================================================================

    public function test_store_completed_decreases_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);

        $req = $this->makeRequest($this->payload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 5, 'Unit_cost' => 2]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(1, DB::table('purchase_returns')->count());
        $r = DB::table('purchase_returns')->first();
        $this->assertSame('completed', $r->statut);
        $this->assertStringStartsWith('RP_', $r->Ref);

        $this->assertSame(1, DB::table('purchase_return_details')->count());
        $d = DB::table('purchase_return_details')->first();
        $this->assertEquals($product, $d->product_id);
        $this->assertEquals(5, (float) $d->quantity);

        $this->assertSame(15.0, $this->stockOf($wh, $product)); // 20 - 5
    }

    public function test_store_non_completed_status_does_not_change_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);

        $req = $this->makeRequest($this->payload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 5]),
        ], 'pending'));
        $this->controller()->store($req);

        $this->assertSame(1, DB::table('purchase_returns')->count());
        $this->assertSame(1, DB::table('purchase_return_details')->count());
        $this->assertSame(20.0, $this->stockOf($wh, $product)); // unchanged
    }

    public function test_store_completed_respects_unit_conversion(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('/', 6);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);

        $req = $this->makeRequest($this->payload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 12]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(18.0, $this->stockOf($wh, $product)); // 20 - (12 / 6)
    }

    // =====================================================================
    // UPDATE
    // =====================================================================

    public function test_update_reverses_old_then_applies_new(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);
        [$rid, $did] = $this->createReturn($wh, $unit, $product, 5);
        $this->assertSame(15.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest($this->payload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 8]),
        ]), 'PUT');
        $this->controller()->update($req, $rid);

        // 15 + 5 (reverse) - 8 (apply) = 12
        $this->assertSame(12.0, $this->stockOf($wh, $product));
        $this->assertEquals(8, (float) DB::table('purchase_return_details')->where('id', $did)->value('quantity'));
    }

    public function test_update_change_quantity_final_stock_is_exact(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);
        [$rid, $did] = $this->createReturn($wh, $unit, $product, 5);

        $req = $this->makeRequest($this->payload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 3]),
        ]), 'PUT');
        $this->controller()->update($req, $rid);

        $this->assertSame(17.0, $this->stockOf($wh, $product)); // 15 + 5 - 3
    }

    /**
     * LATENT: update() moves the stock effect between warehouses (reverse in the
     * ORIGINAL warehouse_id, apply in the request warehouse_id) but it never
     * writes the new warehouse_id back to the purchase_returns row.
     */
    public function test_update_change_warehouse_moves_stock_but_record_keeps_old_warehouse(): void
    {
        $wh1 = $this->makeWarehouse('WH1');
        $wh2 = $this->makeWarehouse('WH2');
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh1, $product, 20);
        $this->seedStock($wh2, $product, 20);
        [$rid, $did] = $this->createReturn($wh1, $unit, $product, 5);
        $this->assertSame(15.0, $this->stockOf($wh1, $product));

        $req = $this->makeRequest($this->payload($wh2, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 5]),
        ]), 'PUT');
        $this->controller()->update($req, $rid);

        $this->assertSame(20.0, $this->stockOf($wh1, $product)); // reversed in old
        $this->assertSame(15.0, $this->stockOf($wh2, $product)); // applied in new
        $this->assertEquals($wh1, DB::table('purchase_returns')->where('id', $rid)->value('warehouse_id')); // NOT updated
    }

    // =====================================================================
    // DESTROY  (stock comes back)
    // =====================================================================

    public function test_destroy_completed_restores_exact_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);
        [$rid] = $this->createReturn($wh, $unit, $product, 5);
        $this->assertSame(15.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest([], 'DELETE');
        $this->controller()->destroy($req, $rid);

        $this->assertSame(20.0, $this->stockOf($wh, $product));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
        // LATENT: PurchaseReturnDetails has no SoftDeletes trait even though the
        // table carries a deleted_at column, so details()->delete() HARD-deletes.
        $this->assertSame(0, DB::table('purchase_return_details')->where('purchase_return_id', $rid)->count());
    }

    public function test_destroy_partial_return_restores_only_the_partial_quantity(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);
        [$rid] = $this->createReturn($wh, $unit, $product, 3); // partial
        $this->assertSame(17.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest([], 'DELETE');
        $this->controller()->destroy($req, $rid);

        $this->assertSame(20.0, $this->stockOf($wh, $product));
    }

    public function test_destroy_total_return_restores_full_quantity(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 10);
        [$rid] = $this->createReturn($wh, $unit, $product, 10); // total
        $this->assertSame(0.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest([], 'DELETE');
        $this->controller()->destroy($req, $rid);

        $this->assertSame(10.0, $this->stockOf($wh, $product));
    }

    // =====================================================================
    // TRANSACTION / FAILURE
    // =====================================================================

    public function test_store_line_failure_leaves_no_partial_return_or_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $good = $this->makeProduct();
        $this->seedStock($wh, $good, 20);

        $payload = $this->payload($wh, [
            $this->line(['product_id' => $good, 'purchase_unit_id' => $unit, 'quantity' => 5]),
            $this->line(['product_id' => null, 'purchase_unit_id' => $unit, 'quantity' => 2]), // NOT NULL violation
        ]);
        $req = $this->makeRequest($payload);

        try {
            $this->controller()->store($req);
            $this->fail('expected the invalid line to abort the transaction');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, DB::table('purchase_return_details')->count());
        $this->assertSame(20.0, $this->stockOf($wh, $good));
    }

    public function test_update_line_failure_rolls_back_reverse_and_reapply(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 20);
        [$rid, $did] = $this->createReturn($wh, $unit, $product, 5);
        $this->assertSame(15.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest($this->payload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 3]),
            $this->line(['id' => 999999, 'product_id' => null, 'purchase_unit_id' => $unit, 'quantity' => 2]),
        ]), 'PUT');

        try {
            $this->controller()->update($req, $rid);
            $this->fail('expected the invalid new line to abort the transaction');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(15.0, $this->stockOf($wh, $product)); // fully restored
        $this->assertSame(1, DB::table('purchase_return_details')->where('purchase_return_id', $rid)->count());
        $this->assertEquals(5, (float) DB::table('purchase_return_details')->where('id', $did)->value('quantity'));
    }
}
