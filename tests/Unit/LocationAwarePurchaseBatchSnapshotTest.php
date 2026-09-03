<?php

namespace Tests\Unit;

use App\Services\InventoryService;
use App\Services\LocationAwarePurchaseBatchPlanner as Planner;
use App\Services\LocationAwarePurchaseStockService as Svc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * MS5-B2 — LocationAwarePurchaseStockService batch-aware snapshot engine.
 * INACTIVE: no controller passes allow_batch.
 */
class LocationAwarePurchaseBatchSnapshotTest extends TestCase
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
        Schema::create('warehouses', fn ($t) => [$t->increments('id'), $t->string('name'), $t->timestamps(), $t->softDeletes()]);
        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
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
        Schema::create('inventory_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('inventory_location_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('variant_key')->default(0);
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('reserved_quantity', 12, 3)->default(0);
            $t->boolean('manage_stock')->default(true);
            $t->timestamps();
            $t->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_uq');
        });
        Schema::create('inventory_location_movements', function ($t) {
            $t->increments('id');
            $t->string('movement_type');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->integer('user_id')->nullable();
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('idempotency_fingerprint', 64)->nullable();
            $t->string('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
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
    }

    private function svc(): Svc
    {
        return app(Svc::class);
    }

    private function planner(): Planner
    {
        return app(Planner::class);
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

    private function line(int $productId, int $unitId, float $qty, ?int $sdid, ?int $variantId = null): array
    {
        return ['product_id' => $productId, 'product_variant_id' => $variantId, 'purchase_unit_id' => $unitId, 'quantity' => $qty, 'source_detail_id' => $sdid];
    }

    private function general(int $productId, ?int $variantId = null): float
    {
        return (float) DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)
            ->where('product_id', $productId)->where('variant_key', (int) ($variantId ?: 0))->value('quantity');
    }

    private function batchQty(int $batchId): float
    {
        return (float) DB::table('product_batches')->where('id', $batchId)->value('qty');
    }

    private function slice(int $batchId): float
    {
        return (float) DB::table('product_batch_location_stocks')->where('product_batch_id', $batchId)->value('quantity');
    }

    private function seedGeneral(int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $this->loc, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'variant_key' => (int) ($variantId ?: 0), 'quantity' => $qty, 'reserved_quantity' => 0,
            'manage_stock' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedBatch(int $productId, string $no, float $qty): int
    {
        $id = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $productId, 'product_variant_id' => null, 'warehouse_id' => $this->wh,
            'batch_no' => $no, 'qty' => $qty, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert([
            'product_batch_id' => $id, 'inventory_location_id' => $this->loc, 'quantity' => $qty,
            'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Build a batch-aware snapshot for a purchase / return. */
    private function buildSnap(string $doc, array $lines, array $raw, int $rev = 1): array
    {
        $v = $this->svc()->validateAndLock($doc, $this->wh, $this->loc, $lines, [], ['allow_batch' => true]);
        $v['lines'] = $doc === Svc::DOC_PURCHASE
            ? $this->planner()->planPurchaseReceipt($this->wh, $this->loc, $v['lines'], $raw)
            : $this->planner()->planPurchaseReturnIssue($this->wh, $this->loc, $v['lines'], $raw);

        return $this->svc()->buildSnapshot($v, $rev);
    }

    private function batchMovements(?string $ref = null): int
    {
        $q = DB::table('product_batch_location_movements');
        if ($ref) {
            $q->where('reference_type', $ref);
        }

        return (int) $q->count();
    }

    // ================= allow_batch on validateAndLock =================

    public function test_allow_batch_false_still_fails_closed(): void
    {
        $u = $this->unit();
        $p = $this->product();
        try {
            DB::transaction(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $this->wh, $this->loc, [$this->line($p, $u, 3, 1)]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details', $e->errors());
        }
    }

    public function test_allow_batch_true_marks_requires_batch(): void
    {
        $u = $this->unit();
        $p = $this->product();
        $v = DB::transaction(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $this->wh, $this->loc, [$this->line($p, $u, 3, 1)], [], ['allow_batch' => true]));
        $this->assertTrue($v['lines'][0]['requires_batch']);
    }

    public function test_allow_batch_true_imei_still_fails_closed(): void
    {
        $u = $this->unit();
        $p = $this->product(['is_batch_tracked' => 0, 'is_imei' => 1]);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $this->wh, $this->loc, [$this->line($p, $u, 3, 1)], [], ['allow_batch' => true]));
    }

    // ================= buildSnapshot / normalizeSnapshot =================

    public function test_build_snapshot_carries_batch_allocation(): void
    {
        $u = $this->unit('*', 12);
        $p = $this->product();
        $snap = DB::transaction(fn () => $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [
            ['batches' => [['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 4]]],
        ]));

        $e = $snap['effects'][0];
        $this->assertSame(120.0, $e['quantity_base']);
        $this->assertCount(2, $e['batch_allocation']);
        $this->assertSame([72.0, 48.0], array_column($e['batch_allocation'], 'quantity_base'));
        $this->assertSame([0, 1], array_column($e['batch_allocation'], 'bidx'));
    }

    public function test_normalize_snapshot_round_trips_batch_allocation(): void
    {
        $raw = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [[
                'source_detail_id' => 55, 'product_id' => 5, 'product_variant_id' => null,
                'quantity_base' => 10.0, 'delta' => 10.0,
                'batch_allocation' => [
                    ['bidx' => 1, 'product_batch_id' => 92, 'quantity_base' => 4.0, 'batch_no' => 'B'],
                    ['bidx' => 0, 'product_batch_id' => 91, 'quantity_base' => 6.0, 'batch_no' => 'A'],
                ],
            ]],
        ];
        $n = $this->svc()->normalizeSnapshot($raw);
        $alloc = $n['effects'][0]['batch_allocation'];
        $this->assertSame([0, 1], array_column($alloc, 'bidx'));   // sorted by bidx
        $this->assertSame([91, 92], array_column($alloc, 'product_batch_id'));
    }

    public function test_normalize_snapshot_without_batch_allocation_ok(): void
    {
        $raw = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [['source_detail_id' => 1, 'product_id' => 5, 'quantity_base' => 3.0, 'delta' => 3.0]],
        ];
        $n = $this->svc()->normalizeSnapshot($raw);
        $this->assertArrayNotHasKey('batch_allocation', $n['effects'][0]);
    }

    public function test_normalize_snapshot_sum_mismatch_is_422(): void
    {
        $raw = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [[
                'source_detail_id' => 1, 'product_id' => 5, 'quantity_base' => 10.0, 'delta' => 10.0,
                'batch_allocation' => [['bidx' => 0, 'product_batch_id' => 91, 'quantity_base' => 6.0]],
            ]],
        ];
        $this->expectException(ValidationException::class);
        $this->svc()->normalizeSnapshot($raw);
    }

    public function test_normalize_snapshot_duplicate_bidx_is_422(): void
    {
        $raw = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [[
                'source_detail_id' => 1, 'product_id' => 5, 'quantity_base' => 10.0, 'delta' => 10.0,
                'batch_allocation' => [
                    ['bidx' => 0, 'product_batch_id' => 91, 'quantity_base' => 6.0],
                    ['bidx' => 0, 'product_batch_id' => 92, 'quantity_base' => 4.0],
                ],
            ]],
        ];
        $this->expectException(ValidationException::class);
        $this->svc()->normalizeSnapshot($raw);
    }

    // ================= apply / reverse — directions =================

    public function test_purchase_apply_receives_batch_then_increases_general(): void
    {
        $u = $this->unit('*', 12);
        $p = $this->product();

        DB::transaction(function () use ($p, $u) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [
                ['batches' => [['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 4]]],
            ]);
            $this->svc()->applySnapshot($snap, 100);

            $ids = array_column($snap['effects'][0]['batch_allocation'], 'product_batch_id');
            $this->assertSame(72.0, $this->batchQty($ids[0]));
            $this->assertSame(48.0, $this->batchQty($ids[1]));
            $this->assertSame(120.0, $this->slice($ids[0]) + $this->slice($ids[1]));
            $this->assertSame(120.0, $this->general($p));
            $this->assertSame(2, $this->batchMovements('PurchaseBatch'));
            $this->assertSame(1, (int) DB::table('inventory_location_movements')->where('reference_type', 'Purchase')->count());
        });
    }

    public function test_purchase_reverse_issues_batch_then_decreases_general(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();

        DB::transaction(function () use ($p, $u) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [
                ['batches' => [['batch_no' => 'A', 'qty' => 10]]],
            ]);
            $this->svc()->applySnapshot($snap, 100);
            $bid = $snap['effects'][0]['batch_allocation'][0]['product_batch_id'];
            $this->assertSame(10.0, $this->batchQty($bid));

            $this->svc()->reverseSnapshot($snap, 100);
            $this->assertSame(0.0, $this->batchQty($bid));
            $this->assertSame(0.0, $this->slice($bid));
            $this->assertSame(0.0, $this->general($p));
            $this->assertSame(1, $this->batchMovements('PurchaseBatchReversal'));
        });
    }

    public function test_return_apply_issues_batch_then_decreases_general(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $b = $this->seedBatch($p, 'A', 10);
        $this->seedGeneral($p, 10);

        DB::transaction(function () use ($p, $u, $b) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE_RETURN, [$this->line($p, $u, 4, 80)], [
                ['batches' => [['product_batch_id' => $b, 'qty' => 4]]],
            ]);
            $this->svc()->applySnapshot($snap, 40);

            $this->assertSame(6.0, $this->batchQty($b));
            $this->assertSame(6.0, $this->slice($b));
            $this->assertSame(6.0, $this->general($p));
            $this->assertSame(1, $this->batchMovements('PurchaseReturnBatch'));
        });
    }

    public function test_return_reverse_receives_batch_then_increases_general(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $b = $this->seedBatch($p, 'A', 10);
        $this->seedGeneral($p, 10);

        DB::transaction(function () use ($p, $u, $b) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE_RETURN, [$this->line($p, $u, 4, 80)], [
                ['batches' => [['product_batch_id' => $b, 'qty' => 4]]],
            ]);
            $this->svc()->applySnapshot($snap, 40);
            $this->svc()->reverseSnapshot($snap, 40);

            $this->assertSame(10.0, $this->batchQty($b));
            $this->assertSame(10.0, $this->slice($b));
            $this->assertSame(10.0, $this->general($p));
            $this->assertSame(1, $this->batchMovements('PurchaseReturnBatchReversal'));
        });
    }

    public function test_multi_batch_a6_b4(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        DB::transaction(function () use ($p, $u) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [
                ['batches' => [['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 4]]],
            ]);
            $this->svc()->applySnapshot($snap, 100);
            $ids = array_column($snap['effects'][0]['batch_allocation'], 'product_batch_id');
            $this->assertSame(6.0, $this->batchQty($ids[0]));
            $this->assertSame(4.0, $this->batchQty($ids[1]));
            $this->assertSame(10.0, $this->general($p));
        });
    }

    public function test_same_batch_no_across_two_details_two_ledger_rows_one_physical(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        DB::transaction(function () use ($p, $u) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE,
                [$this->line($p, $u, 3, 10), $this->line($p, $u, 4, 11)],
                [
                    ['batches' => [['batch_no' => 'SHARED', 'qty' => 3]]],
                    ['batches' => [['batch_no' => 'SHARED', 'qty' => 4]]],
                ]
            );
            $this->svc()->applySnapshot($snap, 100);

            $bid = $snap['effects'][0]['batch_allocation'][0]['product_batch_id'];
            $this->assertSame($bid, $snap['effects'][1]['batch_allocation'][0]['product_batch_id']);
            $this->assertSame(7.0, $this->batchQty($bid));      // 3 + 4 physical
            $this->assertSame(2, $this->batchMovements('PurchaseBatch'));   // one per detail
        });
    }

    public function test_mixed_batch_and_simple_product(): void
    {
        $u = $this->unit('*', 1);
        $batched = $this->product();
        $simple = $this->product(['is_batch_tracked' => 0]);
        DB::transaction(function () use ($batched, $simple, $u) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE,
                [$this->line($batched, $u, 5, 10), $this->line($simple, $u, 3, 11)],
                [['batches' => [['batch_no' => 'A', 'qty' => 5]]], []]
            );
            $this->svc()->applySnapshot($snap, 100);
            $bid = $snap['effects'][0]['batch_allocation'][0]['product_batch_id'] ?? $snap['effects'][1]['batch_allocation'][0]['product_batch_id'];
            $this->assertSame(5.0, $this->batchQty($bid));
            $this->assertSame(5.0, $this->general($batched));
            $this->assertSame(3.0, $this->general($simple));
        });
    }

    public function test_apply_replay_is_noop(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        DB::transaction(function () use ($p, $u) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [['batches' => [['batch_no' => 'A', 'qty' => 10]]]]);
            $this->svc()->applySnapshot($snap, 100);
            $this->svc()->applySnapshot($snap, 100);
            $bid = $snap['effects'][0]['batch_allocation'][0]['product_batch_id'];
            $this->assertSame(10.0, $this->batchQty($bid));
            $this->assertSame(10.0, $this->general($p));
            $this->assertSame(1, $this->batchMovements());
            $this->assertSame(1, (int) DB::table('inventory_location_movements')->count());
        });
    }

    public function test_reverse_replay_is_noop(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        DB::transaction(function () use ($p, $u) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [['batches' => [['batch_no' => 'A', 'qty' => 10]]]]);
            $this->svc()->applySnapshot($snap, 100);
            $this->svc()->reverseSnapshot($snap, 100);
            $this->svc()->reverseSnapshot($snap, 100);
            $bid = $snap['effects'][0]['batch_allocation'][0]['product_batch_id'];
            $this->assertSame(0.0, $this->batchQty($bid));
            $this->assertSame(0.0, $this->general($p));
        });
    }

    public function test_outer_rollback_reverts_batch_and_general_together(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        try {
            DB::transaction(function () use ($p, $u) {
                $snap = $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [['batches' => [['batch_no' => 'A', 'qty' => 10]]]]);
                $this->svc()->applySnapshot($snap, 100);
                throw new \RuntimeException('__ROLLBACK__');
            });
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('__ROLLBACK__', $e->getMessage());
        }
        $this->assertSame(0, (int) DB::table('product_batches')->count());
        $this->assertSame(0, (int) DB::table('product_batch_location_movements')->count());
        $this->assertSame(0, (int) DB::table('inventory_location_movements')->count());
    }

    // ================= artifact safety =================

    public function test_old_snapshot_without_batch_alloc_for_now_batch_product_is_422(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product(['is_batch_tracked' => 0]);
        // build a plain (non-batch) snapshot, then flip the product to batch.
        $snap = DB::transaction(function () use ($p, $u) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $this->wh, $this->loc, [$this->line($p, $u, 5, 55)]);

            return $this->svc()->buildSnapshot($v, 1);
        });
        DB::table('products')->where('id', $p)->update(['is_batch_tracked' => 1]);

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_batch' => true]));
    }

    public function test_artifact_safe_imei_still_fails_closed_even_with_allow_batch(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product(['is_batch_tracked' => 0]);
        $snap = DB::transaction(function () use ($p, $u) {
            $v = $this->svc()->validateAndLock(Svc::DOC_PURCHASE, $this->wh, $this->loc, [$this->line($p, $u, 5, 55)]);

            return $this->svc()->buildSnapshot($v, 1);
        });
        DB::table('products')->where('id', $p)->update(['is_imei' => 1]);

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_batch' => true]));
    }

    public function test_artifact_safe_locks_batch_allocation_and_rejects_wrong_product(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $other = $this->product();
        $bWrong = $this->seedBatch($other, 'W', 10);

        $snap = DB::transaction(fn () => $this->buildSnap(Svc::DOC_PURCHASE, [$this->line($p, $u, 10, 55)], [['batches' => [['batch_no' => 'A', 'qty' => 10]]]]));
        // tamper: point the allocation at a batch of another product.
        $snap['effects'][0]['batch_allocation'][0]['product_batch_id'] = $bWrong;

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_batch' => true]));
    }

    // ===== MS5-B2.1 — planner -> snapshot -> issueMany compose (INACTIVE) ==

    public function test_document_wide_return_fefo_composes_planner_snapshot_issue_many(): void
    {
        $u = $this->unit('*', 1);
        $p = $this->product();
        $a = $this->seedBatch($p, 'A', 10);   // creates slice = 10
        $b = $this->seedBatch($p, 'B', 10);
        $this->seedGeneral($p, 20);

        DB::transaction(function () use ($p, $u, $a, $b) {
            $snap = $this->buildSnap(Svc::DOC_PURCHASE_RETURN,
                [$this->line($p, $u, 8, 80), $this->line($p, $u, 8, 81)],
                [[], []]                       // both FEFO
            );

            // the frozen plan: detail 80 -> A8 ; detail 81 -> A2 + B6.
            $this->assertSame([$a], array_column($snap['effects'][0]['batch_allocation'], 'product_batch_id'));
            $this->assertSame([$a, $b], array_column($snap['effects'][1]['batch_allocation'], 'product_batch_id'));
            $this->assertSame([2.0, 6.0], array_column($snap['effects'][1]['batch_allocation'], 'quantity_base'));

            $this->svc()->applySnapshot($snap, 40);

            $this->assertSame(0.0, $this->batchQty($a));
            $this->assertSame(4.0, $this->batchQty($b));
            $this->assertSame(0.0, $this->slice($a));
            $this->assertSame(4.0, $this->slice($b));
            $this->assertSame(4.0, $this->general($p));

            // one batch ledger row per allocation, all issues (loc -> NULL).
            $this->assertSame(3, $this->batchMovements('PurchaseReturnBatch'));
            $rows = DB::table('product_batch_location_movements')->orderBy('id')->get();
            $this->assertSame([$a, $a, $b], $rows->pluck('product_batch_id')->map(fn ($x) => (int) $x)->all());
            $this->assertSame([8.0, 2.0, 6.0], $rows->pluck('quantity')->map(fn ($x) => (float) $x)->all());
        });
    }
}
