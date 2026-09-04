<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesReturnController;
use App\Models\InventoryTransitionState as Mode;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B2 — serial / IMEI location-native ACTIVATED in PurchasesReturnController
 * for MANUAL returns (store / update / destroy / delete_by_selection).
 *
 * PurchaseReturn physical direction: location -> supplier. Serial apply:
 * available@selected-location -> returned_supplier (general decrease); reverse:
 * returned_supplier -> available@ORIGINAL-return-location (general increase).
 * Serial selection is EXPLICIT (no FEFO). When the Return is linked to a
 * Purchase, each serial must additionally originate from it; an unlinked
 * Return imposes no such restriction. The snapshot is the reverse authority.
 *
 * Modeled on PurchasesSerialLocationNativeTest (MS6-B1) and
 * PurchaseReturnsBatchLocationNativeTest (MS5-D).
 */
class PurchaseReturnsSerialLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $unit1;
    private int $unit12;
    private int $unitDiv;
    private int $loc;
    private int $locB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildSerialSchema();
        $this->buildBatchSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('CD-RET-SERIAL');
        $this->unit1 = $this->makeUnit('*', 1);
        $this->unit12 = $this->makeUnit('*', 12);
        $this->unitDiv = $this->makeUnit('/', 4);
        $this->loc = $this->makeInventoryLocation($this->wh);
        $this->locB = $this->makeInventoryLocation($this->wh, ['code' => 'RB']);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    /** Local copy — the batch+IMEI 422 fence + the mixed-document case need it. */
    private function buildBatchSchema(): void
    {
        Schema::create('product_batches', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('batch_no');
            $t->date('expiry_date')->nullable();
            $t->date('mfg_date')->nullable();
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->integer('provider_id')->nullable();
            $t->integer('source_purchase_id')->nullable();
            $t->string('status')->default('active');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_batch_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('product_batch_id');
            $t->integer('inventory_location_id');
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('reserved_quantity', 12, 3)->default(0);
            $t->timestamps();
            $t->unique(['product_batch_id', 'inventory_location_id'], 'pbls_uq');
        });
        Schema::create('product_batch_location_movements', function ($t) {
            $t->increments('id');
            $t->integer('product_batch_id');
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->integer('user_id')->nullable();
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_return_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('purchase_return_detail_id');
            $t->integer('product_batch_id');
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    private function controller(): PurchasesReturnController
    {
        return new PurchasesReturnController;
    }

    private function lp(string $status = 'healthy', ?int $wh = null): void
    {
        $this->setTransitionMode($wh ?? $this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    private function imei(string $code, ?int $unit = null, string $type = 'is_single'): int
    {
        return (int) $this->makeProduct([
            'code' => $code, 'type' => $type, 'is_imei' => 1,
            'unit_purchase_id' => $unit ?? $this->unit1, 'cost' => 2,
        ]);
    }

    /** Seed an `available` ProductSerial ready to be returned. */
    private function seedAvailable(string $sn, int $productId, ?int $purchaseId = null, ?int $variantId = null, ?int $locationId = null): int
    {
        return (int) DB::table('product_serials')->insertGetId([
            'serial_number' => $sn, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'warehouse_id' => $this->wh, 'inventory_location_id' => $locationId ?? $this->loc,
            'status' => ProductSerial::STATUS_AVAILABLE, 'purchase_id' => $purchaseId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Create a minimal real Purchase row to link a Return to (provenance tests). */
    private function makePurchase(): int
    {
        return (int) DB::table('purchases')->insertGetId([
            'Ref' => 'PO-'.mt_rand(1000, 9999), 'warehouse_id' => $this->wh, 'provider_id' => 7,
            'statut' => 'received', 'date' => '2026-09-01', 'GrandTotal' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<int,string>|string|null  $serials */
    private function line(int $productId, int $unitId, float $qty, $serials = null, ?int $variantId = null, array $batches = []): array
    {
        $row = [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'purchase_unit_id' => $unitId,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'no_unit' => 1,
        ];
        if ($serials !== null) {
            $row['serial_numbers'] = $serials;
        }
        if ($batches) {
            $row['batches'] = $batches;
        }

        return $row;
    }

    private function payload(array $details, string $statut = 'completed', $wh = null, $loc = 'DEFAULT', $purchaseId = null): array
    {
        return [
            'supplier_id' => 7,
            'purchase_id' => $purchaseId,
            'warehouse_id' => $wh ?? $this->wh,
            'inventory_location_id' => $loc === 'DEFAULT' ? $this->loc : $loc,
            'date' => '2026-09-12',
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

    private function doUpdate(int $id, array $payload): void
    {
        $this->controller()->update($this->makeRequest($payload), $id);
    }

    private function doDestroy(int $id): void
    {
        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);
    }

    private function lastReturnId(): int
    {
        return (int) DB::table('purchase_returns')->orderByDesc('id')->value('id');
    }

    /** Store a single-line COMPLETED native return; return its id. */
    private function completed(int $productId, float $qty, array $serials, ?int $variantId = null, ?int $purchaseId = null, ?int $loc = null): int
    {
        $this->store($this->payload([$this->line($productId, $this->unit1, $qty, $serials, $variantId)], 'completed', null, $loc ?? 'DEFAULT', $purchaseId));

        return $this->lastReturnId();
    }

    private function snap(int $id): array
    {
        return json_decode((string) DB::table('purchase_returns')->where('id', $id)->value('inventory_effect_snapshot'), true) ?: [];
    }

    private function serialsBy(array $where): int
    {
        $q = DB::table('product_serials');
        foreach ($where as $k => $v) {
            $q->where($k, $v);
        }

        return (int) $q->count();
    }

    private function pw(): int
    {
        return (int) DB::table('product_warehouse')->count();
    }

    private function serialMovAll(): int
    {
        return (int) DB::table('product_serial_movements')->count();
    }

    // =====================================================================
    // STORE — COMPLETED
    // =====================================================================

    public function test_store_completed_single_serial(): void
    {
        $this->lp();
        $p = $this->imei('S1');
        $this->seedLocationStock($this->loc, $p, 1);
        $sid = $this->seedAvailable('SN-1', $p);

        $rid = $this->completed($p, 1, ['SN-1']);
        $did = (int) DB::table('purchase_return_details')->where('purchase_return_id', $rid)->value('id');

        $row = $this->serialRow('SN-1');
        $this->assertSame($sid, (int) $row->id);
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $row->status);
        $this->assertSame($this->loc, (int) $row->inventory_location_id, 'location KEPT (last physical location)');
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->pw(), 'product_warehouse untouched');

        $mv = DB::table('product_serial_movements')->where('serial_number', 'SN-1')->orderByDesc('id')->first();
        $this->assertSame(ProductSerialMovement::ACTION_PURCHASE_RETURNED, $mv->action);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $mv->from_status);
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $mv->to_status);
        $this->assertSame($this->loc, (int) $mv->from_inventory_location_id);
        $this->assertNull($mv->to_inventory_location_id);
        $this->assertSame('PurchaseReturn', $mv->reference_type);
        $this->assertSame($rid, (int) $mv->reference_id);
        $this->assertStringContainsString('purchase_return:'.$rid.':rev:1:detail:'.$did.':s:0:apply', (string) $mv->idempotency_key);

        $eff = $this->snap($rid)['effects'][0];
        $this->assertCount(1, $eff['serial_allocation']);
        $this->assertSame('SN-1', $eff['serial_allocation'][0]['serial_number']);
        $this->assertSame(1.0, (float) $eff['quantity_base']);
    }

    public function test_store_completed_multiple_serials(): void
    {
        $this->lp();
        $p = $this->imei('S2');
        $this->seedLocationStock($this->loc, $p, 3);
        foreach (['A', 'B', 'C'] as $sn) {
            $this->seedAvailable($sn, $p);
        }

        $rid = $this->completed($p, 3, ['A', 'B', 'C']);

        $this->assertSame(3, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_RETURNED_SUPPLIER]));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertCount(3, $this->snap($rid)['effects'][0]['serial_allocation']);
    }

    public function test_store_10_boxes_of_12_needs_120_serials(): void
    {
        $this->lp();
        $p = $this->imei('BOX', $this->unit12);
        $this->seedLocationStock($this->loc, $p, 120);
        for ($i = 1; $i <= 120; $i++) {
            $this->seedAvailable("X$i", $p);
        }

        try {
            $this->store($this->payload([$this->line($p, $this->unit12, 10, array_map(fn ($i) => "X$i", range(1, 10)))]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('120', json_encode($e->errors()));
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());

        $this->store($this->payload([$this->line($p, $this->unit12, 10, array_map(fn ($i) => "X$i", range(1, 120)))]));
        $rid = $this->lastReturnId();

        $this->assertSame(10.0, (float) DB::table('purchase_return_details')->value('quantity'));
        $this->assertSame(120, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_RETURNED_SUPPLIER]));
        $this->assertSame(120.0, (float) $this->snap($rid)['effects'][0]['quantity_base']);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_store_operator_divide_integer_base(): void
    {
        $this->lp();
        $p = $this->imei('DIV1', $this->unitDiv);   // '/' 4 : qty 8 -> base 2
        $this->seedLocationStock($this->loc, $p, 2);
        $this->seedAvailable('D1', $p);
        $this->seedAvailable('D2', $p);

        $this->store($this->payload([$this->line($p, $this->unitDiv, 8, ['D1', 'D2'])]));

        $this->assertSame(2, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_RETURNED_SUPPLIER]));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_store_fractional_base_is_422(): void
    {
        $this->lp();
        $p = $this->imei('DIV2', $this->unitDiv);   // '/' 4 : qty 2 -> base 0.5
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('F1', $p);

        try {
            $this->store($this->payload([$this->line($p, $this->unitDiv, 2, ['F1'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('entera', json_encode($e->errors()));
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('F1')->status);
    }

    public function test_store_variant_plus_imei(): void
    {
        $this->lp();
        $p = $this->imei('VAR', $this->unit1, 'is_variant');
        $v = $this->makeVariant($p, 'V1');
        $this->seedLocationStock($this->loc, $p, 2, $v);
        $this->seedAvailable('VV-1', $p, null, $v);
        $this->seedAvailable('VV-2', $p, null, $v);

        $rid = $this->completed($p, 2, ['VV-1', 'VV-2'], $v);

        foreach (['VV-1', 'VV-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame($v, (int) $row->product_variant_id);
            $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $row->status);
        }
        $this->assertSame(0.0, $this->locStock($this->loc, $p, $v));
        $this->assertSame($v, (int) $this->snap($rid)['effects'][0]['product_variant_id']);
    }

    public function test_store_batch_plus_imei_is_422(): void
    {
        $this->lp();
        $p = (int) $this->makeProduct([
            'code' => 'BI', 'is_imei' => 1, 'is_batch_tracked' => true,
            'unit_purchase_id' => $this->unit1, 'cost' => 2,
        ]);
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('BI-1', $p);

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 1, ['BI-1'], null, [['product_batch_id' => 1, 'qty' => 1]])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BI-1')->status);
        $this->assertSame(0, $this->serialMovAll());
    }

    public function test_store_duplicate_serial_input_is_422(): void
    {
        $this->lp();
        $p = $this->imei('DUP');
        $this->seedLocationStock($this->loc, $p, 2);
        $this->seedAvailable('DUPX', $p);

        $this->expectException(ValidationException::class);
        $this->store($this->payload([$this->line($p, $this->unit1, 2, ['DUPX', 'DUPX'])]));
    }

    public function test_store_unavailable_serial_is_422(): void
    {
        $this->lp();
        $p = $this->imei('UNAV');
        $this->seedLocationStock($this->loc, $p, 1);
        DB::table('product_serials')->insert([
            'serial_number' => 'SOLD-1', 'product_id' => $p, 'warehouse_id' => $this->wh,
            'inventory_location_id' => $this->loc, 'status' => ProductSerial::STATUS_SOLD,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['SOLD-1'])]));
    }

    public function test_store_serial_wrong_location_is_422(): void
    {
        $this->lp();
        $p = $this->imei('WLOC');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('WL-1', $p, null, null, $this->locB); // available at locB, not loc

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 1, ['WL-1'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('WL-1')->status);
    }

    public function test_store_serial_wrong_product_is_422(): void
    {
        $this->lp();
        $p = $this->imei('WP-A');
        $other = $this->imei('WP-B');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('WP-1', $other); // belongs to a different product

        $this->expectException(ValidationException::class);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['WP-1'])]));
    }

    public function test_store_serial_wrong_variant_is_422(): void
    {
        $this->lp();
        $p = $this->imei('WV', $this->unit1, 'is_variant');
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        $this->seedLocationStock($this->loc, $p, 1, $v1);
        $this->seedAvailable('WV-1', $p, null, $v2); // wrong variant

        $this->expectException(ValidationException::class);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['WV-1'], $v1)]));
    }

    public function test_store_linked_purchase_matching_serial_accepted(): void
    {
        $this->lp();
        $purchaseId = $this->makePurchase();
        $p = $this->imei('LNK');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('LNK-1', $p, $purchaseId);

        $rid = $this->completed($p, 1, ['LNK-1'], null, $purchaseId);

        $this->assertSame($purchaseId, (int) DB::table('purchase_returns')->where('id', $rid)->value('purchase_id'));
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('LNK-1')->status);
    }

    public function test_store_linked_purchase_wrong_origin_serial_is_422(): void
    {
        $this->lp();
        $purchaseId = $this->makePurchase();
        $otherPurchaseId = $this->makePurchase();
        $p = $this->imei('WRONGORIGIN');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('WO-1', $p, $otherPurchaseId); // belongs to a DIFFERENT purchase

        try {
            $this->completed($p, 1, ['WO-1'], null, $purchaseId);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('WO-1')->status);
    }

    public function test_store_unlinked_return_valid_serial_accepted(): void
    {
        $this->lp();
        $p = $this->imei('UNLINK');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('UL-1', $p, null); // no purchase_id at all

        $rid = $this->completed($p, 1, ['UL-1'], null, null);

        $this->assertNull(DB::table('purchase_returns')->where('id', $rid)->value('purchase_id'));
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('UL-1')->status);
    }

    public function test_store_coverage_drift_is_422(): void
    {
        $this->lp();
        $p = $this->imei('DRIFT');
        // general 5 but only 4 available serials at this location => not ready.
        $this->seedLocationStock($this->loc, $p, 5);
        for ($i = 1; $i <= 4; $i++) {
            $this->seedAvailable("DR-$i", $p);
        }

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 1, ['DR-1'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_transition', $e->errors());
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
    }

    public function test_store_product_warehouse_unchanged(): void
    {
        $this->lp();
        $p = $this->imei('PW');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('PW-1', $p);

        $this->completed($p, 1, ['PW-1']);

        $this->assertSame(0, $this->pw());
    }

    public function test_store_mixed_simple_batch_serial(): void
    {
        $this->lp();
        $simple = (int) $this->makeProduct(['code' => 'MX-S', 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $batch = (int) $this->makeProduct(['code' => 'MX-B', 'is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $serial = $this->imei('MX-I');
        $this->seedLocationStock($this->loc, $simple, 3);
        $this->seedLocationStock($this->loc, $batch, 5);
        $this->seedLocationStock($this->loc, $serial, 2);
        $bid = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $batch, 'warehouse_id' => $this->wh, 'batch_no' => 'L', 'qty' => 5,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert(['product_batch_id' => $bid, 'inventory_location_id' => $this->loc, 'quantity' => 5, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedAvailable('MI-1', $serial);
        $this->seedAvailable('MI-2', $serial);

        $this->store($this->payload([
            $this->line($simple, $this->unit1, 3),
            $this->line($batch, $this->unit1, 5, null, null, [['product_batch_id' => $bid, 'qty' => 5]]),
            $this->line($serial, $this->unit1, 2, ['MI-1', 'MI-2']),
        ]));
        $rid = $this->lastReturnId();

        $effects = $this->snap($rid)['effects'];
        $this->assertCount(3, $effects);
        $this->assertSame(0.0, $this->locStock($this->loc, $simple));
        $this->assertSame(0.0, $this->locStock($this->loc, $batch));
        $this->assertSame(0.0, $this->locStock($this->loc, $serial));
        $this->assertSame(0, $this->pw());
    }

    public function test_store_late_failure_is_total_rollback(): void
    {
        $this->lp();
        $simple = (int) $this->makeProduct(['code' => 'LF-S', 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $s1 = $this->imei('LF1');
        $s2 = $this->imei('LF2');
        $this->seedLocationStock($this->loc, $simple, 3);
        $this->seedLocationStock($this->loc, $s1, 1);
        $this->seedLocationStock($this->loc, $s2, 1);
        $this->seedAvailable('GOOD', $s1);
        // s2 has NO available serial named GOOD => detail4-style invalid serial (not found).

        try {
            $this->store($this->payload([
                $this->line($simple, $this->unit1, 3),
                $this->line($s1, $this->unit1, 1, ['GOOD']),
                $this->line($s2, $this->unit1, 1, ['GOOD']), // duplicate + doesn't belong to s2
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, DB::table('purchase_return_details')->count());
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('GOOD')->status);
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(0, $this->serialMovAll());
    }

    // =====================================================================
    // STORE — NON-COMPLETED
    // =====================================================================

    public function test_non_completed_creates_no_serial_effect(): void
    {
        $this->lp();
        $p = $this->imei('NC');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('NC-1', $p);

        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['NC-1'])], 'pending'));

        $this->assertSame(1, DB::table('purchase_returns')->count());
        $this->assertNull(DB::table('purchase_returns')->value('inventory_effect_snapshot'));
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('NC-1')->status, 'not mutated');
        $this->assertSame(1.0, $this->locStock($this->loc, $p), 'unchanged');
        $this->assertSame(0, $this->serialMovAll());
    }

    public function test_non_completed_serial_payload_still_no_physical_effect(): void
    {
        $this->lp();
        $p = $this->imei('NC2');
        $this->seedLocationStock($this->loc, $p, 5);
        for ($i = 1; $i <= 5; $i++) {
            $this->seedAvailable("NC2-$i", $p);
        }

        // Even a fully-formed serial payload has zero physical effect while pending.
        $this->store($this->payload([$this->line($p, $this->unit1, 5, array_map(fn ($i) => "NC2-$i", range(1, 5)))], 'pending'));

        $this->assertSame(5, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(5.0, $this->locStock($this->loc, $p));
    }

    // =====================================================================
    // UPDATE — state machine
    // =====================================================================

    public function test_update_pending_to_pending_no_effect(): void
    {
        $this->lp();
        $p = $this->imei('U-PP');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('PP-1', $p);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['PP-1'])], 'pending'));
        $rid = $this->lastReturnId();

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['PP-1'])], 'pending'));

        $this->assertNull(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'));
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('PP-1')->status);
    }

    public function test_update_pending_to_completed_rev1(): void
    {
        $this->lp();
        $p = $this->imei('U-PC');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('PC-1', $p);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['PC-1'])], 'pending'));
        $rid = $this->lastReturnId();

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['PC-1'])], 'completed'));

        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('PC-1')->status);
        $this->assertSame(1, (int) $this->snap($rid)['revision']);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_update_completed_to_completed_same_serial(): void
    {
        $this->lp();
        $p = $this->imei('U-SAME');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('SA-1', $p);
        $rid = $this->completed($p, 1, ['SA-1']);
        $sid = (int) $this->serialRow('SA-1')->id;

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['SA-1'])], 'completed'));

        $row = $this->serialRow('SA-1');
        $this->assertSame($sid, (int) $row->id, 'same ProductSerial id');
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $row->status);
        $this->assertSame($this->loc, (int) $row->inventory_location_id);
        $this->assertSame(2, (int) $this->snap($rid)['revision']);
        $this->assertGreaterThanOrEqual(2, DB::table('product_serial_movements')->where('serial_number', 'SA-1')->count());
    }

    public function test_update_completed_to_completed_changed_serial(): void
    {
        $this->lp();
        $p = $this->imei('U-CH');
        $this->seedLocationStock($this->loc, $p, 2);
        $this->seedAvailable('C1', $p);
        $this->seedAvailable('C2', $p);
        $rid = $this->completed($p, 1, ['C1']);
        // C2 becomes the new selection; pre-state for the new apply is 1
        // available (C2) — the old-reverse already restored C1 -> general 1.

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['C2'])], 'completed'));

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('C1')->status, 'reverted');
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('C2')->status);
    }

    public function test_update_completed_to_non_completed_reverses(): void
    {
        $this->lp();
        $p = $this->imei('U-R2P');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('RP-1', $p);
        $rid = $this->completed($p, 1, ['RP-1']);

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['RP-1'])], 'pending'));

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RP-1')->status);
        $this->assertSame($this->loc, (int) $this->serialRow('RP-1')->inventory_location_id);
        $this->assertSame(1.0, $this->locStock($this->loc, $p));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'), 'historical snapshot kept');
        $this->assertSame(1, (int) $this->snap($rid)['revision'], 'no new effect, no bump');
    }

    public function test_update_pending_to_completed_revision_progresses(): void
    {
        $this->lp();
        $p = $this->imei('U-REV');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('RV', $p);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'pending'));
        $rid = $this->lastReturnId();

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'completed'));
        $this->assertSame(1, (int) $this->snap($rid)['revision']);

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'completed'));
        $this->assertSame(2, (int) $this->snap($rid)['revision']);

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'pending'));
        $this->assertSame(2, (int) $this->snap($rid)['revision'], 'preserved');

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'completed'));
        $this->assertSame(3, (int) $this->snap($rid)['revision']);
    }

    public function test_update_same_serial_location_change_is_422(): void
    {
        $this->lp();
        $p = $this->imei('U-TP');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedLocationStock($this->locB, $p, 0);
        $this->seedAvailable('TP-X', $p);
        $rid = $this->completed($p, 1, ['TP-X']);
        // TP-X is now returned_supplier @ loc (its last physical location).

        // Attempt to move the Return to locB while reusing the SAME serial: the
        // reverse restores TP-X to available@loc, but the new planner requires
        // available@locB — this MUST fail 422 (no teleportation).
        try {
            $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['TP-X'])], 'completed', null, $this->locB));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        // whole update rolled back: TP-X still returned_supplier @ original loc.
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('TP-X')->status);
        $this->assertSame($this->loc, (int) $this->serialRow('TP-X')->inventory_location_id);
    }

    public function test_update_location_change_different_available_serial_works(): void
    {
        $this->lp();
        $p = $this->imei('U-DIFFLOC');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedLocationStock($this->locB, $p, 1);
        $this->seedAvailable('OLD-X', $p);
        $this->seedAvailable('NEW-Y', $p, null, null, $this->locB);
        $rid = $this->completed($p, 1, ['OLD-X']);

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['NEW-Y'])], 'completed', null, $this->locB));

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('OLD-X')->status, 'reverted to old location');
        $this->assertSame($this->loc, (int) $this->serialRow('OLD-X')->inventory_location_id);
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('NEW-Y')->status);
        $this->assertSame($this->locB, (int) $this->serialRow('NEW-Y')->inventory_location_id);
    }

    public function test_update_warehouse_change_valid_different_serial_works(): void
    {
        $this->lp();
        $wh2 = $this->makeWarehouse('CD-RET-SERIAL-2');
        $loc2 = $this->makeInventoryLocation($wh2);
        $this->setTransitionMode($wh2, Mode::MODE_LOCATION_PRIMARY, $loc2, 'healthy');

        $p = $this->imei('U-WH2');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedLocationStock($loc2, $p, 1);
        $this->seedAvailable('WHX-OLD', $p);
        $this->seedAvailable('WHX-NEW', $p, null, null, $loc2);
        $rid = $this->completed($p, 1, ['WHX-OLD']);

        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['WHX-NEW'])], 'completed', $wh2, $loc2));

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('WHX-OLD')->status, 'reverted at the original warehouse');
        $this->assertSame($this->loc, (int) $this->serialRow('WHX-OLD')->inventory_location_id);
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('WHX-NEW')->status);
        $this->assertSame($loc2, (int) $this->serialRow('WHX-NEW')->inventory_location_id);
        $this->assertSame($wh2, (int) DB::table('purchase_returns')->where('id', $rid)->value('warehouse_id'));
    }

    public function test_update_primary_to_legacy_is_422(): void
    {
        $this->lp();
        $p = $this->imei('U-P2L');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('P2L-1', $p);
        $rid = $this->completed($p, 1, ['P2L-1']);

        $this->setTransitionMode($this->wh, Mode::MODE_LEGACY_ONLY, null, 'healthy');
        try {
            $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['P2L-1'])], 'completed'));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('P2L-1')->status);
    }

    public function test_update_reverse_corrupt_snapshot_is_422(): void
    {
        $this->lp();
        $p = $this->imei('U-CORR');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('CORR-1', $p);
        $rid = $this->completed($p, 1, ['CORR-1']);

        $snap = $this->snap($rid);
        $snap['effects'][0]['serial_allocation'][0]['serial_number'] = 'DIFFERENT';
        DB::table('purchase_returns')->where('id', $rid)->update(['inventory_effect_snapshot' => json_encode($snap)]);

        $this->expectException(ValidationException::class);
        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['CORR-1'])], 'completed'));
    }

    public function test_update_wrong_status_before_reverse_is_422(): void
    {
        $this->lp();
        $p = $this->imei('U-WS');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('WS-1', $p);
        $rid = $this->completed($p, 1, ['WS-1']);

        // simulate a downstream event: the returned serial got sold somehow.
        DB::table('product_serials')->where('serial_number', 'WS-1')->update(['status' => ProductSerial::STATUS_SOLD]);

        try {
            $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['WS-1'])], 'completed'));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('WS-1')->status, 'untouched');
    }

    public function test_update_display_metadata_corruption_does_not_affect_reverse(): void
    {
        $this->lp();
        $p = $this->imei('U-DISP');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('DISP-1', $p);
        $rid = $this->completed($p, 1, ['DISP-1']);
        $did = (int) DB::table('purchase_return_details')->where('purchase_return_id', $rid)->value('id');
        DB::table('purchase_return_details')->where('id', $did)->update(['imei_number' => 'GARBAGE']);

        $this->doDestroy($rid);

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('DISP-1')->status);
    }

    // =====================================================================
    // DESTROY
    // =====================================================================

    public function test_destroy_completed_restores_serial_and_general(): void
    {
        $this->lp();
        $p = $this->imei('DEL');
        $this->seedLocationStock($this->loc, $p, 2);
        $this->seedAvailable('DL-1', $p);
        $this->seedAvailable('DL-2', $p);
        $rid = $this->completed($p, 2, ['DL-1', 'DL-2']);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));

        $this->doDestroy($rid);

        foreach (['DL-1', 'DL-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
            $this->assertSame($this->loc, (int) $row->inventory_location_id);
        }
        $this->assertSame(2.0, $this->locStock($this->loc, $p));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
    }

    public function test_destroy_non_completed_no_effect(): void
    {
        $this->lp();
        $p = $this->imei('DELP');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('DP-1', $p);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['DP-1'])], 'pending'));
        $rid = $this->lastReturnId();

        $this->doDestroy($rid);

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('DP-1')->status, 'unchanged');
        $this->assertSame(1.0, $this->locStock($this->loc, $p));
    }

    public function test_destroy_historical_snapshot_pending_not_reversed_twice(): void
    {
        $this->lp();
        $p = $this->imei('DELHIST');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('DH-1', $p);
        $rid = $this->completed($p, 1, ['DH-1']);
        // completed -> pending: reverses once, historical snapshot kept.
        $this->doUpdate($rid, $this->payload([$this->line($p, $this->unit1, 1, ['DH-1'])], 'pending'));
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('DH-1')->status);
        $this->assertSame(1.0, $this->locStock($this->loc, $p));

        // destroy while pending: current status wins, NOT reversed again.
        $this->doDestroy($rid);

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('DH-1')->status);
        $this->assertSame(1.0, $this->locStock($this->loc, $p));
    }

    public function test_destroy_wrong_serial_status_blocks(): void
    {
        $this->lp();
        $p = $this->imei('DELWS');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('DWS-1', $p);
        $rid = $this->completed($p, 1, ['DWS-1']);

        DB::table('product_serials')->where('serial_number', 'DWS-1')->update(['status' => ProductSerial::STATUS_DAMAGED]);

        try {
            $this->doDestroy($rid);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
        $this->assertSame(ProductSerial::STATUS_DAMAGED, $this->serialRow('DWS-1')->status);
    }

    // =====================================================================
    // BULK — delete_by_selection
    // =====================================================================

    public function test_bulk_native_serial_success(): void
    {
        $this->lp();
        $a = $this->imei('BKA');
        $b = $this->imei('BKB');
        $this->seedLocationStock($this->loc, $a, 1);
        $this->seedLocationStock($this->loc, $b, 1);
        $this->seedAvailable('BKA-1', $a);
        $this->seedAvailable('BKB-1', $b);
        $ridA = $this->completed($a, 1, ['BKA-1']);
        $ridB = $this->completed($b, 1, ['BKB-1']);

        $this->controller()->delete_by_selection($this->makeRequest(['selectedIds' => [$ridA, $ridB]]));

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BKA-1')->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BKB-1')->status);
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $ridA)->value('deleted_at'));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $ridB)->value('deleted_at'));
    }

    public function test_bulk_mixed_legacy_simple_batch_serial(): void
    {
        // legacy warehouse (no transition state) + native serial return.
        $legacyWh = $this->makeWarehouse('LEG-RET');
        $lp = $this->makeProduct(['unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedStock($legacyWh, $lp, 5);
        $legReq = $this->makeRequest([
            'supplier_id' => 1, 'purchase_id' => null, 'warehouse_id' => $legacyWh, 'date' => '2026-09-10', 'statut' => 'completed',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [[
                'product_id' => $lp, 'product_variant_id' => null, 'purchase_unit_id' => $this->unit1,
                'quantity' => 2, 'Unit_cost' => 1, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0,
                'discount_Method' => '2', 'subtotal' => 2, 'imei_number' => null, 'no_unit' => 1,
            ]],
        ]);
        $this->controller()->store($legReq);
        $legacyId = (int) DB::table('purchase_returns')->orderByDesc('id')->value('id');

        $this->lp();
        $p = $this->imei('BKN');
        $this->seedLocationStock($this->loc, $p, 1);
        $this->seedAvailable('BKN-1', $p);
        $nativeId = $this->completed($p, 1, ['BKN-1']);

        $this->controller()->delete_by_selection($this->makeRequest(['selectedIds' => [$legacyId, $nativeId]]));

        $this->assertNotNull(DB::table('purchase_returns')->where('id', $legacyId)->value('deleted_at'));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $nativeId)->value('deleted_at'));
        $this->assertSame(5.0, $this->stockOf($legacyWh, $lp), 'legacy reversed via product_warehouse (fully restored)');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BKN-1')->status, 'native reversed via snapshot');
    }

    public function test_bulk_aborts_all_on_one_non_reversible_serial(): void
    {
        $this->lp();
        $a = $this->imei('BKAA');
        $b = $this->imei('BKBB');
        $this->seedLocationStock($this->loc, $a, 1);
        $this->seedLocationStock($this->loc, $b, 1);
        $this->seedAvailable('BKAA-1', $a);
        $this->seedAvailable('BKBB-1', $b);
        $ridA = $this->completed($a, 1, ['BKAA-1']);
        $ridB = $this->completed($b, 1, ['BKBB-1']);

        DB::table('product_serials')->where('serial_number', 'BKBB-1')->update(['status' => ProductSerial::STATUS_SOLD]);

        try {
            $this->controller()->delete_by_selection($this->makeRequest(['selectedIds' => [$ridA, $ridB]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }

        $this->assertNull(DB::table('purchase_returns')->where('id', $ridA)->value('deleted_at'));
        $this->assertNull(DB::table('purchase_returns')->where('id', $ridB)->value('deleted_at'));
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('BKAA-1')->status);
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('BKBB-1')->status);
    }

    // =====================================================================
    // INTEROP — with MS6-B1 Purchase
    // =====================================================================

    public function test_interop_serial_from_ms6b1_purchase_can_be_returned(): void
    {
        $this->lp();
        $purchaseId = $this->makePurchase();
        $p = $this->imei('INTEROP1');
        $this->seedLocationStock($this->loc, $p, 1);
        // Simulate a serial received via MS6-B1 (available@loc, purchase_id set).
        $this->seedAvailable('IOP-1', $p, $purchaseId);

        $rid = $this->completed($p, 1, ['IOP-1'], null, $purchaseId);

        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('IOP-1')->status);
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('id'));
    }

    public function test_interop_serial_moved_internally_can_be_returned_from_current_location(): void
    {
        $this->lp();
        $purchaseId = $this->makePurchase();
        $p = $this->imei('INTEROP2');
        // Received at loc, then internally moved to locB (available@locB now).
        $this->seedLocationStock($this->loc, $p, 0);
        $this->seedLocationStock($this->locB, $p, 1);
        $this->seedAvailable('IOP-2', $p, $purchaseId, null, $this->locB);

        $rid = $this->completed($p, 1, ['IOP-2'], null, $purchaseId, $this->locB);

        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('IOP-2')->status);
        $this->assertSame($this->locB, (int) $this->serialRow('IOP-2')->inventory_location_id);
        $this->assertSame(0.0, $this->locStock($this->locB, $p));
    }

    public function test_interop_variant_serial_purchase_to_return_works(): void
    {
        $this->lp();
        $purchaseId = $this->makePurchase();
        $p = $this->imei('INTEROP3', $this->unit1, 'is_variant');
        $v = $this->makeVariant($p, 'V1');
        $this->seedLocationStock($this->loc, $p, 1, $v);
        $this->seedAvailable('IOP-3', $p, $purchaseId, $v);

        $rid = $this->completed($p, 1, ['IOP-3'], $v, $purchaseId);

        $row = $this->serialRow('IOP-3');
        $this->assertSame($v, (int) $row->product_variant_id);
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $row->status);
    }
}
