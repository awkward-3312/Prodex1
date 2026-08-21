<?php

namespace Tests\Unit;

use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Services\BranchScopeService;
use App\Services\InventoryLocationScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchInventoryScopeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->integer('employee_id')->nullable();
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

        Schema::create('employees', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type')->default('branch');
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

        Schema::create('user_warehouse', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('warehouse_id');
        });

        Schema::create('user_branches', function ($table) {
            $table->increments('id');
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
            $table->string('type')->default('storage');
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_inventory_locations', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('inventory_location_id');
            $table->timestamps();
        });

        Schema::create('user_operational_assignments', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('temporary_branch_id')->nullable();
            $table->integer('temporary_inventory_location_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_explicit_branch_scope_overrides_legacy_all_warehouses_flag(): void
    {
        $branchA = $this->branch('A');
        $this->branch('B');
        $user = $this->user(['is_all_warehouses' => 1]);
        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([$branchA], app(BranchScopeService::class)->allowedBranchIds($user));
    }

    public function test_default_branch_is_used_when_no_explicit_scope_exists(): void
    {
        $branch = $this->branch('Centro');
        $user = $this->user(['default_branch_id' => $branch]);

        $this->assertSame([$branch], app(BranchScopeService::class)->allowedBranchIds($user));
    }

    public function test_legacy_user_warehouse_mapping_is_only_a_fallback(): void
    {
        $branch = $this->branch('Mall');
        $warehouse = DB::table('warehouses')->insertGetId(['branch_id' => $branch, 'name' => 'Legacy Mall', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->user();
        DB::table('user_warehouse')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse]);

        $this->assertSame([$branch], app(BranchScopeService::class)->allowedBranchIds($user));
    }

    public function test_temporary_assignment_adds_temporary_branch_and_location(): void
    {
        $branchA = $this->branch('Centro');
        $branchB = $this->branch('Mall');
        $locationA = $this->location($branchA, 'PISO-A', true);
        $locationB = $this->location($branchB, 'PISO-B', true);
        $user = $this->user(['default_branch_id' => $branchA, 'default_inventory_location_id' => $locationA]);

        UserOperationalAssignment::create([
            'user_id' => $user->id,
            'temporary_branch_id' => $branchB,
            'temporary_inventory_location_id' => $locationB,
            'status' => UserOperationalAssignment::STATUS_ACTIVE,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);

        $branchIds = app(BranchScopeService::class)->allowedBranchIds($user);
        sort($branchIds);
        $expectedBranches = [$branchA, $branchB];
        sort($expectedBranches);
        $this->assertSame($expectedBranches, $branchIds);

        $locationIds = app(InventoryLocationScopeService::class)->allowedLocationIds($user);
        sort($locationIds);
        $expectedLocations = [$locationA, $locationB];
        sort($expectedLocations);
        $this->assertSame($expectedLocations, $locationIds);
    }

    public function test_branch_default_sales_location_is_safe_fallback_for_unconfigured_user(): void
    {
        $branch = $this->branch('Norte');
        $location = $this->location($branch, 'PISO', true);
        DB::table('branches')->where('id', $branch)->update(['default_inventory_location_id' => $location]);
        $user = $this->user(['default_branch_id' => $branch]);

        $this->assertSame([$location], app(InventoryLocationScopeService::class)->allowedLocationIds($user));
    }

    public function test_explicit_inventory_locations_limit_operational_inventory_scope(): void
    {
        $branch = $this->branch('Sur');
        $floor = $this->location($branch, 'PISO', true);
        $storage = $this->location($branch, 'BODEGA', false);
        $user = $this->user(['default_branch_id' => $branch, 'default_inventory_location_id' => $floor]);
        DB::table('user_inventory_locations')->insert(['user_id' => $user->id, 'inventory_location_id' => $storage, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([$storage], app(InventoryLocationScopeService::class)->allowedLocationIds($user));
    }

    private function branch(string $name): int
    {
        return DB::table('branches')->insertGetId([
            'name' => $name,
            'type' => 'branch',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function location(int $branchId, string $code, bool $sellable): int
    {
        return DB::table('inventory_locations')->insertGetId([
            'branch_id' => $branchId,
            'warehouse_id' => null,
            'code' => $code,
            'name' => $code,
            'type' => $sellable ? InventoryLocation::TYPE_SALES_FLOOR : InventoryLocation::TYPE_STORAGE,
            'is_sellable' => $sellable,
            'is_default_sales' => $sellable,
            'is_quarantine' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function user(array $overrides = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => uniqid('user').'@test.local',
            'password' => 'x',
            'role_id' => 2,
            'is_all_warehouses' => 0,
            'record_view' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ], $overrides));

        return User::findOrFail($id);
    }
}
