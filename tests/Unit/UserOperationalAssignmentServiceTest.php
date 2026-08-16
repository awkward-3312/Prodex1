<?php

namespace Tests\Unit;

use App\Models\CashDrawer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\UserOperationalAssignmentService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserOperationalAssignmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperationalTables();
    }

    public function test_default_assignment_resolves_user_warehouse_and_cash_drawer(): void
    {
        [$warehouse, $drawer] = $this->warehouseWithDrawer('Principal', 'Caja 01');
        $user = User::create([
            'firstname' => 'Betzabe',
            'lastname' => 'Escobar',
            'username' => 'betzabe',
            'email' => 'betzabe@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
            'default_warehouse_id' => $warehouse->id,
            'default_cash_drawer_id' => $drawer->id,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $warehouse->id]);

        $assignment = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);

        $this->assertSame('default', $assignment['source']);
        $this->assertSame($warehouse->id, $assignment['warehouse_id']);
        $this->assertSame($drawer->id, $assignment['cash_drawer_id']);
        $this->assertFalse($assignment['can_override']);
    }

    public function test_active_temporary_assignment_overrides_default(): void
    {
        [$defaultWarehouse, $defaultDrawer] = $this->warehouseWithDrawer('Principal', 'Caja 01');
        [$temporaryWarehouse, $temporaryDrawer] = $this->warehouseWithDrawer('Sucursal', 'Caja 02');
        $user = User::create([
            'firstname' => 'Cajera',
            'username' => 'cashier',
            'email' => 'cashier@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 1,
            'default_warehouse_id' => $defaultWarehouse->id,
            'default_cash_drawer_id' => $defaultDrawer->id,
        ]);
        UserOperationalAssignment::create([
            'user_id' => $user->id,
            'temporary_warehouse_id' => $temporaryWarehouse->id,
            'temporary_warehouse_name_snapshot' => $temporaryWarehouse->name,
            'temporary_cash_drawer_id' => $temporaryDrawer->id,
            'temporary_cash_drawer_name_snapshot' => $temporaryDrawer->name,
            'starts_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->addHour(),
            'reason' => 'Cobertura',
            'status' => UserOperationalAssignment::STATUS_ACTIVE,
        ]);

        $assignment = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);

        $this->assertSame('temporary', $assignment['source']);
        $this->assertSame($temporaryWarehouse->id, $assignment['warehouse_id']);
        $this->assertSame($temporaryDrawer->id, $assignment['cash_drawer_id']);
    }

    public function test_cashier_cannot_request_another_warehouse_or_drawer_without_override(): void
    {
        [$warehouse, $drawer] = $this->warehouseWithDrawer('Principal', 'Caja 01');
        [$otherWarehouse, $otherDrawer] = $this->warehouseWithDrawer('Sucursal', 'Caja 02');
        $user = User::create([
            'firstname' => 'Cajera',
            'username' => 'cashier',
            'email' => 'cashier2@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
            'default_warehouse_id' => $warehouse->id,
            'default_cash_drawer_id' => $drawer->id,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $warehouse->id]);

        $this->expectException(AuthorizationException::class);

        app(UserOperationalAssignmentService::class)->validateRequestedAssignment($user, $otherWarehouse->id, $otherDrawer->id);
    }

    public function test_effective_assignment_does_not_return_inactive_default_drawer(): void
    {
        [$warehouse, $drawer] = $this->warehouseWithDrawer('Principal', 'Caja 01');
        $drawer->update(['is_active' => false]);
        $user = User::create([
            'firstname' => 'Cajera',
            'username' => 'cashier3',
            'email' => 'cashier3@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
            'default_warehouse_id' => $warehouse->id,
            'default_cash_drawer_id' => $drawer->id,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $warehouse->id]);

        $assignment = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);

        $this->assertSame($warehouse->id, $assignment['warehouse_id']);
        $this->assertNull($assignment['cash_drawer_id']);
    }

    public function test_validation_requires_cash_drawer_for_pos_sale(): void
    {
        [$warehouse] = $this->warehouseWithDrawer('Principal', 'Caja 01');
        $user = User::create([
            'firstname' => 'Cajera',
            'username' => 'cashier4',
            'email' => 'cashier4@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
            'default_warehouse_id' => $warehouse->id,
            'default_cash_drawer_id' => null,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $warehouse->id]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(UserOperationalAssignmentService::class)->validateRequestedAssignment($user, $warehouse->id, null);
    }

    public function test_override_permission_allows_requesting_any_active_drawer(): void
    {
        [$warehouse, $drawer] = $this->warehouseWithDrawer('Principal', 'Caja 01');
        [$otherWarehouse, $otherDrawer] = $this->warehouseWithDrawer('Sucursal', 'Caja 02');
        $user = User::create([
            'firstname' => 'Supervisor',
            'username' => 'supervisor',
            'email' => 'supervisor@example.test',
            'password' => 'secret',
            'is_all_warehouses' => 0,
            'default_warehouse_id' => $warehouse->id,
            'default_cash_drawer_id' => $drawer->id,
        ]);
        UserWarehouse::create(['user_id' => $user->id, 'warehouse_id' => $warehouse->id]);
        $this->grantPermission($user, 'cash_register_override_assignment');

        app(UserOperationalAssignmentService::class)->validateRequestedAssignment($user, $otherWarehouse->id, $otherDrawer->id);

        $this->assertTrue($user->hasPermissionName('cash_register_override_assignment'));
    }

    private function warehouseWithDrawer(string $warehouseName, string $drawerName): array
    {
        $warehouse = Warehouse::create(['name' => $warehouseName]);
        $drawer = CashDrawer::create([
            'warehouse_id' => $warehouse->id,
            'name' => $drawerName,
            'code' => strtoupper(str_replace(' ', '-', $warehouseName.'-'.$drawerName)),
            'is_active' => true,
        ]);

        return [$warehouse, $drawer];
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $role = Role::create(['name' => 'Supervisor']);
        $permission = Permission::create(['name' => $permissionName]);
        DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
        DB::table('permission_role')->insert(['permission_id' => $permission->id, 'role_id' => $role->id]);
    }

    private function createOperationalTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->integer('is_all_warehouses')->default(0);
            $table->unsignedInteger('default_warehouse_id')->nullable();
            $table->unsignedInteger('default_cash_drawer_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('cash_drawers', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('warehouse_id');
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
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
            $table->unsignedInteger('default_warehouse_id_snapshot')->nullable();
            $table->string('default_warehouse_name_snapshot')->nullable();
            $table->unsignedInteger('default_cash_drawer_id_snapshot')->nullable();
            $table->string('default_cash_drawer_name_snapshot')->nullable();
            $table->unsignedInteger('temporary_warehouse_id');
            $table->string('temporary_warehouse_name_snapshot');
            $table->unsignedInteger('temporary_cash_drawer_id');
            $table->string('temporary_cash_drawer_name_snapshot');
            $table->unsignedInteger('assigned_by_user_id')->nullable();
            $table->string('assigned_by_user_name_snapshot')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default(UserOperationalAssignment::STATUS_ACTIVE);
            $table->timestamps();
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
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('role_id');
        });
        Schema::create('permission_role', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
        });
    }
}
