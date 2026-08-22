<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Warehouse;
use App\Services\TransferBusinessDestinationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferBusinessDestinationServiceTest extends TestCase
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

    public function test_distribution_center_only_offers_branches_and_resolves_to_their_storage(): void
    {
        [$cd, $branchAStorage, $branchAFloor, $branchBStorage] = $this->fixture();

        $options = app(TransferBusinessDestinationService::class)->optionsForSource($cd);

        $this->assertSame([$branchAStorage->id, $branchBStorage->id], $options->pluck('id')->all());
        $this->assertSame(['Sucursal A', 'Sucursal B'], $options->pluck('name')->all());
        $this->assertNotContains($branchAFloor->id, $options->pluck('id')->all());
    }

    public function test_branch_storage_can_send_to_other_branch_storage_or_its_own_sales_floor(): void
    {
        [, $branchAStorage, $branchAFloor, $branchBStorage] = $this->fixture();

        $options = app(TransferBusinessDestinationService::class)->optionsForSource($branchAStorage);

        $this->assertContains($branchBStorage->id, $options->pluck('id')->all());
        $this->assertContains($branchAFloor->id, $options->pluck('id')->all());
        $this->assertNotContains($branchAStorage->id, $options->pluck('id')->all());
    }

    public function test_cd_to_sales_floor_is_rejected_even_if_called_directly(): void
    {
        [$cd, , $branchAFloor] = $this->fixture();

        $this->expectException(ValidationException::class);
        app(TransferBusinessDestinationService::class)->assertAllowed($cd->id, $branchAFloor->id);
    }

    public function test_cd_to_branch_storage_is_allowed(): void
    {
        [$cd, $branchAStorage] = $this->fixture();

        app(TransferBusinessDestinationService::class)->assertAllowed($cd->id, $branchAStorage->id);
        $this->assertTrue(true);
    }

    public function test_inactive_branch_is_not_offered_and_cannot_receive_inventory(): void
    {
        [$cd, , , $branchBStorage] = $this->fixture();
        $branchBStorage->branch->update(['is_active' => false]);

        $options = app(TransferBusinessDestinationService::class)->optionsForSource($cd);
        $this->assertNotContains($branchBStorage->id, $options->pluck('id')->all());

        $this->expectException(ValidationException::class);
        app(TransferBusinessDestinationService::class)->assertAllowed($cd->id, $branchBStorage->id);
    }

    public function test_route_overrides_send_modern_mutations_through_final_controller(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_transfer_overrides.php'));

        $this->assertStringContainsString("Route::post('transfers', 'FinalTransferController@store')", $routes);
        $this->assertStringContainsString("Route::put('transfers/{id}', 'FinalTransferController@update')", $routes);
        $this->assertStringContainsString("Route::patch('transfers/{id}', 'FinalTransferController@update')", $routes);
    }

    private function fixture(): array
    {
        $warehouse = Warehouse::create(['name' => 'Centro de Distribución']);
        $cd = InventoryLocation::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'MAIN',
            'name' => 'Inventario principal',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);

        $branchA = Branch::create(['name' => 'Sucursal A', 'is_active' => true]);
        $branchAStorage = InventoryLocation::create([
            'branch_id' => $branchA->id,
            'code' => 'BODEGA',
            'name' => 'Bodega de sucursal',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);
        $branchAFloor = InventoryLocation::create([
            'branch_id' => $branchA->id,
            'code' => 'PISO',
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_default_sales' => true,
            'is_active' => true,
        ]);

        $branchB = Branch::create(['name' => 'Sucursal B', 'is_active' => true]);
        $branchBStorage = InventoryLocation::create([
            'branch_id' => $branchB->id,
            'code' => 'BODEGA',
            'name' => 'Bodega de sucursal',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_active' => true,
        ]);

        return [$cd, $branchAStorage, $branchAFloor, $branchBStorage];
    }
}
