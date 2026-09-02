<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\InventoryTransitionState as Mode;
use App\Services\WarehouseInventoryModeResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS4 — store_import_purchases wired to LocationAwarePurchaseStockService, but
 * ONLY for warehouses in MODE_LOCATION_PRIMARY + healthy. Every other mode
 * (legacy_only / shadow_compare / dual_write / no row) keeps the exact legacy
 * import flow (covered by PurchasesLegacyGoldenMasterTest).
 *
 * The CSV format is productcode;qty — Product.code ONLY, no variant column — so
 * a location-native import CANNOT resolve a ProductVariant: variant / batch /
 * IMEI rows FAIL CLOSED (422). NO product_warehouse / BatchService /
 * SerialNumberService on the native path.
 *
 * NOT production-ready as a package: batch (MS5), serial / IMEI (MS6) and
 * provenance (MS7) are still legacy / pending.
 */
class PurchaseImportsLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $unit;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('CD-IMPORT');
        $this->unit = $this->makeUnit('*', 1);
        $this->loc = $this->makeInventoryLocation($this->wh);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    /** @param array<int,array{0:string,1:int|float}> $rows */
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

    private function importPayload(string $statut = 'received', $locationId = 'DEFAULT', $warehouseId = null): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $warehouseId ?? $this->wh,
            'inventory_location_id' => $locationId === 'DEFAULT' ? $this->loc : $locationId,
            'date' => '2026-09-03',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
        ];
    }

    private function lp(string $status = 'healthy'): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    private function runImport(UploadedFile $file, array $payload)
    {
        $req = $this->makeRequest($payload, 'POST', ['products' => $file]);

        return $this->controller()->store_import_purchases($req);
    }

    private function assertNoWrites(): void
    {
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_details')->count());
        $this->assertSame(0, $this->movementCount());
    }

    // =====================================================================
    // ROUTING
    // =====================================================================

    public function test_no_transition_state_uses_the_legacy_import(): void
    {
        $p = $this->makeProduct(['code' => 'L1', 'unit_purchase_id' => $this->unit, 'cost' => 3]);
        $this->seedStock($this->wh, $p, 0);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['L1', 4]]), $this->importPayload());

        $this->assertNull(DB::table('purchases')->value('inventory_location_id'));
        $this->assertSame(4.0, $this->stockOf($this->wh, $p)); // legacy product_warehouse
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
    }

    /** @dataProvider legacyModes */
    public function test_non_primary_modes_use_the_legacy_import(string $mode): void
    {
        $this->setTransitionMode($this->wh, $mode, null, 'pending');
        $this->assertFalse(app(WarehouseInventoryModeResolver::class)->isLocationPrimary($this->wh));

        $p = $this->makeProduct(['code' => 'L2', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->seedStock($this->wh, $p, 0);
        $this->seedLocationStock($this->loc, $p, 0);

        // dual_write received routes through the compat mirror (out of MS4
        // scope); assert routing with a pending import.
        $statut = $mode === Mode::MODE_DUAL_WRITE ? 'pending' : 'received';
        $this->runImport($this->csvFile([['L2', 5]]), $this->importPayload($statut));

        $this->assertNull(DB::table('purchases')->value('inventory_location_id'));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
    }

    public static function legacyModes(): array
    {
        return [
            'legacy_only' => [Mode::MODE_LEGACY_ONLY],
            'shadow_compare' => [Mode::MODE_SHADOW_COMPARE],
            'dual_write' => [Mode::MODE_DUAL_WRITE],
        ];
    }

    public function test_primary_healthy_routes_to_the_engine(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'N1', 'unit_purchase_id' => $this->unit, 'cost' => 2]);
        $this->seedStock($this->wh, $p, 7);          // legacy row — must stay
        $this->seedLocationStock($this->loc, $p, 10);

        $this->runImport($this->csvFile([['N1', 4]]), $this->importPayload());

        $purchase = DB::table('purchases')->first();
        $this->assertSame($this->loc, (int) $purchase->inventory_location_id);
        $this->assertNotNull($purchase->inventory_effect_snapshot);
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertSame(14.0, $this->locStock($this->loc, $p)); // 10 + 4
        $this->assertSame(7.0, $this->stockOf($this->wh, $p));    // product_warehouse UNTOUCHED
        $this->assertSame(1, $this->movementCount('Purchase'));
        $this->assertEquals(8.0, (float) $purchase->GrandTotal);  // 4 * 2

        $snap = json_decode($purchase->inventory_effect_snapshot, true);
        $this->assertSame(1, $snap['revision']);
        $this->assertSame('purchase', $snap['document_type']);
        $this->assertSame(4.0, (float) $snap['effects'][0]['delta']);
        $detailId = (int) DB::table('purchase_details')->value('id');
        $this->assertSame($detailId, (int) $snap['effects'][0]['source_detail_id']);
    }

    public function test_primary_unhealthy_is_422_no_writes(): void
    {
        $this->lp('mismatch');
        $this->makeProduct(['code' => 'N2', 'unit_purchase_id' => $this->unit, 'cost' => 1]);

        try {
            $this->runImport($this->csvFile([['N2', 3]]), $this->importPayload());
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_primary_with_null_state_location_is_422_no_writes(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, null, 'healthy');
        $this->makeProduct(['code' => 'N3', 'unit_purchase_id' => $this->unit, 'cost' => 1]);

        $this->expectException(ValidationException::class);
        try {
            $this->runImport($this->csvFile([['N3', 3]]), $this->importPayload());
        } finally {
            $this->assertNoWrites();
        }
    }

    // =====================================================================
    // RECEIVED
    // =====================================================================

    public function test_received_simple_product_full_effect(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'R1', 'unit_purchase_id' => $this->unit, 'cost' => 5]);
        $this->seedLocationStock($this->loc, $p, 1);

        $this->runImport($this->csvFile([['R1', 6]]), $this->importPayload());

        $this->assertSame(7.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->movementCount('Purchase'));
        $d = DB::table('purchase_details')->first();
        $this->assertSame(5.0, (float) $d->cost);
        $this->assertEquals(30.0, (float) $d->total);
        $this->assertNull($d->product_variant_id);
    }

    public function test_received_unit_multiply_conversion(): void
    {
        $u = $this->makeUnit('*', 12);
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        $p = $this->makeProduct(['code' => 'R2', 'unit_purchase_id' => $u, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['R2', 3]]), $this->importPayload());

        $this->assertSame(36.0, $this->locStock($this->loc, $p)); // 3 * 12
    }

    public function test_received_unit_divide_conversion(): void
    {
        $u = $this->makeUnit('/', 4);
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        $p = $this->makeProduct(['code' => 'R3', 'unit_purchase_id' => $u, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['R3', 8]]), $this->importPayload());

        $this->assertSame(2.0, $this->locStock($this->loc, $p)); // 8 / 4
    }

    public function test_received_succeeds_with_no_product_warehouse_row(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'R4', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        // deliberately NO seedStock() -> no product_warehouse row at all.

        $this->runImport($this->csvFile([['R4', 9]]), $this->importPayload());

        $this->assertSame(9.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, DB::table('product_warehouse')->count());
        $this->assertSame(1, $this->movementCount('Purchase'));
    }

    public function test_two_rows_apply_both_with_distinct_detail_ids(): void
    {
        $this->lp();
        $a = $this->makeProduct(['code' => 'D1', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $b = $this->makeProduct(['code' => 'D2', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);

        $this->runImport($this->csvFile([['D1', 2], ['D2', 3]]), $this->importPayload());

        $this->assertSame(2.0, $this->locStock($this->loc, $a));
        $this->assertSame(3.0, $this->locStock($this->loc, $b));
        $this->assertSame(2, DB::table('purchase_details')->count());
        $this->assertSame(2, $this->movementCount('Purchase'));

        $snap = json_decode(DB::table('purchases')->value('inventory_effect_snapshot'), true);
        $ids = array_map(fn ($e) => $e['source_detail_id'], $snap['effects']);
        $this->assertCount(2, array_unique($ids));
        $this->assertNotContains(null, $ids);
    }

    // =====================================================================
    // PENDING
    // =====================================================================

    public function test_pending_saves_header_location_details_but_no_effect(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'P1', 'unit_purchase_id' => $this->unit, 'cost' => 4]);
        $this->seedLocationStock($this->loc, $p, 20);

        $this->runImport($this->csvFile([['P1', 5]]), $this->importPayload('pending'));

        $purchase = DB::table('purchases')->first();
        $this->assertSame($this->loc, (int) $purchase->inventory_location_id);
        $this->assertNull($purchase->inventory_effect_snapshot);
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertSame(20.0, $this->locStock($this->loc, $p)); // unchanged
        $this->assertSame(0, $this->movementCount());
    }

    // =====================================================================
    // ATOMICITY
    // =====================================================================

    public function test_third_row_invalid_rolls_back_everything(): void
    {
        $this->lp();
        $a = $this->makeProduct(['code' => 'A1', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $b = $this->makeProduct(['code' => 'A2', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $c = $this->makeProduct(['code' => 'A3', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        foreach ([$a, $b, $c] as $pid) {
            $this->seedLocationStock($this->loc, $pid, 0);
        }

        try {
            // third row qty 0 -> resolveImportLinesForLocationNative() fails closed.
            $this->runImport($this->csvFile([['A1', 2], ['A2', 2], ['A3', 0]]), $this->importPayload());
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // ok
        }

        $this->assertNoWrites();
        $this->assertSame(0.0, $this->locStock($this->loc, $a));
        $this->assertSame(0.0, $this->locStock($this->loc, $b));
    }

    // =====================================================================
    // DUPLICATES
    // =====================================================================

    public function test_duplicate_product_code_is_rejected_before_the_engine(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'DUP', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $res = $this->runImport($this->csvFile([['DUP', 2], ['DUP', 3]]), $this->importPayload());

        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['status']);
        $this->assertStringContainsStringIgnoringCase('duplicate', $body['msg']);
        $this->assertNoWrites();
    }

    // =====================================================================
    // VARIANT / BATCH / IMEI — FAIL CLOSED
    // =====================================================================

    public function test_variant_row_fails_closed_no_writes(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'V1', 'type' => 'is_variant', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->makeVariant($p);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['V1', 3]]), $this->importPayload());
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('variante', implode(' ', $e->errors()['products.0'] ?? ['']));
        }
        $this->assertNoWrites();
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_batch_row_fails_closed_no_writes(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'B1', 'is_batch_tracked' => true, 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        try {
            $this->runImport($this->csvFile([['B1', 3]]), $this->importPayload());
        } finally {
            $this->assertNoWrites();
        }
    }

    public function test_imei_row_fails_closed_no_writes(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'I1', 'is_imei' => 1, 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        try {
            $this->runImport($this->csvFile([['I1', 3]]), $this->importPayload());
        } finally {
            $this->assertNoWrites();
        }
    }

    // =====================================================================
    // LEGACY REGRESSION — the historical variant bug is untouched
    // =====================================================================

    public function test_legacy_import_still_writes_the_variant_null_row(): void
    {
        // no transition state -> legacy path, exactly as the golden master.
        $p = $this->makeProduct(['code' => 'LV', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $v = $this->makeVariant($p);
        $this->seedStock($this->wh, $p, 0);
        $this->seedStock($this->wh, $p, 0, $v);

        $this->runImport($this->csvFile([['LV', 5]]), $this->importPayload());

        $this->assertSame(5.0, $this->stockOf($this->wh, $p));       // variant-null row got it
        $this->assertSame(0.0, $this->stockOf($this->wh, $p, $v));   // variant row ignored
        $this->assertNull(DB::table('purchases')->value('inventory_location_id'));
    }

    // =====================================================================
    // TRANSITION BOUNDARY
    // =====================================================================

    public function test_pending_mode_status_before_mutation_is_422_no_writes(): void
    {
        // location_primary but status 'pending' (not reconciled) — routes to the
        // engine (mode check) then FAILS CLOSED inside the tx.
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'pending');
        $p = $this->makeProduct(['code' => 'T1', 'unit_purchase_id' => $this->unit, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        try {
            $this->runImport($this->csvFile([['T1', 3]]), $this->importPayload());
        } finally {
            $this->assertNoWrites();
            $this->assertSame(0.0, $this->locStock($this->loc, $p));
        }
    }

    public function test_missing_inventory_location_id_is_422(): void
    {
        $this->lp();
        $this->makeProduct(['code' => 'T2', 'unit_purchase_id' => $this->unit, 'cost' => 1]);

        $this->expectException(ValidationException::class);
        try {
            $this->runImport($this->csvFile([['T2', 3]]), $this->importPayload('received', null));
        } finally {
            $this->assertNoWrites();
        }
    }
}
