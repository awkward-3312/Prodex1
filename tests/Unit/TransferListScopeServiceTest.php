<?php

namespace Tests\Unit;

use App\Models\Transfer;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferListScopeService;
use App\Services\WarehouseScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TransferListScopeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('inventory_locations', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->integer('from_warehouse_id')->nullable();
            $table->integer('to_warehouse_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_modern_transfer_list_does_not_leak_through_shared_legacy_warehouse(): void
    {
        DB::table('transfers')->insert([
            [
                'id' => 100,
                'from_warehouse_id' => 1,
                'to_warehouse_id' => 1,
                'from_inventory_location_id' => 30,
                'to_inventory_location_id' => 10,
                'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
            ],
            [
                'id' => 101,
                'from_warehouse_id' => 1,
                'to_warehouse_id' => 1,
                'from_inventory_location_id' => 30,
                'to_inventory_location_id' => 20,
                'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
            ],
            [
                'id' => 102,
                'from_warehouse_id' => 2,
                'to_warehouse_id' => 1,
                'from_inventory_location_id' => null,
                'to_inventory_location_id' => null,
                'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
            ],
        ]);

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 77;
        $user->is_all_warehouses = 0;

        $locationScope = Mockery::mock(InventoryLocationScopeService::class);
        $locationScope->shouldReceive('allowedLocationIds')->with($user)->andReturn([10]);
        $this->app->instance(InventoryLocationScopeService::class, $locationScope);

        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('allowedWarehouseIds')->with($user)->andReturn([1]);
        $this->app->instance(WarehouseScopeService::class, $warehouseScope);

        $query = Transfer::query()->whereNull('deleted_at');
        app(TransferListScopeService::class)->apply($query, $user);

        $this->assertSame([100, 102], $query->orderBy('id')->pluck('id')->all());
    }

    public function test_global_warehouse_user_keeps_broad_access_to_modern_locations(): void
    {
        DB::table('inventory_locations')->insert([
            ['id' => 10, 'name' => 'Sucursal A', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['id' => 20, 'name' => 'Sucursal B', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
            ['id' => 30, 'name' => 'Origen', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null],
        ]);

        DB::table('transfers')->insert([
            [
                'id' => 200,
                'from_warehouse_id' => 1,
                'to_warehouse_id' => 1,
                'from_inventory_location_id' => 30,
                'to_inventory_location_id' => 10,
                'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
            ],
            [
                'id' => 201,
                'from_warehouse_id' => 1,
                'to_warehouse_id' => 1,
                'from_inventory_location_id' => 30,
                'to_inventory_location_id' => 20,
                'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
            ],
        ]);

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 88;
        $user->role_id = 2;
        $user->is_all_warehouses = 1;

        $locationScope = Mockery::mock(InventoryLocationScopeService::class);
        $locationScope->shouldNotReceive('allowedLocationIds');
        $this->app->instance(InventoryLocationScopeService::class, $locationScope);

        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('allowedWarehouseIds')->with($user)->andReturn([1]);
        $this->app->instance(WarehouseScopeService::class, $warehouseScope);

        $query = Transfer::query()->whereNull('deleted_at');
        app(TransferListScopeService::class)->apply($query, $user);

        $this->assertSame([200, 201], $query->orderBy('id')->pluck('id')->all());
    }

    public function test_source_options_stay_on_warehouses_during_partial_transfer_schema_rollout(): void
    {
        Schema::drop('transfers');
        Schema::create('transfers', function ($table) {
            $table->increments('id');
            $table->integer('from_warehouse_id')->nullable();
            $table->integer('to_warehouse_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('inventory_locations')->insert([
            'id' => 10,
            'name' => 'Ubicación moderna todavía no utilizable',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 99;
        $user->is_all_warehouses = 0;

        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('visibleWarehouses')->with($user)->andReturn(collect([
            (object) ['id' => 1, 'name' => 'CD Legacy'],
        ]));
        $this->app->instance(WarehouseScopeService::class, $warehouseScope);

        $options = app(TransferListScopeService::class)->sourceOptions($user)->values()->all();

        $this->assertSame([
            ['id' => 1, 'name' => 'CD Legacy'],
        ], $options);
    }
}
