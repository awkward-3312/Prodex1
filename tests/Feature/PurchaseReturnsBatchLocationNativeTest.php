<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesReturnController;
use App\Models\InventoryTransitionState as Mode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS5-D — batch location-native ACTIVATED in PurchasesReturnController (manual
 * returns). store / update / destroy / delete_by_selection.
 *
 * A PurchaseReturn is LOCATION -> SUPPLIER: apply = issueMany (batch) BEFORE
 * decrease (general); reverse = receiveMany BEFORE increase. The physical
 * effect exists ONLY when statut === 'completed'. Explicit batch selection
 * reserves first, then FEFO fills the rest — document-wide over the LOCKED
 * per-location slices. The snapshot is the ONLY source of a reverse;
 * purchase_return_detail_batches keep the entered PURCHASE-unit qty for
 * UX/reporting.
 */
class PurchaseReturnsBatchLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $unit1;
    private int $unit12;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildBatchSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('CD-RET-BATCH');
        $this->unit1 = $this->makeUnit('*', 1);
        $this->unit12 = $this->makeUnit('*', 12);
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
        Schema::create('purchase_return_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('purchase_return_detail_id');
            $t->integer('product_batch_id');
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->timestamps();
        });
    }

    // ---------------- helpers ----------------

    private function controller(): PurchasesReturnController
    {
        return new PurchasesReturnController;
    }

    private function lp(string $status = 'healthy', ?int $wh = null, ?int $loc = null): void
    {
        $this->setTransitionMode($wh ?? $this->wh, Mode::MODE_LOCATION_PRIMARY, $loc ?? $this->loc, $status);
    }

    private function bp(array $overrides = []): int
    {
        return (int) $this->makeProduct(array_merge([
            'is_batch_tracked' => true,
            'unit_purchase_id' => $this->unit1,
            'cost' => 2,
            'code' => 'RBP'.\Illuminate\Support\Str::random(5),
        ], $overrides));
    }

    /**
     * Seed a usable batch: product_batches row + a per-location slice + the
     * matching general inventory_location_stocks so BatchLocationService's
     * coverage / reconcile checks pass.
     */
    private function seedBatch(int $productId, string $batchNo, float $qty, ?string $expiry = null, float $unitCost = 2.0, ?int $variantId = null, ?int $loc = null): int
    {
        $loc = $loc ?? $this->loc;
        $bid = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'warehouse_id' => $this->wh,
            'batch_no' => $batchNo,
            'expiry_date' => $expiry,
            'mfg_date' => null,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert([
            'product_batch_id' => $bid,
            'inventory_location_id' => $loc,
            'quantity' => $qty,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // keep the general per-location stock in step with the batch slices
        $existing = DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $loc)
            ->where('product_id', $productId)
            ->where('variant_key', (int) ($variantId ?: 0))
            ->first();
        if ($existing) {
            DB::table('inventory_location_stocks')->where('id', $existing->id)
                ->update(['quantity' => (float) $existing->quantity + $qty]);
        } else {
            $this->seedLocationStock($loc, $productId, $qty, $variantId);
        }

        return $bid;
    }

    private function line(int $productId, float $qty, array $batches = [], ?int $unitId = null, ?int $variantId = null): array
    {
        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'purchase_unit_id' => $unitId ?? $this->unit1,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'imei_number' => null,
            'no_unit' => 1,
            'batches' => $batches,
        ];
    }

    private function payload(array $details, string $statut = 'completed', $wh = null, $loc = 'DEFAULT', $purchaseId = null): array
    {
        return [
            'supplier_id' => 1,
            'purchase_id' => $purchaseId,
            'warehouse_id' => $wh ?? $this->wh,
            'inventory_location_id' => $loc === 'DEFAULT' ? $this->loc : $loc,
            'date' => '2026-09-10',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 50,
            'details' => $details,
        ];
    }

    private function req(array $payload, string $method = 'POST')
    {
        return $this->makeRequest($payload, $method);
    }

    private function batchByNo(string $no)
    {
        return DB::table('product_batches')->where('batch_no', $no)->first();
    }

    private function sliceOf(int $batchId): float
    {
        return (float) DB::table('product_batch_location_stocks')->where('product_batch_id', $batchId)->value('quantity');
    }

    private function batchMovements(?string $ref = null): int
    {
        $q = DB::table('product_batch_location_movements');
        if ($ref) {
            $q->where('reference_type', $ref);
        }

        return (int) $q->count();
    }

    private function pwCount(): int
    {
        return (int) DB::table('product_warehouse')->count();
    }

    private function snapshot(int $rid): array
    {
        return json_decode(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'), true);
    }

    private function returnId(): int
    {
        return (int) DB::table('purchase_returns')->latest('id')->value('id');
    }

    // =====================================================================
    // STORE — completed, explicit selection
    // =====================================================================

    public function test_store_completed_explicit_single_batch_issues_and_decreases(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'LOT-A', 20, '2027-01-31');

        $this->controller()->store($this->req($this->payload([
            $this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]]),
        ])));

        $this->assertSame(15.0, (float) $this->batchByNo('LOT-A')->qty);
        $this->assertSame(15.0, $this->sliceOf($b));
        $this->assertSame(15.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->batchMovements('PurchaseReturnBatch'));
        $this->assertSame(1, $this->movementCount('PurchaseReturn'));

        $piv = DB::table('purchase_return_detail_batches')->first();
        $this->assertSame($b, (int) $piv->product_batch_id);
        $this->assertSame(5.0, (float) $piv->qty);

        $snap = $this->snapshot($this->returnId());
        $this->assertSame(5.0, (float) $snap['effects'][0]['quantity_base']); // magnitude; return sign applied at runtime
        $this->assertSame(5.0, (float) $snap['effects'][0]['batch_allocation'][0]['quantity_base']);
        $this->assertSame(0, $this->pwCount());
    }

    public function test_store_completed_explicit_two_batches_one_line(): void
    {
        $this->lp();
        $p = $this->bp();
        $a = $this->seedBatch($p, 'A', 10, '2027-01-31');
        $b = $this->seedBatch($p, 'B', 10, '2027-06-30');

        $this->controller()->store($this->req($this->payload([
            $this->line($p, 9, [
                ['product_batch_id' => $a, 'qty' => 6],
                ['product_batch_id' => $b, 'qty' => 3],
            ]),
        ])));

        $this->assertSame(4.0, $this->sliceOf($a));
        $this->assertSame(7.0, $this->sliceOf($b));
        $this->assertSame(11.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, $this->batchMovements('PurchaseReturnBatch'));
        $this->assertSame([6.0, 3.0], DB::table('purchase_return_detail_batches')->orderBy('id')->pluck('qty')->map(fn ($q) => (float) $q)->all());
    }

    public function test_store_explicit_duplicate_batch_in_one_line_is_422(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 10);

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->req($this->payload([
            $this->line($p, 4, [
                ['product_batch_id' => $b, 'qty' => 2],
                ['product_batch_id' => $b, 'qty' => 2],
            ]),
        ])));
    }

    public function test_store_explicit_insufficient_batch_stock_is_422_full_rollback(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 3);

        try {
            $this->controller()->store($this->req($this->payload([
                $this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]]),
            ])));
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, DB::table('purchase_return_details')->count());
        $this->assertSame(0, DB::table('purchase_return_detail_batches')->count());
        $this->assertSame(3.0, $this->sliceOf($b));           // not clamped
        $this->assertSame(0, $this->batchMovements());
        $this->assertSame(0, $this->movementCount());
    }

    // =====================================================================
    // STORE — completed, auto FEFO
    // =====================================================================

    public function test_store_completed_auto_fefo_consumes_oldest_expiry_first(): void
    {
        $this->lp();
        $p = $this->bp();
        $near = $this->seedBatch($p, 'NEAR', 10, '2027-01-31');
        $far = $this->seedBatch($p, 'FAR', 10, '2027-12-31');

        $this->controller()->store($this->req($this->payload([
            $this->line($p, 12),   // no explicit batches -> FEFO
        ])));

        $this->assertSame(0.0, $this->sliceOf($near));   // fully drained first
        $this->assertSame(8.0, $this->sliceOf($far));    // remainder
        $this->assertSame(8.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, $this->batchMovements('PurchaseReturnBatch'));

        $rows = DB::table('purchase_return_detail_batches')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($near, (int) $rows[0]->product_batch_id);
    }

    public function test_store_fefo_null_expiry_batch_is_used_last(): void
    {
        $this->lp();
        $p = $this->bp();
        $dated = $this->seedBatch($p, 'DATED', 5, '2027-05-31');
        $noexp = $this->seedBatch($p, 'NOEXP', 5, null);

        $this->controller()->store($this->req($this->payload([
            $this->line($p, 7),
        ])));

        $this->assertSame(0.0, $this->sliceOf($dated));
        $this->assertSame(3.0, $this->sliceOf($noexp));
    }

    public function test_store_fefo_globally_insufficient_is_422(): void
    {
        $this->lp();
        $p = $this->bp();
        $this->seedBatch($p, 'A', 4, '2027-01-31');
        $this->seedBatch($p, 'B', 4, '2027-02-28');

        try {
            $this->controller()->store($this->req($this->payload([
                $this->line($p, 10),
            ])));
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(0, $this->batchMovements());
    }

    // =====================================================================
    // STORE — 10 boxes x 12 native contract (FEFO auto)
    // =====================================================================

    public function test_store_10_boxes_of_12_return_contract(): void
    {
        $this->lp();
        $p = $this->bp(['unit_purchase_id' => $this->unit12]);
        $a = $this->seedBatch($p, 'LOT-A', 72, '2027-01-31');
        $b = $this->seedBatch($p, 'LOT-B', 48, '2027-03-31');

        $this->controller()->store($this->req($this->payload([
            $this->line($p, 10, [], $this->unit12),   // 10 boxes -> 120 base
        ])));

        // PurchaseReturnDetails.quantity = 10 (entered box count).
        $this->assertSame(10.0, (float) DB::table('purchase_return_details')->value('quantity'));
        // pivot.qty = 6 / 4 (COMMERCIAL, derived from the FEFO base split).
        $this->assertSame([6.0, 4.0], DB::table('purchase_return_detail_batches')->orderBy('id')->pluck('qty')->map(fn ($q) => (float) $q)->all());
        // snapshot + physical = BASE (120 ; 72 / 48).
        $snap = $this->snapshot($this->returnId());
        $this->assertSame(120.0, (float) $snap['effects'][0]['quantity_base']); // magnitude; return sign applied at runtime
        $this->assertSame([72.0, 48.0], array_map('floatval', array_column($snap['effects'][0]['batch_allocation'], 'quantity_base')));
        $this->assertSame(0.0, $this->sliceOf($a));
        $this->assertSame(0.0, $this->sliceOf($b));
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, $this->batchMovements('PurchaseReturnBatch'));
        $this->assertSame(1, $this->movementCount('PurchaseReturn'));
        $this->assertSame(0, $this->pwCount());
    }

    public function test_store_unit_divide_return(): void
    {
        $this->lp();
        $u = $this->makeUnit('/', 4);
        $p = $this->bp(['unit_purchase_id' => $u]);
        $b = $this->seedBatch($p, 'A', 10, '2027-01-31');

        // 8 in the '/'-4 unit -> 2 base.
        $this->controller()->store($this->req($this->payload([
            $this->line($p, 8, [['product_batch_id' => $b, 'qty' => 8]], $u),
        ])));

        $this->assertSame(8.0, $this->sliceOf($b));
        $this->assertSame(8.0, $this->locStock($this->loc, $p));
        $this->assertSame(8.0, (float) DB::table('purchase_return_detail_batches')->value('qty'));
        $snap = $this->snapshot($this->returnId());
        $this->assertSame(2.0, (float) $snap['effects'][0]['quantity_base']); // magnitude; return sign applied at runtime
    }

    // =====================================================================
    // STORE — document-wide allocation across lines
    // =====================================================================

    public function test_store_docwide_two_fefo_lines_share_the_batch_pool(): void
    {
        $this->lp();
        $p = $this->bp();
        $a = $this->seedBatch($p, 'A', 10, '2027-01-31');
        $b = $this->seedBatch($p, 'B', 10, '2027-02-28');

        // two lines of 8 each -> 16 base ; A(10) then B(6). No false 422.
        $this->controller()->store($this->req($this->payload([
            $this->line($p, 8),
            $this->line($p, 8),
        ])));

        $this->assertSame(0.0, $this->sliceOf($a));
        $this->assertSame(4.0, $this->sliceOf($b));
        $this->assertSame(4.0, $this->locStock($this->loc, $p));
    }

    public function test_store_docwide_explicit_reserves_before_fefo_line(): void
    {
        $this->lp();
        $p = $this->bp();
        $a = $this->seedBatch($p, 'A', 10, '2027-01-31');   // FEFO would hit this first
        $b = $this->seedBatch($p, 'B', 10, '2027-06-30');

        // FEFO line FIRST in document order, explicit line SECOND, but explicit
        // reserves batch A before FEFO runs.
        $this->controller()->store($this->req($this->payload([
            $this->line($p, 6),                                       // FEFO
            $this->line($p, 8, [['product_batch_id' => $a, 'qty' => 8]]), // explicit A
        ])));

        // explicit took 8 from A ; FEFO then had A=2 (oldest) + B=4.
        $this->assertSame(0.0, $this->sliceOf($a));
        $this->assertSame(6.0, $this->sliceOf($b));
        $this->assertSame(6.0, $this->locStock($this->loc, $p));
    }

    public function test_store_docwide_two_explicit_lines_overallocate_is_422(): void
    {
        $this->lp();
        $p = $this->bp();
        $a = $this->seedBatch($p, 'A', 10);

        try {
            $this->controller()->store($this->req($this->payload([
                $this->line($p, 6, [['product_batch_id' => $a, 'qty' => 6]]),
                $this->line($p, 6, [['product_batch_id' => $a, 'qty' => 6]]),
            ])));
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            // expected — 12 > 10 available in the document
        }
        $this->assertSame(0, DB::table('purchase_returns')->count());
        $this->assertSame(10.0, $this->sliceOf($a));
    }

    // =====================================================================
    // STORE — pending / IMEI
    // =====================================================================

    public function test_store_pending_creates_no_batch_artifact_even_with_batches(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 10);

        $this->controller()->store($this->req($this->payload([
            $this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]]),
        ], 'pending')));

        $this->assertSame(1, DB::table('purchase_returns')->count());
        $this->assertNull(DB::table('purchase_returns')->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('purchase_return_detail_batches')->count());
        $this->assertSame(0, $this->batchMovements());
        $this->assertSame(10.0, $this->sliceOf($b));         // untouched
        $this->assertSame(10.0, $this->locStock($this->loc, $p));
    }

    public function test_store_imei_without_serial_numbers_is_422(): void
    {
        // MS6-B2 — IMEI is now ACTIVE for native returns (see
        // PurchaseReturnSerialLocationNativeArchitectureTest), but it is still
        // explicit: a requires_serial line with NO serial_numbers still 422s
        // (count(serials)=0 != quantity_base=1), the same outward behaviour
        // this test pinned before B2 (then via the allow_serial=false fence).
        $this->lp();
        $p = $this->makeProduct(['is_imei' => 1, 'unit_purchase_id' => $this->unit1]);
        $this->seedLocationStock($this->loc, $p, 10);

        $this->expectException(ValidationException::class);
        $this->controller()->store($this->req($this->payload([
            $this->line($p, 1),
        ])));
    }

    // =====================================================================
    // UPDATE — state machine A/B/C/D
    // =====================================================================

    public function test_update_A_pending_to_pending_no_artifact(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 10);
        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])], 'pending')));
        $rid = $this->returnId();

        $this->controller()->update($this->req($this->payload([
            $this->line($p, 7, [['product_batch_id' => $b, 'qty' => 7]]),
        ], 'pending'), 'PUT'), $rid);

        $this->assertNull(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'));
        $this->assertSame(0, $this->batchMovements());
        $this->assertSame(10.0, $this->sliceOf($b));
    }

    public function test_update_B_pending_to_completed_plans_current_request(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 10, '2027-01-31');
        $this->controller()->store($this->req($this->payload([$this->line($p, 5)], 'pending')));
        $rid = $this->returnId();

        $this->controller()->update($this->req($this->payload([
            $this->line($p, 6, [['product_batch_id' => $b, 'qty' => 6]]),
        ], 'completed'), 'PUT'), $rid);

        $this->assertSame(4.0, $this->sliceOf($b));
        $this->assertSame(4.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->batchMovements('PurchaseReturnBatch'));
        $snap = $this->snapshot($rid);
        $this->assertSame(1, (int) $snap['revision']);
        $this->assertSame(6.0, (float) DB::table('purchase_return_detail_batches')->value('qty'));
    }

    public function test_update_C_completed_to_completed_reverses_old_then_applies_new(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 20, '2027-01-31');
        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])])));
        $rid = $this->returnId();
        $this->assertSame(15.0, $this->sliceOf($b));

        $this->controller()->update($this->req($this->payload([
            $this->line($p, 8, [['product_batch_id' => $b, 'qty' => 8]]),
        ], 'completed'), 'PUT'), $rid);

        // old +5 back, new -8 => 20 - 8 = 12.
        $this->assertSame(12.0, $this->sliceOf($b));
        $this->assertSame(12.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->batchMovements('PurchaseReturnBatchReversal'));
        $this->assertSame(2, $this->batchMovements('PurchaseReturnBatch'));
        $snap = $this->snapshot($rid);
        $this->assertSame(2, (int) $snap['revision']);
        $this->assertSame(8.0, (float) DB::table('purchase_return_detail_batches')->value('qty'));
    }

    public function test_update_D_completed_to_pending_reverses_and_keeps_snapshot(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 20, '2027-01-31');
        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])])));
        $rid = $this->returnId();

        $this->controller()->update($this->req($this->payload([
            $this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]]),
        ], 'pending'), 'PUT'), $rid);

        $this->assertSame(20.0, $this->sliceOf($b));              // reversed
        $this->assertSame(20.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->batchMovements('PurchaseReturnBatchReversal'));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('purchase_return_detail_batches')->count()); // pivots cleared
    }

    public function test_update_second_pending_to_completed_uses_prev_revision_plus_one(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 20, '2027-01-31');
        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])])));
        $rid = $this->returnId();
        $this->controller()->update($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])], 'pending'), 'PUT'), $rid);

        $this->controller()->update($this->req($this->payload([
            $this->line($p, 3, [['product_batch_id' => $b, 'qty' => 3]]),
        ], 'completed'), 'PUT'), $rid);

        $snap = $this->snapshot($rid);
        $this->assertSame(2, (int) $snap['revision']);
        $this->assertSame(17.0, $this->sliceOf($b));
    }

    public function test_update_change_location_two_external_events(): void
    {
        $this->lp();
        $loc2 = $this->makeInventoryLocation($this->wh);
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        $p = $this->bp();
        $bA = $this->seedBatch($p, 'LOT-1', 20, '2027-01-31', 2.0, null, $this->loc);
        $bB = $this->seedBatch($p, 'LOT-1', 20, '2027-01-31', 2.0, null, $loc2);

        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $bA, 'qty' => 5]])], 'completed', null, $this->loc)));
        $rid = $this->returnId();
        $this->assertSame(15.0, $this->sliceOf($bA));

        $this->controller()->update($this->req($this->payload([
            $this->line($p, 4, [['product_batch_id' => $bB, 'qty' => 4]]),
        ], 'completed', null, $loc2), 'PUT'), $rid);

        // Loc A restored to 20, Loc B down to 16 — LOT-1 never moved A->B.
        $this->assertSame(20.0, $this->sliceOf($bA));
        $this->assertSame(16.0, $this->sliceOf($bB));
        $this->assertSame(20.0, $this->locStock($this->loc, $p));
        $this->assertSame(16.0, $this->locStock($loc2, $p));
    }

    public function test_update_reverse_when_old_batch_slice_drifted_is_422_total_rollback(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 20, '2027-01-31');
        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])])));
        $rid = $this->returnId();

        // drift the slice below what a clean reverse expects (coverage mismatch)
        DB::table('product_batch_location_stocks')->where('product_batch_id', $b)->update(['quantity' => 99]);

        try {
            $this->controller()->update($this->req($this->payload([
                $this->line($p, 8, [['product_batch_id' => $b, 'qty' => 8]]),
            ], 'completed'), 'PUT'), $rid);
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            // expected
        }

        // snapshot revision + pivots intact (rolled back)
        $snap = $this->snapshot($rid);
        $this->assertSame(1, (int) $snap['revision']);
        $this->assertSame(5.0, (float) DB::table('purchase_return_detail_batches')->value('qty'));
    }

    public function test_update_now_tracked_product_without_batch_allocation_is_422(): void
    {
        $this->lp();
        // start as a plain product, completed return
        $p = $this->makeProduct(['unit_purchase_id' => $this->unit1]);
        $this->seedLocationStock($this->loc, $p, 50);
        $this->controller()->store($this->req($this->payload([$this->line($p, 5)])));
        $rid = $this->returnId();

        // flip to batch-tracked, seed a batch, then update WITHOUT explicit
        // batches is fine (FEFO) — but with no batch stock at all it must 422.
        DB::table('products')->where('id', $p)->update(['is_batch_tracked' => true]);

        $caught = false;
        try {
            $this->controller()->update($this->req($this->payload([
                $this->line($p, 5),
            ], 'completed'), 'PUT'), $rid);
        } catch (ValidationException $e) {
            $caught = true; // expected — no batch stock to FEFO against
        }

        $this->assertTrue($caught, 'a now-batch product with no batch stock must fail closed');
        // the original plain effect stays untouched (revision not bumped).
        $this->assertSame(1, (int) $this->snapshot($rid)['revision']);
        $this->assertSame(0, DB::table('purchase_return_detail_batches')->count());
    }

    // =====================================================================
    // DESTROY
    // =====================================================================

    public function test_destroy_completed_reverses_exact_snapshot(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 20, '2027-01-31');
        $this->controller()->store($this->req($this->payload([$this->line($p, 6, [['product_batch_id' => $b, 'qty' => 6]])])));
        $rid = $this->returnId();
        $this->assertSame(14.0, $this->sliceOf($b));

        $this->controller()->destroy($this->req([], 'DELETE'), $rid);

        $this->assertSame(20.0, $this->sliceOf($b));                 // fully restored
        $this->assertSame(20.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->batchMovements('PurchaseReturnBatchReversal'));
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
        $this->assertSame(0, DB::table('purchase_return_detail_batches')->count());
        // ProductBatch row is NEVER deleted.
        $this->assertNotNull($this->batchByNo('A'));
    }

    public function test_destroy_pending_no_stock_reverse(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 20);
        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])], 'pending')));
        $rid = $this->returnId();

        $this->controller()->destroy($this->req([], 'DELETE'), $rid);

        $this->assertSame(20.0, $this->sliceOf($b));
        $this->assertSame(0, $this->batchMovements());
        $this->assertNotNull(DB::table('purchase_returns')->where('id', $rid)->value('deleted_at'));
    }

    public function test_destroy_completed_null_snapshot_fails_closed(): void
    {
        $this->lp();
        $p = $this->bp();
        $b = $this->seedBatch($p, 'A', 20, '2027-01-31');
        $this->controller()->store($this->req($this->payload([$this->line($p, 5, [['product_batch_id' => $b, 'qty' => 5]])])));
        $rid = $this->returnId();
        DB::table('purchase_returns')->where('id', $rid)->update(['inventory_effect_snapshot' => null]);

        $this->expectException(ValidationException::class);
        $this->controller()->destroy($this->req([], 'DELETE'), $rid);
    }

    // =====================================================================
    // BULK delete_by_selection
    // =====================================================================

    public function test_bulk_delete_mixed_native_batch_and_plain(): void
    {
        $this->lp();
        $pb = $this->bp();
        $b = $this->seedBatch($pb, 'A', 20, '2027-01-31');
        $pp = $this->makeProduct(['unit_purchase_id' => $this->unit1]);
        $this->seedLocationStock($this->loc, $pp, 40);

        $this->controller()->store($this->req($this->payload([$this->line($pb, 5, [['product_batch_id' => $b, 'qty' => 5]])])));
        $rid1 = $this->returnId();
        $this->controller()->store($this->req($this->payload([$this->line($pp, 4)])));
        $rid2 = $this->returnId();

        $this->controller()->delete_by_selection($this->req(['selectedIds' => [$rid1, $rid2]], 'POST'));

        $this->assertSame(20.0, $this->sliceOf($b));                    // batch reversed
        $this->assertSame(40.0, $this->locStock($this->loc, $pp));      // plain reversed
        $this->assertSame(2, DB::table('purchase_returns')->whereNotNull('deleted_at')->count());
        $this->assertSame(0, DB::table('purchase_return_detail_batches')->count());
    }

    public function test_bulk_delete_aborts_all_when_one_batch_is_not_reversible(): void
    {
        $this->lp();
        $pb = $this->bp();
        $b = $this->seedBatch($pb, 'A', 20, '2027-01-31');
        $pp = $this->makeProduct(['unit_purchase_id' => $this->unit1]);
        $this->seedLocationStock($this->loc, $pp, 40);

        $this->controller()->store($this->req($this->payload([$this->line($pb, 5, [['product_batch_id' => $b, 'qty' => 5]])])));
        $rid1 = $this->returnId();
        $this->controller()->store($this->req($this->payload([$this->line($pp, 4)])));
        $rid2 = $this->returnId();

        // corrupt the batch snapshot document so its reverse throws
        DB::table('product_batch_location_stocks')->where('product_batch_id', $b)->update(['quantity' => 999]);

        try {
            $this->controller()->delete_by_selection($this->req(['selectedIds' => [$rid1, $rid2]], 'POST'));
            $this->fail('expected 422');
        } catch (ValidationException $e) {
            // expected
        }

        // zero partial deletes — rid1's single batch pivot survives the rollback
        // (rid2 is a plain product with no pivot).
        $this->assertSame(0, DB::table('purchase_returns')->whereNotNull('deleted_at')->count());
        $this->assertSame(1, DB::table('purchase_return_detail_batches')->count());
    }

    // =====================================================================
    // LEGACY untouched
    // =====================================================================

    public function test_legacy_warehouse_return_never_runs_the_batch_planner(): void
    {
        // no transition state -> legacy path. A batch product returns via the
        // legacy BatchService, NOT the location-native planner.
        $p = $this->bp();
        DB::table('product_warehouse')->insert([
            'deleted_at' => null,
            'warehouse_id' => $this->wh,
            'product_id' => $p,
            'qte' => 30,
        ]);

        // legacy store path is exercised elsewhere; here we only assert the
        // native artifacts are NOT produced for a legacy warehouse.
        try {
            $this->controller()->store($this->req($this->payload([$this->line($p, 5)], 'completed')));
        } catch (\Throwable $e) {
            // legacy path may reject batch products differently; irrelevant here
        }
        $this->assertSame(0, $this->batchMovements('PurchaseReturnBatch'));
        $this->assertNull(DB::table('purchase_returns')->value('inventory_effect_snapshot'));
    }
}
