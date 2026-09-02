<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesReturnController;
use App\Models\InventoryTransitionState as Mode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS3 — PurchasesReturnController wired to LocationAwarePurchaseStockService
 * (document_type = purchase_return, NEGATIVE delta) for warehouses in
 * MODE_LOCATION_PRIMARY + healthy. Every other mode stays legacy.
 *
 * Applied effect exists ONLY when statut == 'completed'.
 * NOT production-ready: import (MS4), batch (MS5), serial/IMEI (MS6) stay legacy.
 */
class PurchaseReturnsLocationNativeTest extends TestCase
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

        $this->wh = $this->makeWarehouse('CD-LP');
        $this->unit = $this->makeUnit('*', 1);
        $this->loc = $this->makeInventoryLocation($this->wh);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
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
            'purchase_unit_id' => $this->unit,
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

    private function payload(array $details, string $statut = 'completed', $warehouseId = null, $locationId = 'DEFAULT', $purchaseId = null): array
    {
        return [
            'supplier_id' => 1,
            'purchase_id' => $purchaseId,
            'warehouse_id' => $warehouseId ?? $this->wh,
            'inventory_location_id' => $locationId === 'DEFAULT' ? $this->loc : $locationId,
            'date' => '2026-09-06',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 50,
            'details' => $details,
        ];
    }

    private function lp(string $status = 'healthy'): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    private function req(array $payload, string $method = 'POST')
    {
        return $this->makeRequest($payload, $method);
    }

    private function createCompleted(int $product, float $qty, float $seedLoc = 100.0): array
    {
        $this->seedLocationStock($this->loc, $product, $seedLoc);
        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $product, 'quantity' => $qty])])));
        $r = DB::table('purchase_returns')->latest('id')->first();
        $d = DB::table('purchase_return_details')->where('purchase_return_id', $r->id)->first();

        return [(int) $r->id, (int) $d->id];
    }

    private function createPending(int $product, float $qty): array
    {
        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $product, 'quantity' => $qty])], 'pending')));
        $r = DB::table('purchase_returns')->latest('id')->first();
        $d = DB::table('purchase_return_details')->where('purchase_return_id', $r->id)->first();

        return [(int) $r->id, (int) $d->id];
    }

    private function updateReq(int $rid, array $details, string $statut, $locationId = 'DEFAULT', $warehouseId = null): void
    {
        $this->controller()->update($this->req($this->payload($details, $statut, $warehouseId, $locationId), 'PUT'), $rid);
    }

    // =====================================================================
    // MODE ROUTING
    // =====================================================================

    /** @dataProvider legacyModes */
    public function test_non_location_primary_modes_use_legacy(?string $mode, string $statut): void
    {
        if ($mode !== null) {
            $this->setTransitionMode($this->wh, $mode, null, 'pending');
        }
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 30);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 5])], $statut)));

        $this->assertNull(DB::table('purchase_returns')->value('inventory_location_id'));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
        if ($statut === 'completed') {
            $this->assertSame(25.0, $this->stockOf($this->wh, $p)); // legacy -=
        } else {
            $this->assertSame(30.0, $this->stockOf($this->wh, $p));
        }
    }

    public static function legacyModes(): array
    {
        return [
            'no row' => [null, 'completed'],
            'legacy_only' => [Mode::MODE_LEGACY_ONLY, 'completed'],
            'shadow_compare' => [Mode::MODE_SHADOW_COMPARE, 'completed'],
            'dual_write' => [Mode::MODE_DUAL_WRITE, 'pending'],
        ];
    }

    public function test_location_primary_healthy_uses_native(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 99);
        $this->seedLocationStock($this->loc, $p, 20);

        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 5])])));

        $this->assertSame(15.0, $this->locStock($this->loc, $p)); // 20 - 5
        $this->assertSame(99.0, $this->stockOf($this->wh, $p));   // legacy untouched
        $this->assertSame(1, $this->movementCount('PurchaseReturn'));
        $this->assertEquals($this->loc, DB::table('purchase_returns')->value('inventory_location_id'));
    }

    public function test_location_primary_unhealthy_fails_closed(): void
    {
        $this->lp('mismatch');
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 20);

        try {
            $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 5])])));
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(20.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
    }

    public function test_endpoint_corrupt_state_is_blocked(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, null, 'healthy');
        $body = $this->controller()->inventoryLocationsForWarehouse($this->req([], 'GET'), $this->wh)->getData(true);
        $this->assertFalse($body['requires_inventory_location']);
        $this->assertTrue($body['blocked']);
    }

    // =====================================================================
    // STORE
    // =====================================================================

    public function test_store_completed_writes_negative_snapshot_and_decreases_location(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 99);
        $this->seedLocationStock($this->loc, $p, 12);

        $this->controller()->store($this->req($this->payload([
            $this->line(['product_id' => $p, 'quantity' => 4, 'Unit_cost' => 7]),
        ])));

        $r = DB::table('purchase_returns')->first();
        $d = DB::table('purchase_return_details')->first();
        $snap = json_decode($r->inventory_effect_snapshot, true);
        $this->assertSame('purchase_return', $snap['document_type']);
        $this->assertSame(1, $snap['revision']);
        $this->assertSame(-4.0, (float) $snap['effects'][0]['delta']);
        $this->assertSame(4.0, (float) $snap['effects'][0]['quantity_base']);
        $this->assertSame($d->id, $snap['effects'][0]['source_detail_id']);

        $this->assertSame(8.0, $this->locStock($this->loc, $p)); // 12 - 4
        $this->assertSame(99.0, $this->stockOf($this->wh, $p));
        $m = DB::table('inventory_location_movements')->where('reference_type', 'PurchaseReturn')->first();
        $this->assertSame('decrease', $m->movement_type);
        $this->assertSame('purchase_return:'.$r->id.':rev:1:effect:0:apply', $m->idempotency_key);
    }

    public function test_store_pending_saves_location_no_effect(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 20);

        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 5])], 'pending')));

        $r = DB::table('purchase_returns')->first();
        $this->assertEquals($this->loc, $r->inventory_location_id);
        $this->assertNull($r->inventory_effect_snapshot);
        $this->assertSame(1, DB::table('purchase_return_details')->count());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(20.0, $this->locStock($this->loc, $p));
    }

    public function test_store_insufficient_stock_is_422_full_rollback(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 50);
        $this->seedLocationStock($this->loc, $p, 3);

        try {
            $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 5])])));
            $this->fail('expected 422 for insufficient stock');
        } catch (ValidationException $e) {
            // expected — InventoryService::decrease, no clamp
        }

        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, DB::table('purchase_return_details')->count());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(3.0, $this->locStock($this->loc, $p)); // NOT clamped
        $this->assertSame(50.0, $this->stockOf($this->wh, $p));
    }

    public function test_store_location_of_another_warehouse_is_422(): void
    {
        $this->lp();
        $otherLoc = $this->makeInventoryLocation($this->makeWarehouse('OTHER'));
        $p = $this->makeProduct();
        $this->expectException(ValidationException::class);
        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 1])], 'completed', null, $otherLoc)));
    }

    public function test_store_batch_and_imei_fail_closed(): void
    {
        $this->lp();
        foreach ([['is_batch_tracked' => true], ['is_imei' => 1]] as $flags) {
            $p = $this->makeProduct($flags);
            $this->seedLocationStock($this->loc, $p, 10);
            try {
                $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 1])])));
                $this->fail('tracked product must fail closed');
            } catch (ValidationException $e) {
                // expected
            }
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
    }

    public function test_store_variant_and_unit_conversion(): void
    {
        $this->lp();
        $times = $this->makeUnit('*', 12);
        $div = $this->makeUnit('/', 6);
        $pv = $this->makeProduct(['type' => 'is_variant']);
        $v = $this->makeVariant($pv);
        $p2 = $this->makeProduct();
        $this->seedLocationStock($this->loc, $pv, 30, $v);
        $this->seedLocationStock($this->loc, $p2, 30);

        $this->controller()->store($this->req($this->payload([
            $this->line(['product_id' => $pv, 'product_variant_id' => $v, 'purchase_unit_id' => $times, 'quantity' => 2]),
            $this->line(['product_id' => $p2, 'purchase_unit_id' => $div, 'quantity' => 12]),
        ])));

        $this->assertSame(6.0, $this->locStock($this->loc, $pv, $v)); // 30 - (2*12)
        $this->assertSame(28.0, $this->locStock($this->loc, $p2));    // 30 - (12/6)
    }

    public function test_store_standalone_return_without_purchase_id(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);

        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 3])], 'completed', null, 'DEFAULT', null)));

        $r = DB::table('purchase_returns')->first();
        $this->assertNull($r->purchase_id);
        $m = DB::table('inventory_location_movements')->where('reference_type', 'PurchaseReturn')->first();
        $this->assertSame((string) $r->id, (string) $m->reference_id); // reference_id = return id, not purchase id
        $this->assertSame(7.0, $this->locStock($this->loc, $p));
    }

    public function test_store_linked_return_can_use_a_different_location_than_the_purchase(): void
    {
        $this->lp();
        $loc2 = $this->makeInventoryLocation($this->wh);
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        $this->seedLocationStock($loc2, $p, 10);

        // suggested default would be $this->loc, but the user picks loc2 which has stock
        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 4])], 'completed', null, $loc2, 777)));

        $this->assertSame(6.0, $this->locStock($loc2, $p)); // decremented from the CHOSEN location
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_store_never_touches_product_warehouse(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 42.5);
        $this->seedLocationStock($this->loc, $p, 10);
        $before = DB::table('product_warehouse')->get()->toArray();

        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 3])])));

        $this->assertEquals($before, DB::table('product_warehouse')->get()->toArray());
    }

    // =====================================================================
    // UPDATE — state machine (sign inverted vs Purchase)
    // =====================================================================

    public function test_update_pending_to_pending_no_movements(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 20);
        [$rid, $did] = $this->createPending($p, 8);

        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 5])], 'pending');

        $this->assertSame(0, $this->movementCount());
        $this->assertSame(20.0, $this->locStock($this->loc, $p));
        $this->assertNull(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'));
        $this->assertEquals(5, (float) DB::table('purchase_return_details')->where('purchase_return_id', $rid)->value('quantity'));
    }

    public function test_update_pending_to_completed_applies_rev1(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 20);
        [$rid, $did] = $this->createPending($p, 8);

        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 8])], 'completed');

        $this->assertSame(12.0, $this->locStock($this->loc, $p)); // 20 - 8
        $this->assertSame(1, json_decode(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'), true)['revision']);
        $this->assertSame(1, $this->movementCount('PurchaseReturn'));
    }

    public function test_update_completed_to_completed_qty_change_reverse_rev1_apply_rev2(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        [$rid, $did] = $this->createCompleted($p, 10, 100.0);
        $this->assertSame(90.0, $this->locStock($this->loc, $p));

        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 4])], 'completed');

        // 90 + 10 (reverse rev1) - 4 (apply rev2) = 96
        $this->assertSame(96.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, json_decode(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'), true)['revision']);
        $this->assertSame(2, $this->movementCount('PurchaseReturn'));         // rev1 + rev2 apply
        $this->assertSame(1, $this->movementCount('PurchaseReturnReversal')); // rev1 reverse
    }

    public function test_update_completed_to_pending_reverses_keeps_snapshot(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        [$rid, $did] = $this->createCompleted($p, 7, 20.0);
        $this->assertSame(13.0, $this->locStock($this->loc, $p));

        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 7])], 'pending');

        $this->assertSame(20.0, $this->locStock($this->loc, $p)); // reversed
        $this->assertSame(1, json_decode(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'), true)['revision']);
        $this->assertSame('pending', DB::table('purchase_returns')->where('id', $rid)->value('statut'));
    }

    public function test_update_second_pending_to_completed_uses_prev_revision_plus_one(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        [$rid, $did] = $this->createCompleted($p, 5, 50.0);
        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 5])], 'pending');
        $this->assertSame(50.0, $this->locStock($this->loc, $p));

        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'completed');

        $this->assertSame(41.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, json_decode(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'), true)['revision']);
    }

    public function test_update_change_location_reverses_old_applies_new(): void
    {
        $this->lp();
        $loc2 = $this->makeInventoryLocation($this->wh);
        $p = $this->makeProduct();
        $this->seedLocationStock($loc2, $p, 10);
        [$rid, $did] = $this->createCompleted($p, 5, 10.0); // loc: 10 -> 5

        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 3])], 'completed', $loc2);

        $this->assertSame(10.0, $this->locStock($this->loc, $p)); // reverse +5 in OLD loc
        $this->assertSame(7.0, $this->locStock($loc2, $p));       // apply -3 in NEW loc
        $this->assertEquals($loc2, DB::table('purchase_returns')->where('id', $rid)->value('inventory_location_id'));
    }

    public function test_update_change_warehouse_primary_to_primary_persists_new_identity(): void
    {
        $this->lp();
        $wh2 = $this->makeWarehouse('WH2-LP');
        $loc2 = $this->makeInventoryLocation($wh2);
        $this->setTransitionMode($wh2, Mode::MODE_LOCATION_PRIMARY, $loc2, 'healthy');
        $p = $this->makeProduct();
        $this->seedLocationStock($loc2, $p, 10);
        [$rid, $did] = $this->createCompleted($p, 5, 10.0);

        $this->controller()->update($this->req($this->payload([$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 5])], 'completed', $wh2, $loc2), 'PUT'), $rid);

        $this->assertSame(10.0, $this->locStock($this->loc, $p));
        $this->assertSame(5.0, $this->locStock($loc2, $p));
        $this->assertEquals($wh2, DB::table('purchase_returns')->where('id', $rid)->value('warehouse_id'));    // native DOES persist it (unlike L4)
        $this->assertEquals($loc2, DB::table('purchase_returns')->where('id', $rid)->value('inventory_location_id'));
    }

    public function test_update_change_to_legacy_warehouse_is_422(): void
    {
        $this->lp();
        $legacyWh = $this->makeWarehouse('LEG');
        $p = $this->makeProduct();
        [$rid, $did] = $this->createCompleted($p, 5, 10.0);
        $this->expectException(ValidationException::class);
        $this->controller()->update($this->req($this->payload([$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 5])], 'completed', $legacyWh, 999), 'PUT'), $rid);
    }

    public function test_update_after_demotion_and_unhealthy_are_422(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 50);
        [$rid, $did] = $this->createCompleted($p, 5, 20.0);
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        $this->setTransitionMode($this->wh, Mode::MODE_DUAL_WRITE, $this->loc, 'healthy');
        try {
            $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 8])], 'completed');
            $this->fail('expected 422 after demotion');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertSame(15.0, $this->locStock($this->loc, $p)); // rolled back
        $this->assertSame(50.0, $this->stockOf($this->wh, $p));
    }

    public function test_update_corrupt_snapshot_and_now_tracked_are_422(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        [$rid, $did] = $this->createCompleted($p, 5, 20.0);

        DB::table('purchase_returns')->where('id', $rid)->update(['inventory_effect_snapshot' => '{"broken":1}']);
        try {
            $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 3])], 'completed');
            $this->fail('corrupt snapshot must fail closed');
        } catch (ValidationException $e) {
        }
        // restore a valid snapshot, then flip the product to batch-tracked
        [$rid2, $did2] = $this->createCompleted($this->makeProduct(), 5, 20.0);
        DB::table('products')->where('id', DB::table('purchase_return_details')->where('id', $did2)->value('product_id'))->update(['is_batch_tracked' => true]);
        $this->expectException(ValidationException::class);
        $this->updateReq($rid2, [$this->line(['id' => $did2, 'product_id' => DB::table('purchase_return_details')->where('id', $did2)->value('product_id'), 'quantity' => 3])], 'completed');
    }

    public function test_update_insufficient_stock_on_new_apply_rolls_back_old_reverse(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        [$rid, $did] = $this->createCompleted($p, 5, 6.0); // loc: 6 -> 1
        $this->assertSame(1.0, $this->locStock($this->loc, $p));

        try {
            // reverse gives back +5 (=> 6), then apply -20 fails (only 6 available)
            $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 20])], 'completed');
            $this->fail('expected insufficient stock on the new apply');
        } catch (ValidationException $e) {
        }

        $this->assertSame(1.0, $this->locStock($this->loc, $p)); // old reverse rolled back too
        $this->assertSame(1, $this->movementCount('PurchaseReturn'));
        $this->assertSame(0, $this->movementCount('PurchaseReturnReversal'));
        $this->assertEquals(5, (float) DB::table('purchase_return_details')->where('id', $did)->value('quantity'));
    }

    public function test_update_never_touches_product_warehouse(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 42.5);
        [$rid, $did] = $this->createCompleted($p, 5, 20.0);
        $before = DB::table('product_warehouse')->get()->toArray();

        $this->updateReq($rid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'completed');

        $this->assertEquals($before, DB::table('product_warehouse')->get()->toArray());
    }

    // =====================================================================
    // LEGACY document boundary
    // =====================================================================

    public function test_legacy_return_editable_while_warehouse_stays_legacy(): void
    {
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 20);
        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 4])], 'completed', null, null)));
        $rid = (int) DB::table('purchase_returns')->value('id');
        $did = (int) DB::table('purchase_return_details')->value('id');
        $this->assertSame(16.0, $this->stockOf($this->wh, $p)); // legacy -=

        $this->controller()->update($this->req($this->payload([$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'completed', null, null), 'PUT'), $rid);
        $this->assertSame(11.0, $this->stockOf($this->wh, $p)); // 16 + 4 - 9 legacy arithmetic
        $this->assertSame(0, $this->movementCount());
    }

    public function test_legacy_return_update_and_destroy_fail_closed_after_promotion(): void
    {
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 20);
        $this->controller()->store($this->req($this->payload([$this->line(['product_id' => $p, 'quantity' => 4])], 'completed', null, null)));
        $rid = (int) DB::table('purchase_returns')->value('id');
        $did = (int) DB::table('purchase_return_details')->value('id');
        $this->assertSame(16.0, $this->stockOf($this->wh, $p));

        $this->lp();

        try {
            $this->controller()->update($this->req($this->payload([$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'completed', null, null), 'PUT'), $rid);
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        try {
            $this->controller()->destroy($this->req([], 'DELETE'), $rid);
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertSame(16.0, $this->stockOf($this->wh, $p)); // unchanged
        $this->assertNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
    }

    public function test_legacy_return_update_targeting_primary_warehouse_is_422(): void
    {
        $legWh = $this->makeWarehouse('LEG');
        $p = $this->makeProduct();
        $this->seedStock($legWh, $p, 20);
        $this->controller()->store($this->req([
            'supplier_id' => 1, 'warehouse_id' => $legWh, 'date' => '2026-09-06', 'statut' => 'completed',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['product_id' => $p, 'quantity' => 4])],
        ]));
        $rid = (int) DB::table('purchase_returns')->where('warehouse_id', $legWh)->value('id');
        $did = (int) DB::table('purchase_return_details')->where('purchase_return_id', $rid)->value('id');

        $this->lp();

        $this->expectException(ValidationException::class);
        $this->controller()->update($this->req([
            'supplier_id' => 1, 'warehouse_id' => $this->wh, 'date' => '2026-09-06', 'statut' => 'completed',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])],
        ], 'PUT'), $rid);
    }

    // =====================================================================
    // DESTROY
    // =====================================================================

    public function test_destroy_completed_reverses_exact_snapshot(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 99);
        [$rid] = $this->createCompleted($p, 9, 20.0);
        $this->assertSame(11.0, $this->locStock($this->loc, $p));

        $this->controller()->destroy($this->req([], 'DELETE'), $rid);

        $this->assertSame(20.0, $this->locStock($this->loc, $p)); // stock returns to snapshot location
        $this->assertSame(99.0, $this->stockOf($this->wh, $p));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
        $this->assertSame(1, $this->movementCount('PurchaseReturnReversal'));
    }

    public function test_destroy_pending_no_mutation(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 5);
        [$rid] = $this->createPending($p, 9);

        $this->controller()->destroy($this->req([], 'DELETE'), $rid);

        $this->assertSame(5.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
    }

    public function test_destroy_null_snapshot_on_completed_fails_closed(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        [$rid] = $this->createCompleted($p, 5, 20.0);
        DB::table('purchase_returns')->where('id', $rid)->update(['inventory_effect_snapshot' => null]);

        $this->expectException(ValidationException::class);
        $this->controller()->destroy($this->req([], 'DELETE'), $rid);
    }

    public function test_destroy_after_demotion_is_422(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 50);
        [$rid] = $this->createCompleted($p, 5, 20.0);
        $this->setTransitionMode($this->wh, Mode::MODE_LEGACY_ONLY, null, 'pending');

        try {
            $this->controller()->destroy($this->req([], 'DELETE'), $rid);
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertSame(15.0, $this->locStock($this->loc, $p));
        $this->assertSame(50.0, $this->stockOf($this->wh, $p));
        $this->assertNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
    }

    public function test_destroy_reverses_payments_and_leaves_product_warehouse(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 42.5);
        [$rid] = $this->createCompleted($p, 5, 20.0);
        DB::table('payment_purchase_returns')->insert([
            'purchase_return_id' => $rid, 'account_id' => 1, 'montant' => 40, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = DB::table('product_warehouse')->get()->toArray();

        $this->controller()->destroy($this->req([], 'DELETE'), $rid);

        $this->assertNotNull(DB::table('payment_purchase_returns')->where('purchase_return_id', $rid)->value('deleted_at'));
        $this->assertEquals($before, DB::table('product_warehouse')->get()->toArray());
    }

    // =====================================================================
    // BULK DELETE
    // =====================================================================

    public function test_bulk_delete_mixed_valid_selection(): void
    {
        // legacy return
        $legWh = $this->makeWarehouse('LEG');
        $pl = $this->makeProduct();
        $this->seedStock($legWh, $pl, 30);
        $this->controller()->store($this->req([
            'supplier_id' => 1, 'warehouse_id' => $legWh, 'date' => '2026-09-06', 'statut' => 'completed',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['product_id' => $pl, 'quantity' => 5])],
        ]));
        $legId = (int) DB::table('purchase_returns')->where('warehouse_id', $legWh)->value('id');
        $this->assertSame(25.0, $this->stockOf($legWh, $pl));

        // native return
        $this->lp();
        $pn = $this->makeProduct();
        $this->seedStock($this->wh, $pn, 99);
        [$nativeId] = $this->createCompleted($pn, 8, 20.0);
        $this->assertSame(12.0, $this->locStock($this->loc, $pn));

        $this->controller()->delete_by_selection($this->req(['selectedIds' => [$legId, $nativeId]], 'POST'));

        $this->assertSame(30.0, $this->stockOf($legWh, $pl));   // legacy restored via product_warehouse
        $this->assertSame(20.0, $this->locStock($this->loc, $pn)); // native restored via engine
        $this->assertSame(99.0, $this->stockOf($this->wh, $pn));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $legId)->value('deleted_at'));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $nativeId)->value('deleted_at'));
    }

    public function test_bulk_delete_aborts_atomically_on_incompatible_row(): void
    {
        $legWh = $this->makeWarehouse('LEG-BULK');
        $pl = $this->makeProduct();
        $this->seedStock($legWh, $pl, 30);
        $this->controller()->store($this->req([
            'supplier_id' => 1, 'warehouse_id' => $legWh, 'date' => '2026-09-06', 'statut' => 'completed',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['product_id' => $pl, 'quantity' => 5])],
        ]));
        $legId = (int) DB::table('purchase_returns')->where('warehouse_id', $legWh)->value('id');
        $this->assertSame(25.0, $this->stockOf($legWh, $pl));

        $this->lp();
        $pn = $this->makeProduct();
        [$nativeId] = $this->createCompleted($pn, 8, 20.0);

        // promote the legacy return's warehouse -> its row is now incompatible
        $this->setTransitionMode($legWh, Mode::MODE_LOCATION_PRIMARY, $this->makeInventoryLocation($legWh), 'healthy');

        try {
            $this->controller()->delete_by_selection($this->req(['selectedIds' => [$nativeId, $legId]], 'POST'));
            $this->fail('expected the incompatible row to abort the whole bulk delete');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertNull(DB::table('purchase_returns')->where('id', $legId)->value('deleted_at'));
        $this->assertNull(DB::table('purchase_returns')->where('id', $nativeId)->value('deleted_at'));
        $this->assertSame(25.0, $this->stockOf($legWh, $pl));
        $this->assertSame(12.0, $this->locStock($this->loc, $pn));
        $this->assertSame(0, $this->movementCount('PurchaseReturnReversal'));
    }
}
