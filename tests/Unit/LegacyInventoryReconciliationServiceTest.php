<?php

namespace Tests\Unit;

use App\Models\InventoryLocationStock;
use App\Models\Warehouse;
use App\Services\LegacyInventoryReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyInventoryReconciliationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('email')->nullable();
            $table->string('zip')->nullable();
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
            $table->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_reconcile_unique');
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

        Schema::create('product_warehouse', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('qte', 12, 3);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->string('type')->default('is_single');
            $table->boolean('is_batch_tracked')->default(false);
            $table->integer('is_imei')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_batch_tracked_product_blocks_backfill_and_is_reported_in_audit(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $this->legacy($warehouse->id, 11, null, 3);
        $this->product(10, ['is_batch_tracked' => true]);
        $this->product(11);

        $service = app(LegacyInventoryReconciliationService::class);

        $audit = $service->auditWarehouse($warehouse->id);
        $this->assertCount(1, $audit['batch_or_serial_products']);
        $this->assertSame(10, $audit['batch_or_serial_products'][0]['product_id']);
        $this->assertFalse($audit['is_backfillable']);

        $this->expectException(ValidationException::class);
        $service->backfillWarehouse($warehouse->id);
    }

    public function test_serial_imei_product_blocks_backfill(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $this->product(10, ['is_imei' => 1]);

        $this->expectException(ValidationException::class);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $this->assertSame(0, InventoryLocationStock::count());
    }

    public function test_plain_products_still_backfill_when_products_table_present(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $this->product(10);

        $result = app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $this->assertTrue($result['is_reconciled']);
        $this->assertTrue($result['is_backfillable']);
        $this->assertEmpty($result['batch_or_serial_products']);
    }

    private function product(int $id, array $overrides = []): void
    {
        DB::table('products')->insert(array_merge([
            'id' => $id,
            'name' => 'Producto '.$id,
            'code' => 'P'.$id,
            'type' => 'is_single',
            'is_batch_tracked' => false,
            'is_imei' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_backfill_creates_default_cd_location_and_reconciles_exactly(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 7.5);
        $this->legacy($warehouse->id, 10, null, 2.5);
        $this->legacy($warehouse->id, 20, 201, 4);

        $result = app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);

        $this->assertTrue($result['is_reconciled']);
        $this->assertTrue($result['backfilled']);
        $this->assertSame(14.0, $result['legacy_total']);
        $this->assertSame(14.0, $result['location_total']);
        $this->assertEmpty($result['differences']);

        $warehouse->refresh();
        $this->assertNotNull($warehouse->default_inventory_location_id);
        $this->assertSame(10.0, (float) InventoryLocationStock::where('inventory_location_id', $warehouse->default_inventory_location_id)
            ->where('product_id', 10)->where('variant_key', 0)->value('quantity'));
        $this->assertSame(4.0, (float) InventoryLocationStock::where('inventory_location_id', $warehouse->default_inventory_location_id)
            ->where('product_id', 20)->where('variant_key', 201)->value('quantity'));
    }

    public function test_backfill_is_idempotent_after_exact_reconciliation(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);

        $service = app(LegacyInventoryReconciliationService::class);
        $service->backfillWarehouse($warehouse->id);
        $second = $service->backfillWarehouse($warehouse->id);

        $this->assertTrue($second['is_reconciled']);
        $this->assertTrue($second['already_reconciled']);
        $this->assertFalse($second['backfilled']);
        $this->assertSame(1, InventoryLocationStock::count());
    }

    public function test_negative_legacy_stock_blocks_backfill(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, -1);

        $this->expectException(ValidationException::class);
        app(LegacyInventoryReconciliationService::class)->backfillWarehouse($warehouse->id);
    }

    public function test_existing_divergent_location_stock_blocks_overwrite(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $this->legacy($warehouse->id, 10, null, 5);
        $service = app(LegacyInventoryReconciliationService::class);
        $service->backfillWarehouse($warehouse->id);

        DB::table('product_warehouse')->where('warehouse_id', $warehouse->id)->update(['qte' => 6]);

        $this->expectException(ValidationException::class);
        $service->backfillWarehouse($warehouse->id);
    }

    private function legacy(int $warehouseId, int $productId, ?int $variantId, float $quantity): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'qte' => $quantity,
            'manage_stock' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }
}
