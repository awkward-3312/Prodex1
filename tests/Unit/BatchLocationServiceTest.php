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
}
