<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\WarehouseScopeService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseScopeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('mobile')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('email')->nullable();
            $table->string('zip')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->integer('role_id')->nullable();
            $table->integer('statut')->default(1);
            $table->integer('is_all_warehouses')->default(0);
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('default_cash_drawer_id')->nullable();
            $table->boolean('record_view')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_warehouse', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('warehouse_id');
            $table->timestamps();
        });

        Schema::create('user_operational_assignments', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->integer('default_warehouse_id_snapshot')->nullable();
            $table->string('default_warehouse_name_snapshot')->nullable();
            $table->integer('default_cash_drawer_id_snapshot')->nullable();
            $table->string('default_cash_drawer_name_snapshot')->nullable();
            $table->integer('temporary_warehouse_id')->nullable();
            $table->string('temporary_warehouse_name_snapshot')->nullable();
            $table->integer('temporary_cash_drawer_id')->nullable();
            $table->string('temporary_cash_drawer_name_snapshot')->nullable();
            $table->integer('assigned_by_user_id')->nullable();
            $table->string('assigned_by_user_name_snapshot')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function test_restricted_user_only_sees_assigned_warehouses(): void
    {
        $a = Warehouse::create(['name' => 'Sucursal A']);
        $b = Warehouse::create(['name' => 'Sucursal B']);
        $user = User::create([
            'username' => 'operator',
            'email' => 'operator@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $a->id]);

        $scope = app(WarehouseScopeService::class);

        $this->assertSame([$a->id], $scope->allowedWarehouseIds($user));
        $this->assertTrue($scope->canAccess($user, $a->id));
        $this->assertFalse($scope->canAccess($user, $b->id));
    }

    public function test_temporary_assignment_is_added_to_effective_scope(): void
    {
        $home = Warehouse::create(['name' => 'Principal']);
        $temporary = Warehouse::create(['name' => 'Cobertura']);
        $user = User::create([
            'username' => 'operator',
            'email' => 'temp@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $home->id]);
        UserOperationalAssignment::create([
            'user_id' => $user->id,
            'temporary_warehouse_id' => $temporary->id,
            'temporary_warehouse_name_snapshot' => $temporary->name,
            'starts_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->addHour(),
            'status' => UserOperationalAssignment::STATUS_ACTIVE,
        ]);

        $ids = app(WarehouseScopeService::class)->allowedWarehouseIds($user);
        sort($ids);
        $expected = [$home->id, $temporary->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_assert_access_rejects_other_branch_warehouse(): void
    {
        $allowed = Warehouse::create(['name' => 'Permitida']);
        $blocked = Warehouse::create(['name' => 'Bloqueada']);
        $user = User::create([
            'username' => 'operator',
            'email' => 'blocked@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $allowed->id]);

        $this->expectException(AuthorizationException::class);
        app(WarehouseScopeService::class)->assertAccess($user, $blocked->id);
    }

    public function test_global_user_can_access_all_active_warehouses(): void
    {
        $a = Warehouse::create(['name' => 'A']);
        $b = Warehouse::create(['name' => 'B']);
        $user = User::create([
            'username' => 'owner',
            'email' => 'owner@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 1,
        ]);

        $scope = app(WarehouseScopeService::class);
        $ids = $scope->allowedWarehouseIds($user);

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }
}
