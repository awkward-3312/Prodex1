<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\InventoryTransitionState as Mode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS2 — PurchasesController wired to LocationAwarePurchaseStockService, but
 * ONLY for warehouses in MODE_LOCATION_PRIMARY + healthy. Every other mode
 * keeps the exact legacy flow (covered by PurchasesLegacyGoldenMasterTest).
 *
 * NOT production-ready as a package: batch (MS5), serial / IMEI (MS6) and
 * provenance (MS7) are still legacy / pending.
 */
class PurchasesLocationNativeTest extends TestCase
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

    private function controller(): PurchasesController
    {
        return new PurchasesController;
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
            'no_unit' => 1,
        ], $o);
    }

    private function payload(array $details, string $statut = 'received', $warehouseId = null, $locationId = 'DEFAULT'): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $warehouseId ?? $this->wh,
            'inventory_location_id' => $locationId === 'DEFAULT' ? $this->loc : $locationId,
            'date' => '2026-09-05',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    private function lp(string $status = 'healthy'): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    private function pwCount(): int
    {
        return (int) DB::table('product_warehouse')->count();
    }

    // =====================================================================
    // MODE ROUTING
    // =====================================================================

    /**
     * @dataProvider legacyModes
     *
     * legacy_only / shadow_compare / no-row run a full `received` legacy store
     * (product_warehouse arithmetic). dual_write is asserted with a `pending`
     * store: a received legacy write in dual_write mode goes through
     * InventoryCompatibilityService's mirror, which is out of MS2 scope
     * (§22 — do not touch). Routing is what matters here.
     */
    public function test_non_location_primary_modes_use_the_legacy_flow(?string $mode, string $statut): void
    {
        if ($mode !== null) {
            $this->setTransitionMode($this->wh, $mode, null, 'pending');
        }
        $this->assertFalse(app(\App\Services\WarehouseInventoryModeResolver::class)->isLocationPrimary($this->wh));

        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 5);          // legacy product_warehouse row
        $this->seedLocationStock($this->loc, $p, 0); // location row (should stay 0)

        $req = $this->makeRequest($this->payload([
            $this->line(['product_id' => $p, 'quantity' => 10]),
        ], $statut));
        $this->controller()->store($req);

        // never routed to the location engine.
        $this->assertNull(DB::table('purchases')->value('inventory_location_id'));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());

        if ($statut === 'received') {
            $this->assertSame(15.0, $this->stockOf($this->wh, $p)); // legacy writer ran
        } else {
            $this->assertSame(5.0, $this->stockOf($this->wh, $p));  // pending: no stock change
        }
    }

    public static function legacyModes(): array
    {
        return [
            'no transition row' => [null, 'received'],
            'legacy_only' => [Mode::MODE_LEGACY_ONLY, 'received'],
            'shadow_compare' => [Mode::MODE_SHADOW_COMPARE, 'received'],
            'dual_write' => [Mode::MODE_DUAL_WRITE, 'pending'],
        ];
    }

    public function test_location_primary_healthy_uses_the_location_aware_flow(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 5);
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 10])]));
        $this->controller()->store($req);

        $this->assertSame(10.0, $this->locStock($this->loc, $p)); // engine moved
        $this->assertSame(5.0, $this->stockOf($this->wh, $p));    // legacy untouched
        $this->assertSame(1, $this->movementCount('Purchase'));
        $this->assertEquals($this->loc, DB::table('purchases')->value('inventory_location_id'));
    }

    public function test_location_primary_unhealthy_fails_closed_with_no_writes(): void
    {
        $this->lp('mismatch');
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 5);
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 10])]));
        try {
            $this->controller()->store($req);
            $this->fail('expected 422 inventory_transition');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_details')->count());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(5.0, $this->stockOf($this->wh, $p));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    // =====================================================================
    // ENDPOINT — malformed / corrupt transition state
    // =====================================================================

    public function test_endpoint_healthy_location_primary_returns_requires_true(): void
    {
        $this->lp();
        $req = $this->makeRequest([], 'GET');
        $body = $this->controller()->inventoryLocationsForWarehouse($req, $this->wh)->getData(true);

        $this->assertSame('location_primary', $body['transition_mode']);
        $this->assertSame('healthy', $body['transition_status']);
        $this->assertTrue($body['requires_inventory_location']);
        $this->assertFalse($body['blocked']);
        $this->assertSame($this->loc, $body['default_inventory_location_id']);
    }

    /**
     * #14 — mode=location_primary, status=healthy, but state.inventory_location_id
     * IS NULL. This corrupt state must NOT read as requires=false/blocked=false.
     */
    public function test_endpoint_corrupt_state_healthy_primary_without_location_is_blocked(): void
    {
        // healthy location_primary but NO inventory_location_id on the state row
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, null, 'healthy');

        $req = $this->makeRequest([], 'GET');
        $body = $this->controller()->inventoryLocationsForWarehouse($req, $this->wh)->getData(true);

        $this->assertSame('location_primary', $body['transition_mode']);
        $this->assertFalse($body['requires_inventory_location'], 'corrupt state must not require (and pass) a location');
        $this->assertTrue($body['blocked'], 'corrupt location_primary state must hard-block the form');
    }

    public function test_endpoint_corrupt_state_also_blocks_the_store_backend(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, null, 'healthy');
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);

        // isLocationPrimary() is true (mode check) -> routed to storeLocationAware
        // -> assertLocationNativePurchaseTransitionSafe -> 422 (state not ready).
        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 1])])));
    }

    // =====================================================================
    // STORE
    // =====================================================================

    public function test_store_received_persists_location_snapshot_and_moves_location_stock(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 99);
        $this->seedLocationStock($this->loc, $p, 3);

        $req = $this->makeRequest($this->payload([
            $this->line(['product_id' => $p, 'quantity' => 4, 'Unit_cost' => 7]),
        ]));
        $this->controller()->store($req);

        $purchase = DB::table('purchases')->first();
        $this->assertEquals($this->loc, $purchase->inventory_location_id);
        $this->assertSame('received', $purchase->statut);

        $detail = DB::table('purchase_details')->first();
        $snap = json_decode($purchase->inventory_effect_snapshot, true);
        $this->assertSame(1, $snap['revision']);
        $this->assertSame('purchase', $snap['document_type']);
        $this->assertSame($detail->id, $snap['effects'][0]['source_detail_id']); // REAL detail id
        $this->assertSame(4.0, (float) $snap['effects'][0]['delta']);

        $this->assertSame(7.0, $this->locStock($this->loc, $p)); // 3 + 4
        $this->assertSame(99.0, $this->stockOf($this->wh, $p));  // legacy untouched
        $m = DB::table('inventory_location_movements')->where('reference_type', 'Purchase')->first();
        $this->assertSame('increase', $m->movement_type);
        $this->assertSame('purchase:'.$purchase->id.':rev:1:effect:0:apply', $m->idempotency_key);
    }

    public function test_store_two_lines_same_product_get_distinct_source_detail_ids(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([
            $this->line(['product_id' => $p, 'quantity' => 2]),
            $this->line(['product_id' => $p, 'quantity' => 3]),
        ]));
        $this->controller()->store($req);

        $detailIds = DB::table('purchase_details')->orderBy('id')->pluck('id')->all();
        $snap = json_decode(DB::table('purchases')->value('inventory_effect_snapshot'), true);
        $snapDetailIds = array_column($snap['effects'], 'source_detail_id');
        sort($snapDetailIds);
        $this->assertSame($detailIds, $snapDetailIds); // both real, distinct
        $this->assertSame(5.0, $this->locStock($this->loc, $p)); // 2 + 3
    }

    public function test_store_pending_saves_location_but_no_effect(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 10])], 'pending'));
        $this->controller()->store($req);

        $purchase = DB::table('purchases')->first();
        $this->assertEquals($this->loc, $purchase->inventory_location_id);
        $this->assertNull($purchase->inventory_effect_snapshot); // no physical effect yet
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_store_missing_inventory_location_id_is_422_with_zero_writes(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 10])], 'received', null, null));
        try {
            $this->controller()->store($req);
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_location_id', $e->errors());
        }

        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_details')->count());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_store_location_of_another_warehouse_is_422(): void
    {
        $this->lp();
        $otherWh = $this->makeWarehouse('OTHER');
        $otherLoc = $this->makeInventoryLocation($otherWh);
        $p = $this->makeProduct();

        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 1])], 'received', null, $otherLoc));
        $this->expectException(ValidationException::class);
        $this->controller()->store($req);
    }

    public function test_store_batch_tracked_product_fails_closed(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 1])]));
        try {
            $this->controller()->store($req);
            $this->fail('batch => fail closed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('artifact-aware', implode(' ', $e->errors()['details'] ?? ['']));
        }
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_store_imei_tracked_product_fails_closed(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_imei' => 1]);
        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 1])]));
        $this->expectException(ValidationException::class);
        $this->controller()->store($req);
    }

    public function test_store_variant_line_moves_variant_scoped_location_stock(): void
    {
        $this->lp();
        $p = $this->makeProduct(['type' => 'is_variant']);
        $v = $this->makeVariant($p);
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedLocationStock($this->loc, $p, 2, $v);

        $req = $this->makeRequest($this->payload([
            $this->line(['product_id' => $p, 'product_variant_id' => $v, 'quantity' => 5]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(7.0, $this->locStock($this->loc, $p, $v)); // 2 + 5
        $this->assertSame(1.0, $this->locStock($this->loc, $p));     // base untouched
    }

    public function test_store_respects_unit_conversion_multiply_and_divide(): void
    {
        $this->lp();
        $times = $this->makeUnit('*', 12);
        $div = $this->makeUnit('/', 6);
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p1, 0);
        $this->seedLocationStock($this->loc, $p2, 0);

        $req = $this->makeRequest($this->payload([
            $this->line(['product_id' => $p1, 'purchase_unit_id' => $times, 'quantity' => 2]),
            $this->line(['product_id' => $p2, 'purchase_unit_id' => $div, 'quantity' => 12]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(24.0, $this->locStock($this->loc, $p1)); // 2 * 12
        $this->assertSame(2.0, $this->locStock($this->loc, $p2));  // 12 / 6
    }

    public function test_store_works_even_without_a_product_warehouse_row(): void
    {
        $this->lp();
        $p = $this->makeProduct(); // no seedStock()
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 6])]));
        $this->controller()->store($req);

        $this->assertSame(6.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->pwCount()); // none created — native path doesn't need it
    }

    public function test_store_never_touches_product_warehouse(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 42.5);
        $this->seedLocationStock($this->loc, $p, 0);
        $before = DB::table('product_warehouse')->get()->toArray();

        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 8])]));
        $this->controller()->store($req);

        $this->assertEquals($before, DB::table('product_warehouse')->get()->toArray());
    }

    // =====================================================================
    // UPDATE — state machine
    // =====================================================================

    private function createReceived(int $product, float $qty): array
    {
        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $product, 'quantity' => $qty])]));
        $this->controller()->store($req);
        $p = DB::table('purchases')->latest('id')->first();
        $d = DB::table('purchase_details')->where('purchase_id', $p->id)->first();

        return [(int) $p->id, (int) $d->id];
    }

    private function createPending(int $product, float $qty): array
    {
        $req = $this->makeRequest($this->payload([$this->line(['product_id' => $product, 'quantity' => $qty])], 'pending'));
        $this->controller()->store($req);
        $p = DB::table('purchases')->latest('id')->first();
        $d = DB::table('purchase_details')->where('purchase_id', $p->id)->first();

        return [(int) $p->id, (int) $d->id];
    }

    private function updateReq(int $pid, array $details, string $statut, $locationId = 'DEFAULT'): void
    {
        $req = $this->makeRequest($this->payload($details, $statut, null, $locationId), 'PUT');
        $this->controller()->update($req, $pid);
    }

    public function test_update_pending_to_pending_makes_no_movements(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid, $did] = $this->createPending($p, 8);

        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 5])], 'pending');

        $this->assertSame(0, $this->movementCount());
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot'));
        $this->assertEquals(5, (float) DB::table('purchase_details')->where('purchase_id', $pid)->value('quantity'));
    }

    public function test_update_pending_to_received_applies_revision_1(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid, $did] = $this->createPending($p, 8);

        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 8])], 'received');

        $this->assertSame(8.0, $this->locStock($this->loc, $p));
        $snap = json_decode(DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot'), true);
        $this->assertSame(1, $snap['revision']);
        $this->assertSame(1, $this->movementCount('Purchase'));
    }

    public function test_update_received_to_received_quantity_change_reverse_rev1_apply_rev2(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 100);
        [$pid, $did] = $this->createReceived($p, 10);
        $this->assertSame(110.0, $this->locStock($this->loc, $p));

        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 4])], 'received');

        // 110 - 10 (reverse rev1) + 4 (apply rev2) = 104
        $this->assertSame(104.0, $this->locStock($this->loc, $p));
        $snap = json_decode(DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot'), true);
        $this->assertSame(2, $snap['revision']);
        // rev1 apply + rev2 apply both carry reference_type Purchase.
        $this->assertSame(2, $this->movementCount('Purchase'));
        $this->assertSame(1, $this->movementCount('PurchaseReversal'));  // rev1 reverse
        $keys = DB::table('inventory_location_movements')->orderBy('id')->pluck('idempotency_key')->all();
        $this->assertContains('purchase:'.$pid.':rev:1:effect:0:apply', $keys);
        $this->assertContains('purchase:'.$pid.':rev:1:effect:0:reverse', $keys);
        $this->assertContains('purchase:'.$pid.':rev:2:effect:0:apply', $keys);
    }

    public function test_update_received_to_pending_reverses_and_keeps_historical_snapshot(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 20);
        [$pid, $did] = $this->createReceived($p, 7);
        $this->assertSame(27.0, $this->locStock($this->loc, $p));

        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 7])], 'pending');

        $this->assertSame(20.0, $this->locStock($this->loc, $p)); // reversed, not re-applied
        $snap = json_decode(DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot'), true);
        $this->assertSame(1, $snap['revision']); // historical snapshot preserved
        $this->assertSame('pending', DB::table('purchases')->where('id', $pid)->value('statut'));
    }

    public function test_update_second_pending_to_received_uses_previous_revision_plus_one(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid, $did] = $this->createReceived($p, 5);              // rev 1 applied
        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 5])], 'pending'); // reverse rev1
        $this->assertSame(0.0, $this->locStock($this->loc, $p));

        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'received'); // -> rev 2

        $this->assertSame(9.0, $this->locStock($this->loc, $p));
        $snap = json_decode(DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot'), true);
        $this->assertSame(2, $snap['revision']);
    }

    public function test_update_changing_location_reverses_old_and_applies_new(): void
    {
        $this->lp();
        $loc2 = $this->makeInventoryLocation($this->wh);
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        $this->seedLocationStock($loc2, $p, 10);
        [$pid, $did] = $this->createReceived($p, 6);
        $this->assertSame(16.0, $this->locStock($this->loc, $p));

        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 6])], 'received', $loc2);

        $this->assertSame(10.0, $this->locStock($this->loc, $p)); // reversed in old location
        $this->assertSame(16.0, $this->locStock($loc2, $p));      // applied in new location
        $this->assertEquals($loc2, DB::table('purchases')->where('id', $pid)->value('inventory_location_id'));
    }

    public function test_update_changing_warehouse_reverses_old_snapshot_and_applies_new(): void
    {
        $this->lp();
        $wh2 = $this->makeWarehouse('WH2-LP');
        $loc2 = $this->makeInventoryLocation($wh2);
        $this->setTransitionMode($wh2, Mode::MODE_LOCATION_PRIMARY, $loc2, 'healthy');
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        $this->seedLocationStock($loc2, $p, 10);
        [$pid, $did] = $this->createReceived($p, 6);

        $req = $this->makeRequest($this->payload(
            [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 6])], 'received', $wh2, $loc2
        ), 'PUT');
        $this->controller()->update($req, $pid);

        $this->assertSame(10.0, $this->locStock($this->loc, $p)); // old snapshot reversed at old loc
        $this->assertSame(16.0, $this->locStock($loc2, $p));
        $this->assertEquals($wh2, DB::table('purchases')->where('id', $pid)->value('warehouse_id'));
    }

    public function test_update_corrupt_snapshot_on_received_fails_closed(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid, $did] = $this->createReceived($p, 5);
        DB::table('purchases')->where('id', $pid)->update(['inventory_effect_snapshot' => '{"broken":true}']);

        $this->expectException(ValidationException::class);
        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 3])], 'received');
    }

    public function test_update_product_became_batch_tracked_fails_closed(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid, $did] = $this->createReceived($p, 5);
        DB::table('products')->where('id', $p)->update(['is_batch_tracked' => true]);

        try {
            $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 3])], 'received');
            $this->fail('expected fail closed');
        } catch (ValidationException $e) {
            // expected
        }
        $this->assertSame(15.0, $this->locStock($this->loc, $p)); // untouched (10 + 5, rolled back)
    }

    public function test_update_never_touches_product_warehouse(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 42.5);
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid, $did] = $this->createReceived($p, 5);
        $before = DB::table('product_warehouse')->get()->toArray();

        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'received');

        $this->assertEquals($before, DB::table('product_warehouse')->get()->toArray());
    }

    // =====================================================================
    // HISTORICAL LEGACY PURCHASE — transition boundary
    // =====================================================================

    /** A legacy purchase created + edited while the warehouse stays legacy. */
    public function test_legacy_purchase_editable_while_warehouse_stays_legacy(): void
    {
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 10);
        $this->seedLocationStock($this->loc, $p, 0);
        $storeReq = $this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 4])], 'received', null, null));
        $this->controller()->store($storeReq);
        $pid = (int) DB::table('purchases')->value('id');
        $did = (int) DB::table('purchase_details')->value('id');
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('inventory_location_id'));
        $this->assertSame(14.0, $this->stockOf($this->wh, $p));

        $upReq = $this->makeRequest($this->payload([$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'received', null, null), 'PUT');
        $this->controller()->update($upReq, $pid);

        $this->assertSame(19.0, $this->stockOf($this->wh, $p)); // 14 - 4 + 9 legacy arithmetic
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
    }

    /**
     * BLOCKER — a legacy purchase whose warehouse is LATER promoted to
     * location_primary must NOT be edited through the legacy product_warehouse
     * writer. It FAILS CLOSED (we can't know its historical physical location)
     * and mutates nothing.
     */
    public function test_legacy_purchase_update_fails_closed_when_warehouse_became_location_primary(): void
    {
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 10);
        $this->seedLocationStock($this->loc, $p, 0);
        $this->controller()->store($this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 4])], 'received', null, null)));
        $pid = (int) DB::table('purchases')->value('id');
        $did = (int) DB::table('purchase_details')->value('id');
        $this->assertSame(14.0, $this->stockOf($this->wh, $p));

        $this->lp(); // warehouse promoted afterwards

        $req = $this->makeRequest($this->payload([$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])], 'received', null, null), 'PUT');
        try {
            $this->controller()->update($req, $pid);
            $this->fail('expected 422 inventory_transition');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertSame(14.0, $this->stockOf($this->wh, $p));           // product_warehouse unchanged
        $this->assertSame(0.0, $this->locStock($this->loc, $p));          // location engine untouched
        $this->assertSame(0, $this->movementCount());
        $this->assertEquals(4, (float) DB::table('purchase_details')->where('id', $did)->value('quantity')); // details unchanged
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
    }

    /**
     * Legacy purchase, OLD warehouse legacy but the REQUEST points at a
     * location_primary warehouse => 422 before anything is applied (we do not
     * convert legacy documents to location-native on update).
     */
    public function test_legacy_purchase_update_fails_closed_when_request_targets_location_primary_warehouse(): void
    {
        $legacyWh = $this->makeWarehouse('LEG');
        $p = $this->makeProduct();
        $this->seedStock($legacyWh, $p, 10);
        $storeReq = $this->makeRequest([
            'supplier_id' => 1, 'warehouse_id' => $legacyWh, 'date' => '2026-09-05', 'statut' => 'received',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['product_id' => $p, 'quantity' => 4])],
        ]);
        $this->controller()->store($storeReq);
        $pid = (int) DB::table('purchases')->where('warehouse_id', $legacyWh)->value('id');
        $did = (int) DB::table('purchase_details')->where('purchase_id', $pid)->value('id');
        $this->assertSame(14.0, $this->stockOf($legacyWh, $p));

        $this->lp(); // this->wh is location_primary; request will target it

        $req = $this->makeRequest([
            'supplier_id' => 1, 'warehouse_id' => $this->wh, 'date' => '2026-09-05', 'statut' => 'received',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 9])],
        ], 'PUT');
        try {
            $this->controller()->update($req, $pid);
            $this->fail('expected 422 inventory_transition');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertSame(14.0, $this->stockOf($legacyWh, $p));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
        $this->assertEquals($legacyWh, DB::table('purchases')->where('id', $pid)->value('warehouse_id'));
    }

    public function test_legacy_purchase_destroy_fails_closed_when_warehouse_is_location_primary(): void
    {
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 10);
        $this->controller()->store($this->makeRequest($this->payload([$this->line(['product_id' => $p, 'quantity' => 4])], 'received', null, null)));
        $pid = (int) DB::table('purchases')->value('id');
        $this->assertSame(14.0, $this->stockOf($this->wh, $p));

        $this->lp();

        try {
            $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertSame(14.0, $this->stockOf($this->wh, $p));
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
    }

    /**
     * A location-native purchase whose warehouse is LATER demoted must fail
     * closed on update/destroy — no legacy fallback, no product_warehouse.
     */
    public function test_native_purchase_update_fails_closed_after_warehouse_demotion(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 50);
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid, $did] = $this->createReceived($p, 5);
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        // demote
        $this->setTransitionMode($this->wh, Mode::MODE_DUAL_WRITE, $this->loc, 'healthy');

        try {
            $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 8])], 'received');
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertSame(15.0, $this->locStock($this->loc, $p)); // reverse rolled back
        $this->assertSame(50.0, $this->stockOf($this->wh, $p));   // product_warehouse untouched
        $this->assertEquals(5, (float) DB::table('purchase_details')->where('id', $did)->value('quantity'));
    }

    public function test_native_purchase_update_fails_closed_when_warehouse_unhealthy(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid, $did] = $this->createReceived($p, 5);

        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'mismatch');

        $this->expectException(ValidationException::class);
        $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 8])], 'received');
    }

    public function test_native_purchase_destroy_fails_closed_after_warehouse_demotion(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 50);
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid] = $this->createReceived($p, 5);
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        $this->setTransitionMode($this->wh, Mode::MODE_LEGACY_ONLY, null, 'pending');

        try {
            $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertSame(15.0, $this->locStock($this->loc, $p));  // untouched
        $this->assertSame(50.0, $this->stockOf($this->wh, $p));
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
    }

    /**
     * pending location-native, warehouse demoted BEFORE pending->received:
     * fail closed, no movement.
     */
    public function test_pending_native_to_received_fails_closed_after_demotion(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid, $did] = $this->createPending($p, 8);

        $this->setTransitionMode($this->wh, Mode::MODE_LEGACY_ONLY, null, 'pending');

        try {
            $this->updateReq($pid, [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 8])], 'received');
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
        $this->assertSame('pending', DB::table('purchases')->where('id', $pid)->value('statut'));
    }

    public function test_native_purchase_change_to_another_healthy_primary_warehouse_is_allowed(): void
    {
        $this->lp();
        $wh2 = $this->makeWarehouse('WH2-LP');
        $loc2 = $this->makeInventoryLocation($wh2);
        $this->setTransitionMode($wh2, Mode::MODE_LOCATION_PRIMARY, $loc2, 'healthy');
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        $this->seedLocationStock($loc2, $p, 10);
        [$pid, $did] = $this->createReceived($p, 6);

        $req = $this->makeRequest($this->payload(
            [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 6])], 'received', $wh2, $loc2
        ), 'PUT');
        $this->controller()->update($req, $pid); // allowed: A healthy primary -> B healthy primary

        $this->assertSame(10.0, $this->locStock($this->loc, $p));
        $this->assertSame(16.0, $this->locStock($loc2, $p));
    }

    public function test_native_purchase_change_to_legacy_warehouse_fails_closed(): void
    {
        $this->lp();
        $legacyWh = $this->makeWarehouse('LEG-B');
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid, $did] = $this->createReceived($p, 6);

        $req = $this->makeRequest($this->payload(
            [$this->line(['id' => $did, 'product_id' => $p, 'quantity' => 6])], 'received', $legacyWh, 999
        ), 'PUT');
        $this->expectException(ValidationException::class);
        $this->controller()->update($req, $pid);
    }

    /**
     * Bulk atomicity: a selection with a valid native purchase + a legacy
     * purchase whose warehouse is now location_primary => the WHOLE operation
     * rolls back. Nothing deleted, no stock changed.
     */
    public function test_bulk_delete_aborts_atomically_on_a_transition_incompatible_row(): void
    {
        // legacy purchase in a warehouse that will be promoted
        $legWh = $this->makeWarehouse('LEG-BULK');
        $pl = $this->makeProduct();
        $this->seedStock($legWh, $pl, 30);
        $this->controller()->store($this->makeRequest([
            'supplier_id' => 1, 'warehouse_id' => $legWh, 'date' => '2026-09-05', 'statut' => 'received',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['product_id' => $pl, 'quantity' => 5])],
        ]));
        $legacyId = (int) DB::table('purchases')->where('warehouse_id', $legWh)->value('id');
        $this->assertSame(35.0, $this->stockOf($legWh, $pl));

        // valid location-native purchase
        $this->lp();
        $pn = $this->makeProduct();
        $this->seedLocationStock($this->loc, $pn, 20);
        [$nativeId] = $this->createReceived($pn, 8);
        $this->assertSame(28.0, $this->locStock($this->loc, $pn));

        // promote the legacy purchase's warehouse -> its row is now incompatible
        $this->setTransitionMode($legWh, Mode::MODE_LOCATION_PRIMARY, $this->makeInventoryLocation($legWh), 'healthy');

        try {
            $this->controller()->delete_by_selection($this->makeRequest(['selectedIds' => [$nativeId, $legacyId]], 'POST'));
            $this->fail('expected the incompatible legacy row to abort the whole bulk delete');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }

        // NOTHING deleted, NOTHING moved.
        $this->assertNull(DB::table('purchases')->where('id', $legacyId)->value('deleted_at'));
        $this->assertNull(DB::table('purchases')->where('id', $nativeId)->value('deleted_at'));
        $this->assertSame(35.0, $this->stockOf($legWh, $pl));
        $this->assertSame(28.0, $this->locStock($this->loc, $pn));
        $this->assertSame(0, $this->movementCount('PurchaseReversal'));
    }

    // =====================================================================
    // DESTROY
    // =====================================================================

    public function test_destroy_received_reverses_exact_snapshot(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 99);
        $this->seedLocationStock($this->loc, $p, 20);
        [$pid] = $this->createReceived($p, 9);
        $this->assertSame(29.0, $this->locStock($this->loc, $p));

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);

        $this->assertSame(20.0, $this->locStock($this->loc, $p)); // reversed to preexisting
        $this->assertSame(99.0, $this->stockOf($this->wh, $p));   // legacy untouched
        $this->assertNotNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
        $this->assertSame(1, $this->movementCount('PurchaseReversal'));
    }

    public function test_destroy_pending_does_not_mutate_stock(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 5);
        [$pid] = $this->createPending($p, 9);

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);

        $this->assertSame(5.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->movementCount());
        $this->assertNotNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
    }

    public function test_destroy_blocked_when_purchase_return_exists(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid] = $this->createReceived($p, 5);
        DB::table('purchase_returns')->insert([
            'user_id' => 1, 'date' => '2026-09-05', 'Ref' => 'RP_0001',
            'purchase_id' => $pid, 'provider_id' => 1, 'warehouse_id' => $this->wh,
            'GrandTotal' => 0, 'payment_statut' => 'unpaid', 'statut' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertSame(5.0, $this->locStock($this->loc, $p)); // untouched
        $this->assertNull(DB::table('purchases')->where('id', $pid)->value('deleted_at'));
    }

    public function test_destroy_received_with_null_snapshot_fails_closed(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid] = $this->createReceived($p, 5);
        DB::table('purchases')->where('id', $pid)->update(['inventory_effect_snapshot' => null]);

        $this->expectException(ValidationException::class);
        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);
    }

    public function test_destroy_reverses_payments_like_legacy(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid] = $this->createReceived($p, 5);
        DB::table('payment_purchases')->insert([
            'purchase_id' => $pid, 'account_id' => 1, 'montant' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);

        $this->assertNotNull(DB::table('payment_purchases')->where('purchase_id', $pid)->value('deleted_at'));
    }

    public function test_destroy_never_touches_product_warehouse(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedStock($this->wh, $p, 42.5);
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid] = $this->createReceived($p, 5);
        $before = DB::table('product_warehouse')->get()->toArray();

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);

        $this->assertEquals($before, DB::table('product_warehouse')->get()->toArray());
    }

    // =====================================================================
    // BULK DELETE (mixed selection)
    // =====================================================================

    public function test_bulk_delete_mixed_selection_each_row_uses_its_own_writer(): void
    {
        // legacy purchase (warehouse legacy_only)
        $legacyWh = $this->makeWarehouse('LEG');
        $pl = $this->makeProduct();
        $this->seedStock($legacyWh, $pl, 30);
        $legReq = $this->makeRequest([
            'supplier_id' => 1, 'warehouse_id' => $legacyWh, 'date' => '2026-09-05', 'statut' => 'received',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [$this->line(['product_id' => $pl, 'quantity' => 5])],
        ]);
        $this->controller()->store($legReq);
        $legacyId = (int) DB::table('purchases')->where('warehouse_id', $legacyWh)->value('id');
        $this->assertSame(35.0, $this->stockOf($legacyWh, $pl));

        // location-native purchase
        $this->lp();
        $pn = $this->makeProduct();
        $this->seedStock($this->wh, $pn, 99);
        $this->seedLocationStock($this->loc, $pn, 20);
        [$nativeId] = $this->createReceived($pn, 8);
        $this->assertSame(28.0, $this->locStock($this->loc, $pn));

        $req = $this->makeRequest(['selectedIds' => [$legacyId, $nativeId]], 'POST');
        $this->controller()->delete_by_selection($req);

        // legacy reversed via product_warehouse, native reversed via the engine
        $this->assertSame(30.0, $this->stockOf($legacyWh, $pl));
        $this->assertSame(20.0, $this->locStock($this->loc, $pn));
        $this->assertSame(99.0, $this->stockOf($this->wh, $pn)); // native purchase never touched its legacy row
        $this->assertNotNull(DB::table('purchases')->where('id', $legacyId)->value('deleted_at'));
        $this->assertNotNull(DB::table('purchases')->where('id', $nativeId)->value('deleted_at'));
        $this->assertSame(1, $this->movementCount('PurchaseReversal'));
    }

    // =====================================================================
    // TRANSACTION / IDEMPOTENCY
    // =====================================================================

    public function test_update_failure_after_reverse_rolls_everything_back(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 10);
        [$pid, $did] = $this->createReceived($p, 5);
        $this->assertSame(15.0, $this->locStock($this->loc, $p));
        $snapBefore = DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot');

        // second line has product_id = null -> PurchaseDetail::create() fails
        // AFTER reverseSnapshot(rev1) already ran inside the transaction.
        $req = $this->makeRequest($this->payload([
            $this->line(['id' => $did, 'product_id' => $p, 'quantity' => 6]),
            $this->line(['id' => 999999, 'product_id' => null, 'quantity' => 2]),
        ], 'received'), 'PUT');

        try {
            $this->controller()->update($req, $pid);
            $this->fail('expected the invalid line to abort');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(15.0, $this->locStock($this->loc, $p)); // reverse rolled back
        $this->assertSame(1, DB::table('purchase_details')->where('purchase_id', $pid)->count());
        $this->assertEquals(5, (float) DB::table('purchase_details')->where('id', $did)->value('quantity'));
        $this->assertSame($snapBefore, DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot'));
        $this->assertSame(1, $this->movementCount('Purchase'));         // only the original apply
        $this->assertSame(0, $this->movementCount('PurchaseReversal')); // reverse rolled back
    }

    public function test_replaying_the_store_closure_does_not_duplicate_movements(): void
    {
        $this->lp();
        $p = $this->makeProduct();
        $this->seedLocationStock($this->loc, $p, 0);
        [$pid] = $this->createReceived($p, 6);
        $this->assertSame(6.0, $this->locStock($this->loc, $p));

        // Re-apply the persisted snapshot for the same purchase id + revision.
        $snap = json_decode(DB::table('purchases')->where('id', $pid)->value('inventory_effect_snapshot'), true);
        DB::transaction(fn () => app(\App\Services\LocationAwarePurchaseStockService::class)->applySnapshot($snap, $pid));

        $this->assertSame(6.0, $this->locStock($this->loc, $p)); // not 12
        $this->assertSame(1, DB::table('inventory_location_movements')
            ->where('idempotency_key', 'purchase:'.$pid.':rev:1:effect:0:apply')->count());
    }
}
