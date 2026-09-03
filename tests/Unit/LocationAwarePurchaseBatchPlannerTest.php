<?php

namespace Tests\Unit;

use App\Services\LocationAwarePurchaseBatchPlanner as Planner;
use App\Services\LocationAwarePurchaseStockService as Svc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * MS5-B2 — LocationAwarePurchaseBatchPlanner. INACTIVE: no controller calls it.
 */
class LocationAwarePurchaseBatchPlannerTest extends TestCase
{
    private int $wh = 7;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();

        DB::table('warehouses')->insert(['id' => $this->wh, 'name' => 'CD', 'created_at' => now(), 'updated_at' => now()]);
        $this->loc = (int) DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $this->wh, 'code' => 'L1', 'name' => 'L1', 'type' => 'storage',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('warehouses', fn ($t) => [
            $t->increments('id'), $t->string('name'), $t->timestamps(), $t->softDeletes(),
        ]);
        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->string('type')->default('is_single');
            $t->boolean('is_batch_tracked')->default(false);
            $t->integer('is_imei')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_variants', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('units', function ($t) {
            $t->increments('id');
            $t->string('operator')->nullable();
            $t->decimal('operator_value', 12, 3)->default(1);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('code');
            $t->string('name');
            $t->string('type')->default('storage');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
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
    }

    private function planner(): Planner
    {
        return app(Planner::class);
    }

    private function svc(): Svc
    {
        return app(Svc::class);
    }

    private function unit(string $op = '*', float $v = 1): int
    {
        return (int) DB::table('units')->insertGetId(['operator' => $op, 'operator_value' => $v, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(array $o = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'P', 'type' => 'is_single', 'is_batch_tracked' => 1, 'is_imei' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function line(int $productId, int $unitId, float $qty, ?int $variantId = null, ?int $sdid = null): array
    {
        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'purchase_unit_id' => $unitId,
            'quantity' => $qty,
            'source_detail_id' => $sdid,
        ];
    }

    /** validated lines (allow_batch) + raw lines -> receipt plan, inside a tx. */
    private function planReceipt(array $lines, array $raw, array $ctx = []): array
    {
        return DB::transaction(function () use ($lines, $raw, $ctx) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $this->wh, $this->loc, $lines, [], ['allow_batch' => true]);

            return $this->planner()->planPurchaseReceipt($this->wh, $this->loc, $v['lines'], $raw, $ctx);
        });
    }

    private function planReturn(array $lines, array $raw, array $ctx = []): array
    {
        return DB::transaction(function () use ($lines, $raw, $ctx) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE_RETURN, $this->wh, $this->loc, $lines, [], ['allow_batch' => true]);

            return $this->planner()->planPurchaseReturnIssue($this->wh, $this->loc, $v['lines'], $raw, $ctx);
        });
    }

    private function seedBatch(int $productId, string $batchNo, array $o = []): int
    {
        return (int) DB::table('product_batches')->insertGetId(array_merge([
            'product_id' => $productId, 'product_variant_id' => null, 'warehouse_id' => $this->wh,
            'batch_no' => $batchNo, 'qty' => 0, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function seedSlice(int $batchId, float $qty): void
    {
        DB::table('product_batch_location_stocks')->insert([
            'product_batch_id' => $batchId, 'inventory_location_id' => $this->loc,
            'quantity' => $qty, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ================= RECEIPT =================

    public function test_receipt_single_batch_operator_one(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $plan = $this->planReceipt(
            [$this->line($p, $u, 5, null, 55)],
            [['batches' => [['batch_no' => 'LOT-A', 'qty' => 5, 'unit_cost' => 2]]]]
        );

        $alloc = $plan[0]['batch_allocation'];
        $this->assertCount(1, $alloc);
        $this->assertSame(0, $alloc[0]['bidx']);
        $this->assertSame('LOT-A', $alloc[0]['batch_no']);
        $this->assertSame(5.0, $alloc[0]['quantity_base']);
        $this->assertSame(5.0, $alloc[0]['quantity_input']);
        $this->assertGreaterThan(0, $alloc[0]['product_batch_id']);
        $this->assertSame(0.0, (float) DB::table('product_batches')->where('id', $alloc[0]['product_batch_id'])->value('qty'));
    }

    public function test_receipt_10_boxes_of_12_converts_to_base(): void
    {
        $u = $this->unit('*', 12);
        $p = $this->product();
        $plan = $this->planReceipt(
            [$this->line($p, $u, 10, null, 55)],
            [['batches' => [
                ['batch_no' => 'LOT-A', 'qty' => 6, 'expiry_date' => '2027-01-31'],
                ['batch_no' => 'LOT-B', 'qty' => 4, 'expiry_date' => '2027-03-31'],
            ]]]
        );

        $alloc = $plan[0]['batch_allocation'];
        $this->assertSame([72.0, 48.0], array_column($alloc, 'quantity_base'));
        $this->assertSame([6.0, 4.0], array_column($alloc, 'quantity_input'));
        $this->assertSame(120.0, round(array_sum(array_column($alloc, 'quantity_base')), 3));
        $this->assertSame([0, 1], array_column($alloc, 'bidx'));
    }

    public function test_receipt_operator_divide(): void
    {
        $u = $this->unit('/', 4);
        $p = $this->product();
        $plan = $this->planReceipt(
            [$this->line($p, $u, 8, null, 55)],
            [['batches' => [['batch_no' => 'LOT-A', 'qty' => 8]]]]
        );
        $this->assertSame(2.0, $plan[0]['batch_allocation'][0]['quantity_base']);
    }

    public function test_receipt_duplicate_batch_no_same_line_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->planReceipt(
            [$this->line($p, $u, 4, null, 55)],
            [['batches' => [['batch_no' => 'X', 'qty' => 2], ['batch_no' => 'x', 'qty' => 2]]]]
        );
    }

    public function test_receipt_same_batch_no_two_details_resolves_same_product_batch(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $plan = $this->planReceipt(
            [$this->line($p, $u, 3, null, 10), $this->line($p, $u, 4, null, 11)],
            [
                ['batches' => [['batch_no' => 'SHARED', 'qty' => 3]]],
                ['batches' => [['batch_no' => 'SHARED', 'qty' => 4]]],
            ]
        );
        $id0 = $plan[0]['batch_allocation'][0]['product_batch_id'];
        $id1 = $plan[1]['batch_allocation'][0]['product_batch_id'];
        $this->assertSame($id0, $id1);
        $this->assertSame(3.0, $plan[0]['batch_allocation'][0]['quantity_base']);
        $this->assertSame(4.0, $plan[1]['batch_allocation'][0]['quantity_base']);
        $this->assertSame(1, DB::table('product_batches')->where('batch_no', 'SHARED')->count());
    }

    public function test_receipt_sum_mismatch_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->planReceipt(
            [$this->line($p, $u, 10, null, 55)],
            [['batches' => [['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 3]]]]
        );
    }

    public function test_receipt_existing_active_batch_reused(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $existing = $this->seedBatch($p, 'LOT-A', ['expiry_date' => '2027-05-01']);
        $plan = $this->planReceipt(
            [$this->line($p, $u, 4, null, 55)],
            [['batches' => [['batch_no' => 'LOT-A', 'qty' => 4, 'expiry_date' => '2027-05-01']]]]
        );
        $this->assertSame($existing, $plan[0]['batch_allocation'][0]['product_batch_id']);
    }

    public function test_receipt_soft_deleted_identity_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $this->seedBatch($p, 'LOT-A', ['deleted_at' => now()]);
        try {
            $this->planReceipt([$this->line($p, $u, 4, null, 55)], [['batches' => [['batch_no' => 'LOT-A', 'qty' => 4]]]]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }
    }

    public function test_receipt_conflicting_expiry_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $this->seedBatch($p, 'LOT-A', ['expiry_date' => '2027-01-01']);
        $this->expectException(ValidationException::class);
        $this->planReceipt([$this->line($p, $u, 4, null, 55)], [['batches' => [['batch_no' => 'LOT-A', 'qty' => 4, 'expiry_date' => '2028-01-01']]]]);
    }

    public function test_receipt_completes_null_expiry_from_incoming(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $existing = $this->seedBatch($p, 'LOT-A');   // expiry NULL
        $this->planReceipt([$this->line($p, $u, 4, null, 55)], [['batches' => [['batch_no' => 'LOT-A', 'qty' => 4, 'expiry_date' => '2027-09-09']]]]);
        $this->assertStringStartsWith('2027-09-09', (string) DB::table('product_batches')->where('id', $existing)->value('expiry_date'));
    }

    public function test_receipt_invalid_expiry_string_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $this->expectException(ValidationException::class);
        $this->planReceipt([$this->line($p, $u, 4, null, 55)], [['batches' => [['batch_no' => 'A', 'qty' => 4, 'expiry_date' => 'not-a-date']]]]);
    }

    public function test_receipt_variant_batch_identity(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product(['type' => 'is_variant']);
        $v = (int) DB::table('product_variants')->insertGetId(['product_id' => $p, 'name' => 'V', 'created_at' => now(), 'updated_at' => now()]);
        $plan = $this->planReceipt(
            [$this->line($p, $u, 3, $v, 55)],
            [['batches' => [['batch_no' => 'LOT-V', 'qty' => 3]]]]
        );
        $bid = $plan[0]['batch_allocation'][0]['product_batch_id'];
        $this->assertSame($v, (int) DB::table('product_batches')->where('id', $bid)->value('product_variant_id'));
    }

    public function test_non_batch_line_gets_empty_allocation(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product(['is_batch_tracked' => 0]);
        $plan = $this->planReceipt([$this->line($p, $u, 3, null, 55)], [[]]);
        $this->assertSame([], $plan[0]['batch_allocation']);
        $this->assertFalse($plan[0]['requires_batch']);
    }

    // ================= RETURN =================

    public function test_return_explicit_single_batch(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $b = $this->seedBatch($p, 'LOT-A', ['qty' => 10]);
        $this->seedSlice($b, 10);
        $plan = $this->planReturn(
            [$this->line($p, $u, 4, null, 80)],
            [['batches' => [['product_batch_id' => $b, 'qty' => 4]]]]
        );
        $this->assertSame($b, $plan[0]['batch_allocation'][0]['product_batch_id']);
        $this->assertSame(4.0, $plan[0]['batch_allocation'][0]['quantity_base']);
    }

    public function test_return_explicit_multiple(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $a = $this->seedBatch($p, 'A', ['qty' => 10]);
        $b = $this->seedBatch($p, 'B', ['qty' => 10]);
        $this->seedSlice($a, 10);
        $this->seedSlice($b, 10);
        $plan = $this->planReturn(
            [$this->line($p, $u, 7, null, 80)],
            [['batches' => [['product_batch_id' => $a, 'qty' => 5], ['product_batch_id' => $b, 'qty' => 2]]]]
        );
        $this->assertSame([$a, $b], array_column($plan[0]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([5.0, 2.0], array_column($plan[0]['batch_allocation'], 'quantity_base'));
    }

    public function test_return_explicit_qty_conversion_to_base(): void
    {
        $u = $this->unit('*', 6);
        $p = $this->product();
        $b = $this->seedBatch($p, 'A', ['qty' => 100]);
        $this->seedSlice($b, 100);
        $plan = $this->planReturn([$this->line($p, $u, 2, null, 80)], [['batches' => [['product_batch_id' => $b, 'qty' => 2]]]]);
        $this->assertSame(12.0, $plan[0]['batch_allocation'][0]['quantity_base']);
    }

    public function test_return_explicit_batch_of_other_product_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $other = $this->product();
        $b = $this->seedBatch($other, 'A', ['qty' => 10]);
        $this->seedSlice($b, 10);
        $this->expectException(ValidationException::class);
        $this->planReturn([$this->line($p, $u, 3, null, 80)], [['batches' => [['product_batch_id' => $b, 'qty' => 3]]]]);
    }

    public function test_return_explicit_batch_of_other_warehouse_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $b = $this->seedBatch($p, 'A', ['qty' => 10, 'warehouse_id' => 999]);
        $this->seedSlice($b, 10);
        $this->expectException(ValidationException::class);
        $this->planReturn([$this->line($p, $u, 3, null, 80)], [['batches' => [['product_batch_id' => $b, 'qty' => 3]]]]);
    }

    public function test_return_explicit_batch_not_in_location_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $b = $this->seedBatch($p, 'A', ['qty' => 10]);   // no slice in $this->loc
        $this->expectException(ValidationException::class);
        $this->planReturn([$this->line($p, $u, 3, null, 80)], [['batches' => [['product_batch_id' => $b, 'qty' => 3]]]]);
    }

    public function test_return_fefo_single(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $b = $this->seedBatch($p, 'A', ['qty' => 10, 'expiry_date' => '2027-03-01']);
        $this->seedSlice($b, 10);
        $plan = $this->planReturn([$this->line($p, $u, 6, null, 80)], [[]]);   // no explicit
        $this->assertSame($b, $plan[0]['batch_allocation'][0]['product_batch_id']);
        $this->assertSame(6.0, $plan[0]['batch_allocation'][0]['quantity_base']);
        $this->assertSame(0, $plan[0]['batch_allocation'][0]['bidx']);
    }

    public function test_return_fefo_spans_multiple_in_expiry_order(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $late = $this->seedBatch($p, 'LATE', ['qty' => 5, 'expiry_date' => '2027-09-01']);
        $early = $this->seedBatch($p, 'EARLY', ['qty' => 5, 'expiry_date' => '2027-03-01']);
        $this->seedSlice($late, 5);
        $this->seedSlice($early, 5);
        $plan = $this->planReturn([$this->line($p, $u, 7, null, 80)], [[]]);
        $this->assertSame([$early, $late], array_column($plan[0]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([5.0, 2.0], array_column($plan[0]['batch_allocation'], 'quantity_base'));
    }

    public function test_return_fefo_null_expiry_last(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $noExp = $this->seedBatch($p, 'NOEXP', ['qty' => 5]);          // expiry NULL
        $dated = $this->seedBatch($p, 'DATED', ['qty' => 5, 'expiry_date' => '2027-12-31']);
        $this->seedSlice($noExp, 5);
        $this->seedSlice($dated, 5);
        $plan = $this->planReturn([$this->line($p, $u, 6, null, 80)], [[]]);
        $this->assertSame([$dated, $noExp], array_column($plan[0]['batch_allocation'], 'product_batch_id'));
    }

    public function test_return_fefo_insufficient_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $b = $this->seedBatch($p, 'A', ['qty' => 3, 'expiry_date' => '2027-01-01']);
        $this->seedSlice($b, 3);
        $this->expectException(ValidationException::class);
        $this->planReturn([$this->line($p, $u, 5, null, 80)], [[]]);
    }

    public function test_planner_requires_outer_transaction(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $v = DB::transaction(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $this->wh, $this->loc, [$this->line($p, $u, 3, null, 55)], [], ['allow_batch' => true]));

        $this->expectException(\LogicException::class);
        $this->planner()->planPurchaseReceipt($this->wh, $this->loc, $v['lines'], [['batches' => [['batch_no' => 'A', 'qty' => 3]]]]);
    }

    // ================= MS5-B2.1 — DOCUMENT-WIDE RETURN ALLOCATION =========

    private function twoBatches(int $p): array
    {
        $a = $this->seedBatch($p, 'A', ['qty' => 10, 'expiry_date' => '2027-03-01']);
        $b = $this->seedBatch($p, 'B', ['qty' => 10, 'expiry_date' => '2027-06-01']);
        $this->seedSlice($a, 10);
        $this->seedSlice($b, 10);

        return [$a, $b];
    }

    public function test_docwide_two_fefo_lines_share_the_batch_pool(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        [$a, $b] = $this->twoBatches($p);

        $plan = $this->planReturn(
            [$this->line($p, $u, 8, null, 10), $this->line($p, $u, 8, null, 11)],
            [[], []]
        );

        // line 1 drains A; line 2 gets what's left of A (2) then B (6).
        $this->assertSame([$a], array_column($plan[0]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([8.0], array_column($plan[0]['batch_allocation'], 'quantity_base'));
        $this->assertSame([$a, $b], array_column($plan[1]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([2.0, 6.0], array_column($plan[1]['batch_allocation'], 'quantity_base'));
        $this->assertSame([0, 1], array_column($plan[1]['batch_allocation'], 'bidx'));

        // GLOBAL: A = 10, B = 6.
        $global = [];
        foreach ([$plan[0], $plan[1]] as $l) {
            foreach ($l['batch_allocation'] as $x) {
                $global[$x['product_batch_id']] = ($global[$x['product_batch_id']] ?? 0) + $x['quantity_base'];
            }
        }
        $this->assertSame([$a => 10.0, $b => 6.0], $global);
        // DB untouched by the planner.
        $this->assertSame(10.0, (float) DB::table('product_batch_location_stocks')->where('product_batch_id', $a)->value('quantity'));
    }

    public function test_docwide_explicit_then_fefo(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        [$a, $b] = $this->twoBatches($p);

        $plan = $this->planReturn(
            [$this->line($p, $u, 8, null, 10), $this->line($p, $u, 8, null, 11)],
            [['batches' => [['product_batch_id' => $a, 'qty' => 8]]], []]
        );

        $this->assertSame([$a], array_column($plan[0]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([$a, $b], array_column($plan[1]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([2.0, 6.0], array_column($plan[1]['batch_allocation'], 'quantity_base'));
    }

    public function test_docwide_fefo_line_before_explicit_line_still_reserves_explicit_first(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        [$a, $b] = $this->twoBatches($p);

        // FEFO line is FIRST in the document; explicit A8 is SECOND.
        $plan = $this->planReturn(
            [$this->line($p, $u, 8, null, 10), $this->line($p, $u, 8, null, 11)],
            [[], ['batches' => [['product_batch_id' => $a, 'qty' => 8]]]]
        );

        // explicit reserved A8 in PASS 1 -> the earlier FEFO line only sees A2 + B6.
        $this->assertSame([$a, $b], array_column($plan[0]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([2.0, 6.0], array_column($plan[0]['batch_allocation'], 'quantity_base'));
        $this->assertSame([$a], array_column($plan[1]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([8.0], array_column($plan[1]['batch_allocation'], 'quantity_base'));
    }

    public function test_docwide_two_explicit_lines_same_batch_valid(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        [$a] = $this->twoBatches($p);

        $plan = $this->planReturn(
            [$this->line($p, $u, 3, null, 10), $this->line($p, $u, 4, null, 11)],
            [['batches' => [['product_batch_id' => $a, 'qty' => 3]]], ['batches' => [['product_batch_id' => $a, 'qty' => 4]]]]
        );

        $this->assertSame(3.0, $plan[0]['batch_allocation'][0]['quantity_base']);
        $this->assertSame(4.0, $plan[1]['batch_allocation'][0]['quantity_base']);
        $this->assertSame($a, $plan[0]['batch_allocation'][0]['product_batch_id']);
        $this->assertSame($a, $plan[1]['batch_allocation'][0]['product_batch_id']);
    }

    public function test_docwide_two_explicit_lines_overallocate_is_422_in_planner(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        [$a] = $this->twoBatches($p);   // A available 10

        try {
            $this->planReturn(
                [$this->line($p, $u, 6, null, 10), $this->line($p, $u, 6, null, 11)],   // 6 + 6 > 10
                [['batches' => [['product_batch_id' => $a, 'qty' => 6]]], ['batches' => [['product_batch_id' => $a, 'qty' => 6]]]]
            );
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertMatchesRegularExpression('/details\.\d+\.batches/', json_encode(array_keys($e->errors())));
        }
        // planner never mutates.
        $this->assertSame(10.0, (float) DB::table('product_batch_location_stocks')->where('product_batch_id', $a)->value('quantity'));
    }

    public function test_docwide_two_fefo_lines_globally_insufficient_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $a = $this->seedBatch($p, 'A', ['qty' => 5, 'expiry_date' => '2027-03-01']);
        $b = $this->seedBatch($p, 'B', ['qty' => 5, 'expiry_date' => '2027-06-01']);
        $this->seedSlice($a, 5);
        $this->seedSlice($b, 5);

        $this->expectException(ValidationException::class);
        $this->planReturn(
            [$this->line($p, $u, 7, null, 10), $this->line($p, $u, 7, null, 11)],   // 14 > 10
            [[], []]
        );
    }

    public function test_docwide_variant_pools_are_independent(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product(['type' => 'is_variant']);
        $vA = (int) DB::table('product_variants')->insertGetId(['product_id' => $p, 'name' => 'VA', 'created_at' => now(), 'updated_at' => now()]);
        $vB = (int) DB::table('product_variants')->insertGetId(['product_id' => $p, 'name' => 'VB', 'created_at' => now(), 'updated_at' => now()]);
        $ba = $this->seedBatch($p, 'A', ['qty' => 6, 'product_variant_id' => $vA, 'expiry_date' => '2027-03-01']);
        $bb = $this->seedBatch($p, 'B', ['qty' => 6, 'product_variant_id' => $vB, 'expiry_date' => '2027-03-01']);
        $this->seedSlice($ba, 6);
        $this->seedSlice($bb, 6);

        $plan = $this->planReturn(
            [$this->line($p, $u, 5, $vA, 10), $this->line($p, $u, 5, $vB, 11)],
            [[], []]
        );

        $this->assertSame([$ba], array_column($plan[0]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([$bb], array_column($plan[1]['batch_allocation'], 'product_batch_id'));
        $this->assertSame([5.0], array_column($plan[0]['batch_allocation'], 'quantity_base'));
    }

    public function test_docwide_two_lines_different_explicit_batches_map_by_ordinal(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        [$a, $b] = $this->twoBatches($p);

        $plan = $this->planReturn(
            [$this->line($p, $u, 4, null, 10), $this->line($p, $u, 3, null, 11)],
            [['batches' => [['product_batch_id' => $b, 'qty' => 4]]], ['batches' => [['product_batch_id' => $a, 'qty' => 3]]]]
        );

        $this->assertSame($b, $plan[0]['batch_allocation'][0]['product_batch_id']);
        $this->assertSame($a, $plan[1]['batch_allocation'][0]['product_batch_id']);
    }

    public function test_explicit_duplicate_batch_in_one_line_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        [$a] = $this->twoBatches($p);

        $this->expectException(ValidationException::class);
        $this->planReturn(
            [$this->line($p, $u, 7, null, 10)],
            [['batches' => [['product_batch_id' => $a, 'qty' => 3], ['product_batch_id' => $a, 'qty' => 4]]]]
        );
    }
}
