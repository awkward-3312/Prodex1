<?php

namespace Tests\Unit;

use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchTransferReceivingScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->integer('role_id')->default(2);
            $table->integer('is_all_warehouses')->default(0);
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('default_branch_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->integer('default_cash_drawer_id')->nullable();
            $table->boolean('record_view')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('permissions', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('role_user', function ($table) {
            $table->integer('role_id');
            $table->integer('user_id');
        });
        Schema::create('permission_role', function ($table) {
            $table->integer('permission_id');
            $table->integer('role_id');
        });
        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('default_inventory_location_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_branches', function ($table) {
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->timestamps();
        });
        Schema::create('inventory_locations', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_inventory_locations', function ($table) {
            $table->integer('user_id');
            $table->integer('inventory_location_id');
            $table->timestamps();
        });
    }

    public function test_branch_receiver_gets_primary_storage_only_for_receiving(): void
    {
        $roleId = DB::table('roles')->insertGetId(['name' => 'Gerente', 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'transfer_receive', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['permission_id' => $permissionId, 'role_id' => $roleId]);

        $branchId = DB::table('branches')->insertGetId(['name' => 'Sucursal 1', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $floorId = $this->location($branchId, 'PISO', InventoryLocation::TYPE_SALES_FLOOR, true);
        $storageId = $this->location($branchId, 'BODEGA', InventoryLocation::TYPE_STORAGE, false);
        $secondaryStorageId = $this->location($branchId, 'BODEGA-2', InventoryLocation::TYPE_STORAGE, false);

        $userId = DB::table('users')->insertGetId([
            'firstname' => 'Gerente', 'email' => 'gerente@test.local', 'password' => 'x',
            'role_id' => $roleId, 'default_branch_id' => $branchId,
            'default_inventory_location_id' => $floorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
        DB::table('user_branches')->insert(['user_id' => $userId, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_inventory_locations')->insert(['user_id' => $userId, 'inventory_location_id' => $floorId, 'created_at' => now(), 'updated_at' => now()]);

        $user = User::findOrFail($userId);
        $scope = app(InventoryLocationScopeService::class);

        $this->assertSame([$floorId], $scope->allowedLocationIds($user));

        $receiving = $scope->receivingLocationIds($user);
        sort($receiving);
        $expected = [$floorId, $storageId];
        sort($expected);

        $this->assertSame($expected, $receiving);
        $this->assertTrue($scope->canReceiveAt($user, $storageId));
        $this->assertFalse($scope->canAccess($user, $storageId));
        $this->assertFalse($scope->canReceiveAt($user, $secondaryStorageId));
    }

    private function location(int $branchId, string $code, string $type, bool $sellable): int
    {
        return DB::table('inventory_locations')->insertGetId([
            'branch_id' => $branchId,
            'warehouse_id' => null,
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'is_sellable' => $sellable,
            'is_default_sales' => $type === InventoryLocation::TYPE_SALES_FLOOR,
            'is_quarantine' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
