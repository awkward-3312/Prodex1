<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * PRE-LOCATION-NATIVE BASELINE — MS0 golden master for PurchasesController.
 *
 * Characterizes EXACTLY what `main` does today for
 * store / update / destroy / delete_by_selection / store_import_purchases,
 * focused on the legacy `product_warehouse.qte` writers that MS1/MS2 will
 * replace. Batch + serial tracking are OFF (tables absent) so those code paths
 * stay inert — they get dedicated coverage in MS5/MS6.
 *
 * These tests assert current behaviour, INCLUDING behaviour that is arguably
 * wrong (see the class-level "LATENT" notes and the MS0 report). Nothing here
 * is a fix; MS1/MS2 will deliberately flip the assertions that change.
 */
class PurchasesLegacyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->legacyOwner();
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    /** @return array<string,mixed> one purchase-line payload row */
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
            'no_unit' => 1,
        ], $o);
    }

    private function storePayload(int $warehouseId, array $details, string $statut = 'received'): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $warehouseId,
            'date' => '2026-09-01',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0,
            'TaxNet' => 0,
            'discount' => 0,
            'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    // =====================================================================
    // STORE
    // =====================================================================

    public function test_store_received_creates_purchase_details_and_increments_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 5);

        $req = $this->makeRequest($this->storePayload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 10, 'Unit_cost' => 3]),
        ]));

        $this->controller()->store($req);

        $this->assertSame(1, DB::table('purchases')->count());
        $purchase = DB::table('purchases')->first();
        $this->assertSame('received', $purchase->statut);
        $this->assertStringStartsWith('PR_', $purchase->Ref);
        $this->assertSame('unpaid', $purchase->payment_statut);

        $this->assertSame(1, DB::table('purchase_details')->count());
        $detail = DB::table('purchase_details')->first();
        $this->assertEquals($product, $detail->product_id);
        $this->assertEquals(10, (float) $detail->quantity);
        $this->assertEquals(3, (float) $detail->cost);
        $this->assertEquals($purchase->id, $detail->purchase_id);

        // 5 + 10 = 15
        $this->assertSame(15.0, $this->stockOf($wh, $product));
    }

    public function test_store_received_respects_unit_conversion_multiply(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 12); // box of 12
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);

        $req = $this->makeRequest($this->storePayload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 2]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(24.0, $this->stockOf($wh, $product)); // 2 * 12
    }

    public function test_store_received_respects_unit_conversion_divide(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('/', 6);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);

        $req = $this->makeRequest($this->storePayload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 12]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(2.0, $this->stockOf($wh, $product)); // 12 / 6
    }

    public function test_store_pending_creates_document_but_does_not_touch_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 5);

        $req = $this->makeRequest($this->storePayload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 10]),
        ], 'pending'));
        $this->controller()->store($req);

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertSame(5.0, $this->stockOf($wh, $product)); // unchanged
    }

    /**
     * LATENT: when no product_warehouse row exists for the (warehouse, product)
     * key, a received purchase silently adds NOTHING — no row is created, no
     * warning. Characterized here on purpose.
     */
    public function test_store_received_without_product_warehouse_row_adds_no_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct(); // no seedStock()

        $req = $this->makeRequest($this->storePayload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 10]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertSame(0, DB::table('product_warehouse')->count()); // nothing created
    }

    public function test_store_received_variant_line_increments_variant_scoped_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);
        $this->seedStock($wh, $product, 3);                 // base row
        $this->seedStock($wh, $product, 1, $variant);       // variant row

        $req = $this->makeRequest($this->storePayload($wh, [
            $this->line([
                'product_id' => $product,
                'product_variant_id' => $variant,
                'purchase_unit_id' => $unit,
                'quantity' => 4,
            ]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(5.0, $this->stockOf($wh, $product, $variant)); // 1 + 4
        $this->assertSame(3.0, $this->stockOf($wh, $product));           // base untouched
    }

    // =====================================================================
    // UPDATE
    // =====================================================================

    /** create a received purchase, return [purchaseId, detailId] */
    private function createReceived(int $wh, int $unit, int $product, float $qty, string $statut = 'received'): array
    {
        $req = $this->makeRequest($this->storePayload($wh, [
            $this->line(['product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => $qty]),
        ], $statut));
        $this->controller()->store($req);
        $p = DB::table('purchases')->latest('id')->first();
        $d = DB::table('purchase_details')->where('purchase_id', $p->id)->first();

        return [(int) $p->id, (int) $d->id];
    }

    private function updatePayload(int $warehouseId, array $details, string $statut = 'received'): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $warehouseId,
            'date' => '2026-09-02',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0,
            'TaxNet' => 0,
            'discount' => 0,
            'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    public function test_update_received_to_received_reverses_old_then_applies_new(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);
        [$pid, $did] = $this->createReceived($wh, $unit, $product, 10);
        $this->assertSame(10.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest($this->updatePayload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 4]),
        ]), 'PUT');
        $this->controller()->update($req, $pid);

        // 10 - 10 (reverse) + 4 (apply) = 4
        $this->assertSame(4.0, $this->stockOf($wh, $product));
        $this->assertEquals(4, (float) DB::table('purchase_details')->where('id', $did)->value('quantity'));
    }

    public function test_update_pending_to_received_applies_stock_once(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);
        [$pid, $did] = $this->createReceived($wh, $unit, $product, 8, 'pending');
        $this->assertSame(0.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest($this->updatePayload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 8]),
        ], 'received'), 'PUT');
        $this->controller()->update($req, $pid);

        $this->assertSame(8.0, $this->stockOf($wh, $product)); // applied exactly once
    }

    public function test_update_received_to_pending_reverses_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);
        [$pid, $did] = $this->createReceived($wh, $unit, $product, 7);
        $this->assertSame(7.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest($this->updatePayload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 7]),
        ], 'pending'), 'PUT');
        $this->controller()->update($req, $pid);

        $this->assertSame(0.0, $this->stockOf($wh, $product)); // reversed, not re-applied
    }

    public function test_update_change_quantity_final_stock_is_exact(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);
        [$pid, $did] = $this->createReceived($wh, $unit, $product, 10);

        $req = $this->makeRequest($this->updatePayload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 25]),
        ]), 'PUT');
        $this->controller()->update($req, $pid);

        $this->assertSame(25.0, $this->stockOf($wh, $product)); // 10 - 10 + 25
    }

    /**
     * The frontend disables the warehouse select once lines exist, but the
     * backend still supports a warehouse change: it reverses in the OLD
     * warehouse and applies in the NEW one.
     */
    public function test_update_change_warehouse_via_backend_moves_the_effect(): void
    {
        $wh1 = $this->makeWarehouse('WH1');
        $wh2 = $this->makeWarehouse('WH2');
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh1, $product, 0);
        $this->seedStock($wh2, $product, 0);
        [$pid, $did] = $this->createReceived($wh1, $unit, $product, 10);
        $this->assertSame(10.0, $this->stockOf($wh1, $product));

        $req = $this->makeRequest($this->updatePayload($wh2, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 10]),
        ]), 'PUT');
        $this->controller()->update($req, $pid);

        $this->assertSame(0.0, $this->stockOf($wh1, $product));  // reversed in old
        $this->assertSame(10.0, $this->stockOf($wh2, $product)); // applied in new
        $this->assertEquals($wh2, DB::table('purchases')->where('id', $pid)->value('warehouse_id'));
    }

    // =====================================================================
    // DESTROY
    // =====================================================================

    public function test_destroy_received_reverses_stock_and_soft_deletes_header(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);
        [$pid] = $this->createReceived($wh, $unit, $product, 9);
        $this->assertSame(9.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest([], 'DELETE');
        $this->controller()->destroy($req, $pid);

        $this->assertSame(0.0, $this->stockOf($wh, $product));
        $this->assertNotNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
        $this->assertSame(0, DB::table('purchase_details')->where('purchase_id', $pid)->count()); // hard-deleted
    }

    public function test_destroy_pending_does_not_touch_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 3);
        [$pid] = $this->createReceived($wh, $unit, $product, 9, 'pending');
        $this->assertSame(3.0, $this->stockOf($wh, $product));

        $req = $this->makeRequest([], 'DELETE');
        $this->controller()->destroy($req, $pid);

        $this->assertSame(3.0, $this->stockOf($wh, $product)); // unchanged
        $this->assertNotNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
    }

    public function test_destroy_is_blocked_when_a_purchase_return_exists(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);
        [$pid] = $this->createReceived($wh, $unit, $product, 5);
        $this->assertSame(5.0, $this->stockOf($wh, $product));

        DB::table('purchase_returns')->insert([
            'user_id' => 1, 'date' => '2026-09-02', 'Ref' => 'RP_0001',
            'purchase_id' => $pid, 'provider_id' => 1, 'warehouse_id' => $wh,
            'GrandTotal' => 0, 'payment_statut' => 'unpaid', 'statut' => 'completed',
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);

        $req = $this->makeRequest([], 'DELETE');
        $this->controller()->destroy($req, $pid);

        // 403 short-circuit: stock untouched and purchase NOT deleted
        $this->assertSame(5.0, $this->stockOf($wh, $product));
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
    }

    // =====================================================================
    // IMPORT
    // =====================================================================

    private function csvFile(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        $fh = fopen($path, 'w');
        fwrite($fh, "productcode;qty\n");
        foreach ($rows as [$code, $qty]) {
            fwrite($fh, "{$code};{$qty}\n");
        }
        fclose($fh);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    private function importPayload(int $warehouseId, string $statut = 'received'): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $warehouseId,
            'date' => '2026-09-03',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
        ];
    }

    public function test_import_received_increments_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct(['code' => 'IMP1', 'unit_purchase_id' => $unit, 'cost' => 3]);
        $this->seedStock($wh, $product, 0);

        $file = $this->csvFile([['IMP1', 4]]);
        $req = $this->makeRequest($this->importPayload($wh), 'POST', ['products' => $file]);
        $this->controller()->store_import_purchases($req);

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertSame(4.0, $this->stockOf($wh, $product));
        $this->assertEquals(12.0, (float) DB::table('purchases')->value('GrandTotal')); // 4 * 3
    }

    public function test_import_pending_does_not_touch_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct(['code' => 'IMP2', 'unit_purchase_id' => $unit, 'cost' => 3]);
        $this->seedStock($wh, $product, 0);

        $file = $this->csvFile([['IMP2', 4]]);
        $req = $this->makeRequest($this->importPayload($wh, 'pending'), 'POST', ['products' => $file]);
        $this->controller()->store_import_purchases($req);

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(0.0, $this->stockOf($wh, $product));
    }

    /**
     * LATENT: store_import_purchases() looks up product_warehouse WITHOUT a
     * product_variant_id filter, so it always writes the variant-NULL row and
     * silently ignores variant stock. Characterized here.
     */
    public function test_import_ignores_variants_and_writes_the_variant_null_row(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct(['code' => 'IMP3', 'unit_purchase_id' => $unit, 'cost' => 1]);
        $variant = $this->makeVariant($product);
        $this->seedStock($wh, $product, 0);              // variant-null row
        $this->seedStock($wh, $product, 0, $variant);    // variant row

        $file = $this->csvFile([['IMP3', 5]]);
        $req = $this->makeRequest($this->importPayload($wh), 'POST', ['products' => $file]);
        $this->controller()->store_import_purchases($req);

        $this->assertSame(5.0, $this->stockOf($wh, $product));           // variant-null row got it
        $this->assertSame(0.0, $this->stockOf($wh, $product, $variant)); // variant row ignored
    }

    // =====================================================================
    // TRANSACTION / FAILURE
    // =====================================================================

    public function test_store_line_failure_leaves_no_partial_purchase_or_stock(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $good = $this->makeProduct();
        $this->seedStock($wh, $good, 5);

        // Second line has product_id = null -> PurchaseDetail::insert() violates
        // the NOT NULL constraint AFTER line 1 already mutated product_warehouse.
        $payload = $this->storePayload($wh, [
            $this->line(['product_id' => $good, 'purchase_unit_id' => $unit, 'quantity' => 10]),
            $this->line(['product_id' => null, 'purchase_unit_id' => $unit, 'quantity' => 3]),
        ]);
        $req = $this->makeRequest($payload);

        try {
            $this->controller()->store($req);
            $this->fail('expected the invalid line to abort the transaction');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_details')->count());
        $this->assertSame(5.0, $this->stockOf($wh, $good)); // line 1 rolled back
    }

    public function test_update_line_failure_rolls_back_reverse_and_reapply(): void
    {
        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 1);
        $product = $this->makeProduct();
        $this->seedStock($wh, $product, 0);
        [$pid, $did] = $this->createReceived($wh, $unit, $product, 10);
        $this->assertSame(10.0, $this->stockOf($wh, $product));

        // Existing line kept + a new line with product_id = null -> Create() fails
        // AFTER the reverse (-10) and the existing line's re-apply already ran.
        $req = $this->makeRequest($this->updatePayload($wh, [
            $this->line(['id' => $did, 'product_id' => $product, 'purchase_unit_id' => $unit, 'quantity' => 6]),
            $this->line(['id' => 999999, 'product_id' => null, 'purchase_unit_id' => $unit, 'quantity' => 2]),
        ]), 'PUT');

        try {
            $this->controller()->update($req, $pid);
            $this->fail('expected the invalid new line to abort the transaction');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(10.0, $this->stockOf($wh, $product)); // fully restored
        $this->assertSame(1, DB::table('purchase_details')->where('purchase_id', $pid)->count());
        $this->assertEquals(10, (float) DB::table('purchase_details')->where('id', $did)->value('quantity'));
        $this->assertSame('received', DB::table('purchases')->where('id', $pid)->value('statut'));
    }
}
