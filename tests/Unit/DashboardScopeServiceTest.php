<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DashboardScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Boundary de autorización del alcance analítico del Panel.
 *
 * Verifica las DOS dimensiones que resuelve DashboardScopeService:
 *   A. ORGANIZATION SCOPE  — allowedBranchIds / selectedBranchId /
 *      effectiveWarehouseIds / hasBranch.
 *   B. METRIC VISIBILITY   — canSeeTeam / canSeeFinancialManagement /
 *      personalUserId.
 *
 * Regla central: `record_view` y `is_all_warehouses` NUNCA amplían el alcance
 * ni conceden autoridad; la autoridad de un gerente proviene de ser
 * `branches.manager_employee_id`.
 */
class DashboardScopeServiceTest extends TestCase
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
            $table->integer('manager_employee_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_branches', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->timestamps();
        });

        Schema::create('user_operational_assignments', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('temporary_branch_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    private function service(): DashboardScopeService
    {
        return app(DashboardScopeService::class);
    }

    // --- fixtures -----------------------------------------------------------

    private function branch(string $name, ?int $managerEmployeeId = null, bool $active = true): int
    {
        return DB::table('branches')->insertGetId([
            'name' => $name,
            'type' => 'branch',
            'manager_employee_id' => $managerEmployeeId,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(string $name, ?int $branchId): int
    {
        return DB::table('warehouses')->insertGetId([
            'name' => $name,
            'branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
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
        ], $overrides));

        return User::findOrFail($id);
    }

    private function employee(int $branchId): int
    {
        return DB::table('employees')->insertGetId([
            'branch_id' => $branchId,
            'firstname' => 'Emp',
            'lastname' => 'Loyee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // --- OWNER ------------------------------------------------------------------

    public function test_owner_sees_whole_business_consolidated(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $w1 = $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);
        $wNoBranch = $this->warehouse('Legacy', null);

        $owner = $this->user(['role_id' => 1]);

        $scope = $this->service()->resolve($owner, 0);

        $this->assertTrue($scope->isOwner);
        $this->assertTrue($scope->hasBranch);
        $this->assertTrue($scope->consolidatedView);
        $this->assertTrue($scope->canSeeTeam);
        $this->assertTrue($scope->canSeeFinancialManagement);
        $this->assertEqualsCanonicalizing([$b1, $b5], $scope->allowedBranchIds);
        // "Todas" para el owner incluye almacenes sin branch_id (histórico).
        $this->assertEqualsCanonicalizing([$w1, $w5, $wNoBranch], $scope->effectiveWarehouseIds);
    }

    public function test_owner_selecting_a_branch_limits_effective_warehouses_to_that_branch(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $this->warehouse('W1', $b1);
        $w5a = $this->warehouse('W5a', $b5);
        $w5b = $this->warehouse('W5b', $b5);

        $owner = $this->user(['role_id' => 1]);

        $scope = $this->service()->resolve($owner, $b5);

        $this->assertSame($b5, $scope->selectedBranchId);
        $this->assertFalse($scope->consolidatedView);
        $this->assertEqualsCanonicalizing([$w5a, $w5b], $scope->effectiveWarehouseIds);
    }

    // --- GERENTE -------------------------------------------------------------

    public function test_manager_scope_is_only_their_branches_with_team_and_financial_authority(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $w5 = $this->warehouse('W5', $b5);
        $this->warehouse('W1', $b1);

        $emp = $this->employee($b5);
        DB::table('branches')->where('id', $b5)->update(['manager_employee_id' => $emp]);
        $manager = $this->user(['role_id' => 2, 'employee_id' => $emp]);

        $scope = $this->service()->resolve($manager, 0);

        $this->assertFalse($scope->isOwner);
        $this->assertTrue($scope->hasBranch);
        $this->assertSame([$b5], $scope->allowedBranchIds);
        $this->assertSame([$b5], $scope->managedBranchIds);
        $this->assertSame([$w5], $scope->effectiveWarehouseIds);
        $this->assertTrue($scope->canSeeTeam);
        $this->assertTrue($scope->canSeeFinancialManagement);
    }

    public function test_manager_never_sees_an_unmanaged_branch_even_if_requested(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        $emp = $this->employee($b5);
        DB::table('branches')->where('id', $b5)->update(['manager_employee_id' => $emp]);
        $manager = $this->user(['role_id' => 2, 'employee_id' => $emp]);

        // pide explícitamente la sucursal 1 (que NO gestiona)
        $scope = $this->service()->resolve($manager, $b1);

        $this->assertSame(0, $scope->selectedBranchId, 'branch_id fuera de alcance debe ignorarse');
        $this->assertSame([$b5], $scope->allowedBranchIds);
        $this->assertSame([$w5], $scope->effectiveWarehouseIds);
        $this->assertNotContains($b1, $scope->allowedBranchIds);
    }

    public function test_manager_with_record_view_false_keeps_team_and_financial_authority(): void
    {
        $b5 = $this->branch('Sucursal 5');
        $this->warehouse('W5', $b5);
        $emp = $this->employee($b5);
        DB::table('branches')->where('id', $b5)->update(['manager_employee_id' => $emp]);

        $manager = $this->user(['role_id' => 2, 'employee_id' => $emp, 'record_view' => 0]);

        $scope = $this->service()->resolve($manager, 0);

        // La autoridad viene de ser manager, NO de record_view.
        $this->assertTrue($scope->canSeeTeam);
        $this->assertTrue($scope->canSeeFinancialManagement);
    }

    public function test_multi_branch_manager_consolidated_covers_all_managed_branches(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $w1 = $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        $emp = $this->employee($b1);
        DB::table('branches')->whereIn('id', [$b1, $b5])->update(['manager_employee_id' => $emp]);
        $manager = $this->user(['role_id' => 2, 'employee_id' => $emp]);

        $scope = $this->service()->resolve($manager, 0);

        $this->assertEqualsCanonicalizing([$b1, $b5], $scope->allowedBranchIds);
        $this->assertEqualsCanonicalizing([$w1, $w5], $scope->effectiveWarehouseIds);
        $this->assertTrue($scope->canSeeTeam);
        $this->assertTrue($scope->canSeeFinancialManagement);
    }

    // --- CAJERO / OPERATIVO -----------------------------------------------

    public function test_cashier_with_default_branch_sees_only_that_branch_no_team_no_financial(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        $cashier = $this->user(['role_id' => 2, 'default_branch_id' => $b5]);

        $scope = $this->service()->resolve($cashier, 0);

        $this->assertTrue($scope->hasBranch);
        $this->assertSame([$b5], $scope->allowedBranchIds);
        $this->assertSame([], $scope->managedBranchIds);
        $this->assertSame([$w5], $scope->effectiveWarehouseIds);
        $this->assertFalse($scope->canSeeTeam);
        $this->assertFalse($scope->canSeeFinancialManagement);
        $this->assertSame($cashier->id, $scope->personalUserId);
    }

    public function test_cashier_with_user_branches_multi_assignment(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $w1 = $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        $cashier = $this->user(['role_id' => 2]);
        DB::table('user_branches')->insert([
            ['user_id' => $cashier->id, 'branch_id' => $b1, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $cashier->id, 'branch_id' => $b5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $scope = $this->service()->resolve($cashier, 0);

        $this->assertEqualsCanonicalizing([$b1, $b5], $scope->allowedBranchIds);
        $this->assertEqualsCanonicalizing([$w1, $w5], $scope->effectiveWarehouseIds);
        $this->assertFalse($scope->canSeeTeam);
        $this->assertFalse($scope->canSeeFinancialManagement);
    }

    public function test_operational_user_without_resolvable_branch_gets_empty_scope(): void
    {
        $b5 = $this->branch('Sucursal 5');
        $this->warehouse('W5', $b5);

        // sin default_branch_id, sin user_branches, sin employee, sin asignación
        $cashier = $this->user(['role_id' => 2]);

        $scope = $this->service()->resolve($cashier, 0);

        $this->assertFalse($scope->hasBranch);
        $this->assertSame([], $scope->allowedBranchIds);
        $this->assertSame([], $scope->effectiveWarehouseIds, 'nunca fallback a todos los almacenes');
        $this->assertFalse($scope->canSeeTeam);
        $this->assertFalse($scope->canSeeFinancialManagement);
    }

    public function test_legacy_is_all_warehouses_does_not_widen_analytic_scope(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        // bandera heredada activada, pero funcionalmente es operativo de la S5
        $cashier = $this->user(['role_id' => 2, 'is_all_warehouses' => 1, 'default_branch_id' => $b5]);

        $scope = $this->service()->resolve($cashier, 0);

        $this->assertSame([$b5], $scope->allowedBranchIds, 'is_all_warehouses no da acceso a todas las sucursales');
        $this->assertSame([$w5], $scope->effectiveWarehouseIds);
        $this->assertFalse($scope->canSeeFinancialManagement);
    }

    public function test_record_view_true_on_cashier_does_not_widen_scope_or_grant_authority(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        $cashier = $this->user(['role_id' => 2, 'record_view' => 1, 'default_branch_id' => $b5]);

        $scope = $this->service()->resolve($cashier, 0);

        $this->assertSame([$b5], $scope->allowedBranchIds);
        $this->assertSame([$w5], $scope->effectiveWarehouseIds);
        $this->assertFalse($scope->canSeeTeam, 'record_view no habilita TEAM');
        $this->assertFalse($scope->canSeeFinancialManagement, 'record_view no habilita FINANCIAL');
    }

    public function test_manipulated_out_of_scope_branch_id_is_ignored(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $w1 = $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        $cashier = $this->user(['role_id' => 2, 'default_branch_id' => $b5]);

        // cajero de S5 manipula branch_id=1
        $scope = $this->service()->resolve($cashier, $b1);

        $this->assertSame(0, $scope->selectedBranchId);
        $this->assertSame([$w5], $scope->effectiveWarehouseIds);
        $this->assertNotContains($w1, $scope->effectiveWarehouseIds);
    }

    // --- HÍBRIDO -----------------------------------------------------------

    public function test_hybrid_manager_of_one_branch_operational_in_another(): void
    {
        $b1 = $this->branch('Sucursal 1');
        $b5 = $this->branch('Sucursal 5');
        $w1 = $this->warehouse('W1', $b1);
        $w5 = $this->warehouse('W5', $b5);

        $emp = $this->employee($b5);
        DB::table('branches')->where('id', $b5)->update(['manager_employee_id' => $emp]);
        // gestiona S5, pero además es operativo de S1
        $user = $this->user(['role_id' => 2, 'employee_id' => $emp, 'default_branch_id' => $b1]);

        // Vista de la sucursal que gestiona: autoridad plena.
        $onS5 = $this->service()->resolve($user, $b5);
        $this->assertTrue($onS5->canSeeTeam);
        $this->assertTrue($onS5->canSeeFinancialManagement);
        $this->assertSame([$w5], $onS5->effectiveWarehouseIds);

        // Vista de la sucursal donde sólo opera: sin autoridad.
        $onS1 = $this->service()->resolve($user, $b1);
        $this->assertFalse($onS1->canSeeTeam);
        $this->assertFalse($onS1->canSeeFinancialManagement);
        $this->assertSame([$w1], $onS1->effectiveWarehouseIds);

        // Vista consolidada: no gestiona TODO el alcance => criterio de menor
        // exposición, sin autoridad.
        $all = $this->service()->resolve($user, 0);
        $this->assertEqualsCanonicalizing([$b1, $b5], $all->allowedBranchIds);
        $this->assertFalse($all->canSeeTeam);
        $this->assertFalse($all->canSeeFinancialManagement);
    }

    public function test_inactive_managed_branch_is_excluded(): void
    {
        $b5 = $this->branch('Sucursal 5 (cerrada)', null, false);
        $this->warehouse('W5', $b5);
        $emp = $this->employee($b5);
        DB::table('branches')->where('id', $b5)->update(['manager_employee_id' => $emp]);

        $manager = $this->user(['role_id' => 2, 'employee_id' => $emp]);
        $scope = $this->service()->resolve($manager, 0);

        $this->assertSame([], $scope->allowedBranchIds);
        $this->assertFalse($scope->hasBranch);
    }
}
