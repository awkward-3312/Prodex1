<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\InventoryTransitionState as Mode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS5-C — batch location-native activated in PurchasesController (manual
 * purchases). store / update / destroy / delete_by_selection.
 *
 * Contract vs the legacy golden master (PurchaseBatchLegacyCharacterizationTest):
 * NATIVE freezes the physical batch quantity in BASE UNIT; the
 * purchase_detail_batches pivot keeps the entered PURCHASE-unit quantity for
 * UX/reporting and is NEVER the source of a reverse.
 */
class PurchasesBatchLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $unit12;
    private int $unit1;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildBatchSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('CD-BATCH');
        $this->unit12 = $this->makeUnit('*', 12);
        $this->unit1 = $this->makeUnit('*', 1);
        $this->loc = $this->makeInventoryLocation($this->wh);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

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
        Schema::create('purchase_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('purchase_detail_id');
            $t->integer('product_batch_id');
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->timestamps();
        });
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function lp(string $status = 'healthy', ?int $wh = null): void
    {
        $this->setTransitionMode($wh ?? $this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    private function line(int $productId, int $unitId, float $qty, array $batches = [], ?int $variantId = null): array
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
            'batches' => $batches,
        ];
    }

    private function payload(array $details, string $statut = 'received', $wh = null, $loc = 'DEFAULT'): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $wh ?? $this->wh,
            'inventory_location_id' => $loc === 'DEFAULT' ? $this->loc : $loc,
            'date' => '2026-09-10',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    private function bp(int $productId): int
    {
        return (int) $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 2, 'code' => 'BP'.$productId]);
    }

    private function pw(): int
    {
        return (int) DB::table('product_warehouse')->count();
    }

    private function locMovements(?string $ref = null): int
    {
        $q = DB::table('product_batch_location_movements');
        if ($ref) {
            $q->where('reference_type', $ref);
        }

        return (int) $q->count();
    }

    private function batchByNo(string $no)
    {
        return DB::table('product_batches')->where('batch_no', $no)->first();
    }

    private function slice(int $batchId): float
    {
        return (float) DB::table('product_batch_location_stocks')->where('product_batch_id', $batchId)->value('quantity');
    }

    // ===================== STORE =====================

    public function test_store_received_single_batch(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 2]);
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit1, 5, [['batch_no' => 'LOT-A', 'qty' => 5, 'unit_cost' => 2]])]));
        $this->controller()->store($req);

        $b = $this->batchByNo('LOT-A');
        $this->assertNotNull($b);
        $this->assertSame(5.0, (float) $b->qty);
        $this->assertSame(5.0, $this->slice($b->id));
        $this->assertSame(5.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->locMovements('PurchaseBatch'));
        $this->assertSame(1, $this->movementCount('Purchase'));
        $piv = DB::table('purchase_detail_batches')->first();
        $this->assertSame($b->id, (int) $piv->product_batch_id);
        $this->assertSame(5.0, (float) $piv->qty);
        $this->assertSame(0, $this->pw());   // product_warehouse untouched
        $snap = json_decode(DB::table('purchases')->value('inventory_effect_snapshot'), true);
        $this->assertSame(5.0, (float) $snap['effects'][0]['batch_allocation'][0]['quantity_base']);
    }

    public function test_store_received_two_batches(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit1, 10, [
            ['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 4],
        ])]));
        $this->controller()->store($req);

        $this->assertSame(6.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(4.0, (float) $this->batchByNo('B')->qty);
        $this->assertSame(10.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, $this->locMovements('PurchaseBatch'));
        $this->assertSame(2, DB::table('purchase_detail_batches')->count());
    }

    public function test_store_10_boxes_of_12_native_contract(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit12, 'cost' => 5]);
        $this->seedStock($this->wh, $p, 0);              // legacy row present
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit12, 10, [
            ['batch_no' => 'LOT-A', 'qty' => 6, 'expiry_date' => '2027-01-31'],
            ['batch_no' => 'LOT-B', 'qty' => 4, 'expiry_date' => '2027-03-31'],
        ])]));
        $this->controller()->store($req);

        // PurchaseDetail.quantity = 10 (the entered box count).
        $this->assertSame(10.0, (float) DB::table('purchase_details')->value('quantity'));
        // pivot.qty = 6 / 4 (COMMERCIAL / purchase unit).
        $this->assertSame([6.0, 4.0], DB::table('purchase_detail_batches')->orderBy('id')->pluck('qty')->map(fn ($q) => (float) $q)->all());
        // snapshot + physical = BASE (72 / 48).
        $snap = json_decode(DB::table('purchases')->value('inventory_effect_snapshot'), true);
        $this->assertSame(120.0, (float) $snap['effects'][0]['quantity_base']);
        $this->assertSame([72.0, 48.0], array_map('floatval', array_column($snap['effects'][0]['batch_allocation'], 'quantity_base')));
        $this->assertSame(72.0, (float) $this->batchByNo('LOT-A')->qty);
        $this->assertSame(48.0, (float) $this->batchByNo('LOT-B')->qty);
        $this->assertSame(72.0, $this->slice($this->batchByNo('LOT-A')->id));
        $this->assertSame(48.0, $this->slice($this->batchByNo('LOT-B')->id));
        $this->assertSame(120.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, $this->locMovements());
        $this->assertSame(1, $this->movementCount('Purchase'));
        // product_warehouse UNCHANGED.
        $this->assertSame(0.0, $this->stockOf($this->wh, $p));
    }

    public function test_store_unit_divide(): void
    {
        $this->lp();
        $u = $this->makeUnit('/', 4);
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $u, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line($p, $u, 8, [['batch_no' => 'A', 'qty' => 8]])]));
        $this->controller()->store($req);

        $this->assertSame(2.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(2.0, $this->locStock($this->loc, $p));
    }

    public function test_store_variant_batch(): void
    {
        $this->lp();
        $p = $this->makeProduct(['type' => 'is_variant', 'is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $v = $this->makeVariant($p);
        $this->seedLocationStock($this->loc, $p, 0, $v);

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit1, 3, [['batch_no' => 'LOT-V', 'qty' => 3]], $v)]));
        $this->controller()->store($req);

        $b = $this->batchByNo('LOT-V');
        $this->assertSame($v, (int) $b->product_variant_id);
        $this->assertSame(3.0, $this->locStock($this->loc, $p, $v));
    }

    public function test_store_same_batch_no_two_details(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([
            $this->line($p, $this->unit1, 3, [['batch_no' => 'SHARED', 'qty' => 3]]),
            $this->line($p, $this->unit1, 4, [['batch_no' => 'SHARED', 'qty' => 4]]),
        ]));
        $this->controller()->store($req);

        $this->assertSame(1, DB::table('product_batches')->where('batch_no', 'SHARED')->count());
        $this->assertSame(7.0, (float) $this->batchByNo('SHARED')->qty);
        $this->assertSame(2, $this->locMovements('PurchaseBatch'));    // one ledger row per detail
        $this->assertSame(2, DB::table('purchase_detail_batches')->count());
    }

    public function test_store_existing_native_ready_batch_top_up(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $b = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 10,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert(['product_batch_id' => $b, 'inventory_location_id' => $this->loc, 'quantity' => 10, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedLocationStock($this->loc, $p, 10);

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit1, 5, [['batch_no' => 'LOT-A', 'qty' => 5]])]));
        $this->controller()->store($req);

        $this->assertSame(15.0, (float) $this->batchByNo('LOT-A')->qty);
        $this->assertSame(15.0, $this->slice($b));
        $this->assertSame(15.0, $this->locStock($this->loc, $p));
    }

    public function test_store_drifted_existing_batch_rolls_back(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $b = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 10,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert(['product_batch_id' => $b, 'inventory_location_id' => $this->loc, 'quantity' => 10, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedLocationStock($this->loc, $p, 120);   // legacy drift: general 120 vs batch 10

        try {
            $this->controller()->store($this->makeRequest($this->payload([$this->line($p, $this->unit1, 5, [['batch_no' => 'LOT-A', 'qty' => 5]])])));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(10.0, (float) $this->batchByNo('LOT-A')->qty);
        $this->assertSame(0, $this->locMovements());
    }

    public function test_store_soft_deleted_identity_rolls_back(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        DB::table('product_batches')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 0,
            'status' => 'active', 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        try {
            $this->controller()->store($this->makeRequest($this->payload([$this->line($p, $this->unit1, 3, [['batch_no' => 'LOT-A', 'qty' => 3]])])));
        } finally {
            $this->assertSame(0, DB::table('purchases')->count());
        }
    }

    public function test_store_conflicting_expiry_rolls_back(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        DB::table('product_batches')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 0,
            'expiry_date' => '2027-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        try {
            $this->controller()->store($this->makeRequest($this->payload([$this->line($p, $this->unit1, 3, [['batch_no' => 'LOT-A', 'qty' => 3, 'expiry_date' => '2028-01-01']])])));
        } finally {
            $this->assertSame(0, DB::table('purchases')->count());
            $this->assertStringStartsWith('2027-01-01', (string) $this->batchByNo('LOT-A')->expiry_date);
        }
    }

    public function test_store_expiry_null_completion_rolls_back_on_later_failure(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $b = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 10,
            'expiry_date' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert(['product_batch_id' => $b, 'inventory_location_id' => $this->loc, 'quantity' => 10, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedLocationStock($this->loc, $p, 999);   // coverage drift -> receiveMany fails AFTER metadata completion

        try {
            $this->controller()->store($this->makeRequest($this->payload([$this->line($p, $this->unit1, 5, [['batch_no' => 'LOT-A', 'qty' => 5, 'expiry_date' => '2027-09-09']])])));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // ok
        }
        // metadata completion rolled back with the whole transaction.
        $this->assertNull($this->batchByNo('LOT-A')->expiry_date);
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_store_imei_still_422(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->makeRequest($this->payload([$this->line($p, $this->unit1, 3, [['batch_no' => 'A', 'qty' => 3]])])));
    }

    // ===================== PENDING =====================

    public function test_store_pending_batch_creates_no_artifact(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]])], 'pending'));
        $this->controller()->store($req);

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertNull(DB::table('purchases')->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('product_batches')->count());
        $this->assertSame(0, DB::table('purchase_detail_batches')->count());
        $this->assertSame(0, DB::table('product_batch_location_stocks')->count());
        $this->assertSame(0, $this->locMovements());
        $this->assertSame(0, $this->movementCount());
    }

    public function test_pending_does_not_touch_pre_existing_batch(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $b = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'A', 'qty' => 10,
            'expiry_date' => '2027-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->controller()->store($this->makeRequest($this->payload([
            $this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5, 'expiry_date' => '2029-09-09']]),
        ], 'pending')));

        $fresh = $this->batchByNo('A');
        $this->assertSame(10.0, (float) $fresh->qty);
        $this->assertStringStartsWith('2027-01-01', (string) $fresh->expiry_date);
    }

    // ===================== UPDATE =====================

    private function storedPurchase(string $statut, array $line): int
    {
        $this->controller()->store($this->makeRequest($this->payload([$line], $statut)));

        return (int) DB::table('purchases')->orderByDesc('id')->value('id');
    }

    public function test_update_pending_to_pending(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('pending', $this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]]));

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit1, 8, [['batch_no' => 'A', 'qty' => 8]])], 'pending'));
        $this->controller()->update($req, $id);

        $this->assertNull(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('product_batches')->count());
        $this->assertSame(8.0, (float) DB::table('purchase_details')->where('purchase_id', $id)->value('quantity'));
    }

    public function test_update_pending_to_received_rev1(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('pending', $this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]]));

        $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]])], 'received')), $id);

        $snap = json_decode(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'), true);
        $this->assertSame(1, $snap['revision']);
        $this->assertSame(5.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(5.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->locMovements('PurchaseBatch'));
    }

    public function test_update_received_to_received_rev2_reverses_old_then_applies_new(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 6, [['batch_no' => 'A', 'qty' => 6]]));

        $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 4, [['batch_no' => 'B', 'qty' => 4]])], 'received')), $id);

        $snap = json_decode(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'), true);
        $this->assertSame(2, $snap['revision']);
        $this->assertSame(0.0, (float) $this->batchByNo('A')->qty);   // reversed
        $this->assertSame(4.0, (float) $this->batchByNo('B')->qty);   // new
        $this->assertSame(4.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->locMovements('PurchaseBatchReversal'));
        $this->assertSame(2, $this->locMovements('PurchaseBatch'));    // rev1 + rev2
    }

    public function test_update_received_to_pending_keeps_snapshot_but_reverses_stock(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 6, [['batch_no' => 'A', 'qty' => 6]]));

        $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 6, [['batch_no' => 'A', 'qty' => 6]])], 'pending')), $id);

        $this->assertNotNull(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'));   // kept
        $this->assertSame(0.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_update_pending_to_received_again_rev_continues(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 6, [['batch_no' => 'A', 'qty' => 6]]));
        $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 6, [['batch_no' => 'A', 'qty' => 6]])], 'pending')), $id);
        $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 3, [['batch_no' => 'C', 'qty' => 3]])], 'received')), $id);

        $snap = json_decode(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'), true);
        $this->assertSame(2, $snap['revision']);
        $this->assertSame(3.0, (float) $this->batchByNo('C')->qty);
        $this->assertSame(3.0, $this->locStock($this->loc, $p));
    }

    public function test_update_change_location_two_external_events(): void
    {
        $locB = $this->makeInventoryLocation($this->wh, ['code' => 'LB']);
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $this->seedLocationStock($locB, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 10, [['batch_no' => 'LOT1', 'qty' => 10]]));

        $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 7, [['batch_no' => 'LOT2', 'qty' => 7]])], 'received', null, $locB)), $id);

        $this->assertSame(0.0, (float) $this->batchByNo('LOT1')->qty);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(7.0, (float) $this->batchByNo('LOT2')->qty);
        $this->assertSame(7.0, $this->locStock($locB, $p));
    }

    public function test_update_primary_to_legacy_is_422(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]]));

        $this->setTransitionMode($this->wh, Mode::MODE_LEGACY_ONLY, null, 'healthy');
        try {
            $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]])], 'received')), $id);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertSame(5.0, (float) $this->batchByNo('A')->qty);
    }

    public function test_update_reverse_when_old_batch_partially_consumed_is_422_total_rollback(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 10, [['batch_no' => 'A', 'qty' => 10]]));

        // simulate a downstream consumption of 4 (sale / transfer) from the slice + aggregate + general.
        $b = $this->batchByNo('A');
        DB::table('product_batch_location_stocks')->where('product_batch_id', $b->id)->update(['quantity' => 6]);
        DB::table('product_batches')->where('id', $b->id)->update(['qty' => 6]);
        DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->update(['quantity' => 6]);

        try {
            $this->controller()->update($this->makeRequest($this->payload([$this->line($p, $this->unit1, 8, [['batch_no' => 'A', 'qty' => 8]])], 'received')), $id);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // ok
        }
        $this->assertSame(6.0, (float) $this->batchByNo('A')->qty);   // untouched
        $this->assertSame(1, DB::table('purchase_details')->where('purchase_id', $id)->count());   // details not replaced
        $snap = json_decode(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'), true);
        $this->assertSame(1, $snap['revision']);                       // no rev bump
    }

    // ===================== DESTROY =====================

    public function test_destroy_received_reverses_batch_and_general(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 10, [['batch_no' => 'A', 'qty' => 10]]));

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);

        $this->assertNotNull(DB::table('purchases')->where('id', $id)->value('deleted_at'));
        $this->assertSame(0.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->locMovements('PurchaseBatchReversal'));
        $this->assertNotSame(0, DB::table('product_batches')->count());   // batch row NOT deleted
        $this->assertSame(0, DB::table('purchase_detail_batches')->count());   // pivots gone
    }

    public function test_destroy_pending_no_stock_effect(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('pending', $this->line($p, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]]));

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);
        $this->assertNotNull(DB::table('purchases')->where('id', $id)->value('deleted_at'));
        $this->assertSame(0, $this->locMovements());
    }

    public function test_destroy_partially_consumed_is_422(): void
    {
        $this->lp();
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->storedPurchase('received', $this->line($p, $this->unit1, 10, [['batch_no' => 'A', 'qty' => 10]]));

        $b = $this->batchByNo('A');
        DB::table('product_batch_location_stocks')->where('product_batch_id', $b->id)->update(['quantity' => 6]);
        DB::table('product_batches')->where('id', $b->id)->update(['qty' => 6]);
        DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->update(['quantity' => 6]);

        $this->expectException(ValidationException::class);
        try {
            $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);
        } finally {
            $this->assertNull(DB::table('purchases')->where('id', $id)->value('deleted_at'));   // NOT deleted
            $this->assertSame(6.0, (float) $this->batchByNo('A')->qty);
        }
    }

    // ===================== BULK =====================

    public function test_bulk_delete_mixed_selection(): void
    {
        // legacy warehouse (no transition state) + native batch warehouse.
        $legacyWh = $this->makeWarehouse('LEG');
        $lp = $this->makeProduct(['unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedStock($legacyWh, $lp, 0);
        $legReq = $this->makeRequest([
            'supplier_id' => 1, 'warehouse_id' => $legacyWh, 'date' => '2026-09-10', 'statut' => 'received',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [[
                'product_id' => $lp, 'product_variant_id' => null, 'purchase_unit_id' => $this->unit1,
                'quantity' => 4, 'Unit_cost' => 1, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0,
                'discount_Method' => '2', 'subtotal' => 4, 'no_unit' => 1,
            ]],
        ]);
        $this->controller()->store($legReq);
        $legacyId = (int) DB::table('purchases')->orderByDesc('id')->value('id');

        $this->lp();
        $bpn = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $bpn, 0);
        $nativeId = $this->storedPurchase('received', $this->line($bpn, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]]));

        $req = $this->makeRequest(['selectedIds' => [$legacyId, $nativeId]]);
        $this->controller()->delete_by_selection($req);

        $this->assertNotNull(DB::table('purchases')->where('id', $legacyId)->value('deleted_at'));
        $this->assertNotNull(DB::table('purchases')->where('id', $nativeId)->value('deleted_at'));
        $this->assertSame(0.0, $this->stockOf($legacyWh, $lp));            // legacy reversed via product_warehouse
        $this->assertSame(0.0, (float) $this->batchByNo('A')->qty);        // native reversed via snapshot
        $this->assertSame(1, $this->locMovements('PurchaseBatchReversal'));
    }

    public function test_bulk_delete_aborts_all_on_one_non_reversible(): void
    {
        $this->lp();
        $a = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $b = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);
        $idA = $this->storedPurchase('received', $this->line($a, $this->unit1, 5, [['batch_no' => 'A', 'qty' => 5]]));
        $idB = $this->storedPurchase('received', $this->line($b, $this->unit1, 10, [['batch_no' => 'B', 'qty' => 10]]));

        // partially consume B's batch -> its reverse cannot complete.
        $bb = $this->batchByNo('B');
        DB::table('product_batch_location_stocks')->where('product_batch_id', $bb->id)->update(['quantity' => 6]);
        DB::table('product_batches')->where('id', $bb->id)->update(['qty' => 6]);
        DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $b)->update(['quantity' => 6]);

        try {
            $this->controller()->delete_by_selection($this->makeRequest(['selectedIds' => [$idA, $idB]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // ok
        }
        // NOTHING deleted, NOTHING reversed.
        $this->assertNull(DB::table('purchases')->where('id', $idA)->value('deleted_at'));
        $this->assertNull(DB::table('purchases')->where('id', $idB)->value('deleted_at'));
        $this->assertSame(5.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(6.0, (float) $this->batchByNo('B')->qty);
    }

    // ===================== LEGACY ISOLATION =====================

    public function test_legacy_warehouse_still_uses_legacy_batch_writer(): void
    {
        // no transition state -> legacy path -> BatchService legacy.
        $p = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $this->unit12, 'cost' => 1]);
        $this->seedStock($this->wh, $p, 0);
        // NOTE: no lp() -> legacy_only (absent).

        $req = $this->makeRequest($this->payload([$this->line($p, $this->unit12, 10, [
            ['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 4],
        ])], 'received', null, null));
        // legacy store ignores inventory_location_id; keep it null.
        $req = $this->makeRequest(array_merge($this->payload([$this->line($p, $this->unit12, 10, [
            ['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 4],
        ])], 'received'), ['inventory_location_id' => null]));
        $this->controller()->store($req);

        // LEGACY contract: product_warehouse gets BASE (120), batches get the
        // ENTERED unit (6 / 4) — the historical divergence, untouched.
        $this->assertSame(120.0, $this->stockOf($this->wh, $p));
        $this->assertSame(10.0, (float) DB::table('product_batches')->sum('qty'));
        $this->assertNull(DB::table('purchases')->value('inventory_location_id'));
        $this->assertSame(0, $this->locMovements());
    }
}
