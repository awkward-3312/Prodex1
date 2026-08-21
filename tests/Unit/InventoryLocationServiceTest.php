<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Warehouse;
use App\Services\InventoryLocationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryLocationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('type')->default('branch');
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

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
    }

    public function test_branch_sales_floor_can_be_created_without_touching_legacy_stock(): void
    {
        $branch = Branch::create(['name' => 'Sucursal Mall', 'is_active' => true]);

        $location = app(InventoryLocationService::class)->createForBranch($branch, [
            'code' => 'PISO',
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_default_sales' => true,
        ]);

        $this->assertSame($branch->id, $location->branch_id);
        $this->assertNull($location->warehouse_id);
        $this->assertTrue($location->is_sellable);
        $this->assertTrue($location->is_default_sales);
        $this->assertSame($location->id, $branch->fresh()->default_inventory_location_id);
        $this->assertFalse(Schema::hasTable('product_inventory_locations'));
    }

    public function test_only_one_default_sales_location_remains_per_branch(): void
    {
        $branch = Branch::create(['name' => 'Sucursal Centro', 'is_active' => true]);
        $service = app(InventoryLocationService::class);

        $first = $service->createForBranch($branch, [
            'code' => 'PISO1',
            'name' => 'Piso principal',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_default_sales' => true,
        ]);

        $second = $service->createForBranch($branch, [
            'code' => 'PISO2',
            'name' => 'Piso secundario',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_default_sales' => true,
        ]);

        $this->assertFalse($first->fresh()->is_default_sales);
        $this->assertTrue($second->fresh()->is_default_sales);
        $this->assertSame($second->id, $branch->fresh()->default_inventory_location_id);
    }

    public function test_warehouse_location_can_be_default_for_distribution_center(): void
    {
        $warehouse = Warehouse::create(['name' => 'CD Principal']);
        $service = app(InventoryLocationService::class);

        $location = $service->createForWarehouse($warehouse, [
            'code' => 'MAIN',
            'name' => 'Inventario principal',
            'type' => InventoryLocation::TYPE_STORAGE,
        ]);
        $service->setWarehouseDefault($location);

        $this->assertNull($location->branch_id);
        $this->assertSame($warehouse->id, $location->warehouse_id);
        $this->assertSame($location->id, $warehouse->fresh()->default_inventory_location_id);
    }

    public function test_location_cannot_belong_to_branch_and_warehouse_at_the_same_time(): void
    {
        $branch = Branch::create(['name' => 'Sucursal A', 'is_active' => true]);
        $warehouse = Warehouse::create(['name' => 'CD A']);

        $this->expectException(ValidationException::class);

        InventoryLocation::create([
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'code' => 'INVALID',
            'name' => 'Inválida',
            'type' => InventoryLocation::TYPE_STORAGE,
        ]);
    }

    public function test_quarantine_location_is_never_sellable(): void
    {
        $branch = Branch::create(['name' => 'Sucursal A', 'is_active' => true]);

        $location = app(InventoryLocationService::class)->createForBranch($branch, [
            'code' => 'Q',
            'name' => 'Cuarentena',
            'type' => InventoryLocation::TYPE_QUARANTINE,
            'is_sellable' => true,
        ]);

        $this->assertTrue($location->is_quarantine);
        $this->assertFalse($location->is_sellable);
    }
}
