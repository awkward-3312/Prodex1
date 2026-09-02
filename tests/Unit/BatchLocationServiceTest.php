<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationMovement;
use App\Models\ProductBatchLocationStock;
use App\Services\BatchLocationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class BatchLocationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_locations', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('type')->default('storage');
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_batches', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('batch_no');
            $table->date('expiry_date')->nullable();
            $table->date('mfg_date')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->decimal('unit_cost', 12, 3)->nullable();
            $table->integer('provider_id')->nullable();
            $table->integer('source_purchase_id')->nullable();
            $table->string('status')->default('active');
            $table->string('barcode')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_batch_location_stocks', function ($table) {
            $table->increments('id');
            $table->integer('product_batch_id');
            $table->integer('inventory_location_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->timestamps();
            $table->unique(['product_batch_id', 'inventory_location_id'], 'batch_location_unique_test');
        });

        Schema::create('product_batch_location_movements', function ($table) {
            $table->increments('id');
            $table->integer('product_batch_id');
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->integer('user_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_location_stocks', function ($table) {
            $table->increments('id');
            $table->integer('inventory_location_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('variant_key')->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_location_movements', function ($table) {
            $table->increments('id');
            $table->string('movement_type');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->integer('user_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('idempotency_fingerprint', 64)->nullable();
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_batch_can_be_split_between_branch_locations_without_changing_legacy_total(): void
    {
        [$from, $to] = $this->locations();
        $batch = ProductBatch::create([
            'product_id' => 10,
            'batch_no' => 'LOT-001',
            'qty' => 20,
            'status' => 'active',
        ]);
        ProductBatchLocationStock::create([
            'product_batch_id' => $batch->id,
            'inventory_location_id' => $from->id,
            'quantity' => 20,
        ]);

        $movement = app(BatchLocationService::class)->move(
            $batch->id,
            $from->id,
            $to->id,
            7,
            ['idempotency_key' => 'batch-move-001']
        );

        $this->assertSame(13.0, (float) ProductBatchLocationStock::where('inventory_location_id', $from->id)->value('quantity'));
        $this->assertSame(7.0, (float) ProductBatchLocationStock::where('inventory_location_id', $to->id)->value('quantity'));
        $this->assertSame(20.0, (float) $batch->fresh()->qty);
        $this->assertSame(7.0, (float) $movement->quantity);
        $this->assertTrue(app(BatchLocationService::class)->reconcileBatch($batch->id)['matches']);
    }

    public function test_batch_move_rejects_quantity_reserved_at_source(): void
    {
        [$from, $to] = $this->locations();
        $batch = ProductBatch::create(['product_id' => 10, 'batch_no' => 'LOT-002', 'qty' => 10, 'status' => 'active']);
        ProductBatchLocationStock::create([
            'product_batch_id' => $batch->id,
            'inventory_location_id' => $from->id,
            'quantity' => 10,
            'reserved_quantity' => 8,
        ]);

        $this->expectException(ValidationException::class);
        app(BatchLocationService::class)->move($batch->id, $from->id, $to->id, 3);
    }

    public function test_batch_move_is_idempotent(): void
    {
        [$from, $to] = $this->locations();
        $batch = ProductBatch::create(['product_id' => 11, 'batch_no' => 'LOT-003', 'qty' => 5, 'status' => 'active']);
        ProductBatchLocationStock::create([
            'product_batch_id' => $batch->id,
            'inventory_location_id' => $from->id,
            'quantity' => 5,
        ]);

        $service = app(BatchLocationService::class);
        $first = $service->move($batch->id, $from->id, $to->id, 2, ['idempotency_key' => 'same-batch-move']);
        $second = $service->move($batch->id, $from->id, $to->id, 2, ['idempotency_key' => 'same-batch-move']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(3.0, (float) ProductBatchLocationStock::where('inventory_location_id', $from->id)->value('quantity'));
        $this->assertSame(2.0, (float) ProductBatchLocationStock::where('inventory_location_id', $to->id)->value('quantity'));
    }

    public function test_batch_movement_ledger_is_immutable(): void
    {
        [$from, $to] = $this->locations();
        $batch = ProductBatch::create(['product_id' => 12, 'batch_no' => 'LOT-004', 'qty' => 3, 'status' => 'active']);
        ProductBatchLocationStock::create(['product_batch_id' => $batch->id, 'inventory_location_id' => $from->id, 'quantity' => 3]);
        $movement = app(BatchLocationService::class)->move($batch->id, $from->id, $to->id, 1);

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    private function locations(): array
    {
        $branch = Branch::create(['name' => 'Sucursal', 'is_active' => true]);
        $from = InventoryLocation::create([
            'branch_id' => $branch->id,
            'code' => 'BODEGA',
            'name' => 'Bodega',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);
        $to = InventoryLocation::create([
            'branch_id' => $branch->id,
            'code' => 'PISO',
            'name' => 'Piso',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_active' => true,
        ]);

        return [$from, $to];
    }

    // =====================================================================
    // MS5-B0 — external primitives: receive() / issue() / batchCoverageForLocation()
    //
    // A warehouse-scoped location (branch_id NULL, warehouse_id set) is required
    // because receive()/issue() enforce location.warehouse_id == batch.warehouse_id.
    // =====================================================================

    private function whLocation(int $warehouseId, string $code = 'CD-A'): InventoryLocation
    {
        return InventoryLocation::create([
            'warehouse_id' => $warehouseId,
            'code' => $code,
            'name' => $code,
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);
    }

    private function whBatch(int $warehouseId, array $o = []): ProductBatch
    {
        return ProductBatch::create(array_merge([
            'product_id' => 100,
            'product_variant_id' => null,
            'warehouse_id' => $warehouseId,
            'batch_no' => 'B-'.\Illuminate\Support\Str::random(5),
            'qty' => 0,
            'status' => 'active',
        ], $o));
    }

    private function slice(int $batchId, int $locationId, float $qty, float $reserved = 0): ProductBatchLocationStock
    {
        return ProductBatchLocationStock::create([
            'product_batch_id' => $batchId,
            'inventory_location_id' => $locationId,
            'quantity' => $qty,
            'reserved_quantity' => $reserved,
        ]);
    }

    private function movementCount(): int
    {
        return (int) ProductBatchLocationMovement::count();
    }

    // ---- RECEIVE --------------------------------------------------------

    public function test_receive_credits_new_batch_and_writes_null_to_location(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7);                 // qty 0, no slices — brand new

        $movement = app(BatchLocationService::class)->receive($batch->id, $loc->id, 10, ['idempotency_key' => 'rcv-1']);

        $this->assertSame(10.0, (float) $batch->fresh()->qty);
        $this->assertSame(10.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertNull($movement->from_inventory_location_id);
        $this->assertSame($loc->id, (int) $movement->to_inventory_location_id);
        $this->assertSame(10.0, (float) $movement->quantity);
        $this->assertSame(1, $this->movementCount());
    }

    public function test_receive_is_idempotent_on_key_replay(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7);
        $svc = app(BatchLocationService::class);

        $first = $svc->receive($batch->id, $loc->id, 10, ['idempotency_key' => 'rcv-same']);
        $second = $svc->receive($batch->id, $loc->id, 10, ['idempotency_key' => 'rcv-same']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(10.0, (float) $batch->fresh()->qty);
        $this->assertSame(10.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(1, $this->movementCount());
    }

    public function test_receive_same_key_different_quantity_is_422(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7);
        $svc = app(BatchLocationService::class);
        $svc->receive($batch->id, $loc->id, 10, ['idempotency_key' => 'rcv-clash']);

        try {
            $svc->receive($batch->id, $loc->id, 11, ['idempotency_key' => 'rcv-clash']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }
        $this->assertSame(10.0, (float) $batch->fresh()->qty);
        $this->assertSame(1, $this->movementCount());
    }

    public function test_receive_into_location_of_another_warehouse_is_422(): void
    {
        $loc = $this->whLocation(9, 'CD-OTHER');
        $batch = $this->whBatch(7);

        try {
            app(BatchLocationService::class)->receive($batch->id, $loc->id, 5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }
        $this->assertSame(0.0, (float) $batch->fresh()->qty);
        $this->assertSame(0, $this->movementCount());
    }

    public function test_receive_into_inactive_location_is_422(): void
    {
        $loc = $this->whLocation(7);
        $loc->update(['is_active' => false]);
        $batch = $this->whBatch(7);

        $this->expectException(ValidationException::class);
        try {
            app(BatchLocationService::class)->receive($batch->id, $loc->id, 5);
        } finally {
            $this->assertSame(0.0, (float) $batch->fresh()->qty);
            $this->assertSame(0, $this->movementCount());
        }
    }

    public function test_receive_soft_deleted_batch_fails_closed_and_is_not_restored(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7);
        $batch->delete();

        try {
            app(BatchLocationService::class)->receive($batch->id, $loc->id, 5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_batch_id', $e->errors());
        }
        $this->assertNotNull(ProductBatch::withTrashed()->find($batch->id)->deleted_at);
        $this->assertSame(0, $this->movementCount());
    }

    public function test_receive_written_off_batch_fails_closed_without_reactivating(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['status' => 'written_off']);

        try {
            app(BatchLocationService::class)->receive($batch->id, $loc->id, 5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }
        $this->assertSame('written_off', $batch->fresh()->status);
        $this->assertSame(0, $this->movementCount());
    }

    public function test_receive_on_drifted_batch_fails_closed(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['qty' => 10]);   // aggregate 10
        $this->slice($batch->id, $loc->id, 12);      // slices 12 -> reconcile mismatch

        try {
            app(BatchLocationService::class)->receive($batch->id, $loc->id, 5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }
        $this->assertSame(10.0, (float) $batch->fresh()->qty);
        $this->assertSame(12.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(0, $this->movementCount());
    }

    // ---- ISSUE --------------------------------------------------------

    public function test_issue_debits_batch_and_slice_and_writes_location_to_null(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['qty' => 20]);
        $this->slice($batch->id, $loc->id, 20);
        $this->generalStock($loc->id, 100, null, 20);      // coherent coverage

        $movement = app(BatchLocationService::class)->issue($batch->id, $loc->id, 5, ['idempotency_key' => 'iss-1']);

        $this->assertSame(15.0, (float) $batch->fresh()->qty);
        $this->assertSame(15.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame($loc->id, (int) $movement->from_inventory_location_id);
        $this->assertNull($movement->to_inventory_location_id);
        $this->assertSame(1, $this->movementCount());
    }

    public function test_issue_is_idempotent_on_key_replay(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['qty' => 20]);
        $this->slice($batch->id, $loc->id, 20);
        $this->generalStock($loc->id, 100, null, 20);
        $svc = app(BatchLocationService::class);

        $first = $svc->issue($batch->id, $loc->id, 5, ['idempotency_key' => 'iss-same']);
        $second = $svc->issue($batch->id, $loc->id, 5, ['idempotency_key' => 'iss-same']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(15.0, (float) $batch->fresh()->qty);
        $this->assertSame(15.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(1, $this->movementCount());
    }

    public function test_issue_insufficient_slice_is_422_no_change(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['qty' => 3]);
        $this->slice($batch->id, $loc->id, 3);
        $this->generalStock($loc->id, 100, null, 3);       // coverage OK -> slice guard fires

        try {
            app(BatchLocationService::class)->issue($batch->id, $loc->id, 5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
        $this->assertSame(3.0, (float) $batch->fresh()->qty);
        $this->assertSame(3.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(0, $this->movementCount());
    }

    public function test_issue_cannot_consume_reserved_quantity(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['qty' => 10]);
        $this->slice($batch->id, $loc->id, 10, 8);   // available = 2
        $this->generalStock($loc->id, 100, null, 10);   // coverage OK (raw slice qty 10)

        try {
            app(BatchLocationService::class)->issue($batch->id, $loc->id, 5);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
        $this->assertSame(10.0, (float) $batch->fresh()->qty);
        $this->assertSame(10.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(0, $this->movementCount());
    }

    public function test_issue_on_drifted_batch_fails_closed(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['qty' => 10]);
        $this->slice($batch->id, $loc->id, 7);       // reconcile mismatch

        try {
            app(BatchLocationService::class)->issue($batch->id, $loc->id, 3);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }
        $this->assertSame(10.0, (float) $batch->fresh()->qty);
        $this->assertSame(7.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(0, $this->movementCount());
    }

    public function test_issue_written_off_batch_fails_closed(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['qty' => 10, 'status' => 'written_off']);
        $this->slice($batch->id, $loc->id, 10);

        $this->expectException(ValidationException::class);
        try {
            app(BatchLocationService::class)->issue($batch->id, $loc->id, 3);
        } finally {
            $this->assertSame(10.0, (float) $batch->fresh()->qty);
            $this->assertSame(0, $this->movementCount());
        }
    }

    // ---- COVERAGE ---------------------------------------------------------

    public function test_batch_coverage_matches_when_general_equals_batch_sum(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['product_id' => 200, 'qty' => 10]);
        $this->slice($batch->id, $loc->id, 10);
        $this->generalStock($loc->id, 200, null, 10);

        $cov = app(BatchLocationService::class)->batchCoverageForLocation($loc->id, 200);
        $this->assertSame(10.0, $cov['general_quantity']);
        $this->assertSame(10.0, $cov['batch_quantity']);
        $this->assertSame(0.0, $cov['difference']);
        $this->assertTrue($cov['matches']);
    }

    public function test_batch_coverage_mismatch_when_general_exceeds_batch_sum(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['product_id' => 201, 'qty' => 8]);
        $this->slice($batch->id, $loc->id, 8);
        $this->generalStock($loc->id, 201, null, 10);

        $cov = app(BatchLocationService::class)->batchCoverageForLocation($loc->id, 201);
        $this->assertSame(10.0, $cov['general_quantity']);
        $this->assertSame(8.0, $cov['batch_quantity']);
        $this->assertSame(-2.0, $cov['difference']);
        $this->assertFalse($cov['matches']);
    }

    public function test_batch_coverage_separates_variants(): void
    {
        $loc = $this->whLocation(7);
        $vBatch = $this->whBatch(7, ['product_id' => 202, 'product_variant_id' => 55, 'qty' => 6]);
        $nBatch = $this->whBatch(7, ['product_id' => 202, 'product_variant_id' => null, 'qty' => 3]);
        $this->slice($vBatch->id, $loc->id, 6);
        $this->slice($nBatch->id, $loc->id, 3);
        $this->generalStock($loc->id, 202, 55, 6);
        $this->generalStock($loc->id, 202, null, 99);   // drift on the no-variant key

        $covV = app(BatchLocationService::class)->batchCoverageForLocation($loc->id, 202, 55);
        $this->assertTrue($covV['matches']);
        $this->assertSame(6.0, $covV['batch_quantity']);

        $covN = app(BatchLocationService::class)->batchCoverageForLocation($loc->id, 202, null);
        $this->assertFalse($covN['matches']);
        $this->assertSame(3.0, $covN['batch_quantity']);
        $this->assertSame(99.0, $covN['general_quantity']);
    }

    public function test_batch_coverage_ignores_other_locations_and_does_not_mutate(): void
    {
        $locA = $this->whLocation(7, 'CD-A');
        $locB = $this->whLocation(7, 'CD-B');
        $batch = $this->whBatch(7, ['product_id' => 203, 'qty' => 12]);
        $this->slice($batch->id, $locA->id, 5);
        $this->slice($batch->id, $locB->id, 7);
        $this->generalStock($locA->id, 203, null, 5);

        $before = [ProductBatchLocationStock::count(), ProductBatch::count(), $this->movementCount()];
        $cov = app(BatchLocationService::class)->batchCoverageForLocation($locA->id, 203);
        app(BatchLocationService::class)->batchCoverageForLocation($locA->id, 203);

        $this->assertSame(5.0, $cov['general_quantity']);
        $this->assertSame(5.0, $cov['batch_quantity']);   // locB slice ignored
        $this->assertTrue($cov['matches']);
        $this->assertSame($before, [ProductBatchLocationStock::count(), ProductBatch::count(), $this->movementCount()]);
    }

    // ---- B0.1: general-coverage gate --------------------------------------

    /**
     * The exact legacy false positive: aggregate reconciles (A == B == 10) but
     * the general location stock is 120 (10 boxes x 12, backfill copied 10 to
     * the slice). receive() AND issue() must FAIL CLOSED with zero mutation.
     */
    public function test_receive_and_issue_fail_closed_on_legacy_coverage_drift_even_when_aggregate_reconciles(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['product_id' => 300, 'qty' => 10]);
        $this->slice($batch->id, $loc->id, 10);              // A = 10
        $this->generalStock($loc->id, 300, null, 120);       // C = 120

        $svc = app(BatchLocationService::class);

        // The false positive is real: aggregate matches, coverage does not.
        $this->assertTrue($svc->reconcileBatch($batch->id)['matches']);
        $this->assertFalse($svc->batchCoverageForLocation($loc->id, 300)['matches']);

        try {
            $svc->receive($batch->id, $loc->id, 12);
            $this->fail('expected ValidationException on receive');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }

        try {
            $svc->issue($batch->id, $loc->id, 5);
            $this->fail('expected ValidationException on issue');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }

        $this->assertSame(10.0, (float) $batch->fresh()->qty);
        $this->assertSame(10.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(120.0, (float) \App\Models\InventoryLocationStock::query()
            ->where('inventory_location_id', $loc->id)->where('product_id', 300)->value('quantity'));
        $this->assertSame(0, $this->movementCount());
    }

    /**
     * Correct case + the composition contract: the primitive moves ONLY the
     * batch artifact; the business layer completes it with InventoryService in
     * the same conceptual transaction. Final state is fully reconciled.
     */
    public function test_receive_composes_with_general_increase_to_a_reconciled_state(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['product_id' => 301, 'qty' => 10]);
        $this->slice($batch->id, $loc->id, 10);
        $this->generalStock($loc->id, 301, null, 10);        // A=B=C=10

        $svc = app(BatchLocationService::class);
        $svc->receive($batch->id, $loc->id, 5, ['idempotency_key' => 'compose-rcv']);

        // Artifact layer moved; general NOT touched by the primitive.
        $this->assertSame(15.0, (float) $batch->fresh()->qty);
        $this->assertSame(15.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(10.0, (float) $this->generalQty($loc->id, 301));

        // Business layer completes the composition.
        app(\App\Services\InventoryService::class)->increase($loc->id, 301, 5, null, [
            'idempotency_key' => 'compose-rcv:general', 'reference_type' => 'Test',
        ]);

        $this->assertSame(15.0, (float) $this->generalQty($loc->id, 301));
        $this->assertTrue($svc->reconcileBatch($batch->id)['matches']);
        $this->assertTrue($svc->batchCoverageForLocation($loc->id, 301)['matches']);
    }

    public function test_issue_composes_with_general_decrease_to_a_reconciled_state(): void
    {
        $loc = $this->whLocation(7);
        $batch = $this->whBatch(7, ['product_id' => 302, 'qty' => 20]);
        $this->slice($batch->id, $loc->id, 20);
        $this->generalStock($loc->id, 302, null, 20);

        $svc = app(BatchLocationService::class);
        $svc->issue($batch->id, $loc->id, 5, ['idempotency_key' => 'compose-iss']);

        $this->assertSame(15.0, (float) $batch->fresh()->qty);
        $this->assertSame(15.0, (float) ProductBatchLocationStock::where('product_batch_id', $batch->id)->value('quantity'));
        $this->assertSame(20.0, (float) $this->generalQty($loc->id, 302));

        app(\App\Services\InventoryService::class)->decrease($loc->id, 302, 5, null, [
            'idempotency_key' => 'compose-iss:general', 'reference_type' => 'Test',
        ]);

        $this->assertSame(15.0, (float) $this->generalQty($loc->id, 302));
        $this->assertTrue($svc->reconcileBatch($batch->id)['matches']);
        $this->assertTrue($svc->batchCoverageForLocation($loc->id, 302)['matches']);
    }

    private function generalQty(int $locationId, int $productId, ?int $variantId = null): float
    {
        return (float) \App\Models\InventoryLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->where('product_id', $productId)
            ->where('variant_key', (int) ($variantId ?: 0))
            ->value('quantity');
    }

    private function generalStock(int $locationId, int $productId, ?int $variantId, float $qty): void
    {
        \App\Models\InventoryLocationStock::create([
            'inventory_location_id' => $locationId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'variant_key' => (int) ($variantId ?: 0),
            'quantity' => $qty,
            'reserved_quantity' => 0,
            'manage_stock' => true,
        ]);
    }
}
