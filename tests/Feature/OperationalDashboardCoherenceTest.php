<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationalDashboardController;
use App\Support\DashboardScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Dashboard must be internally coherent: for the same records, "Mis ventas",
 * "Ventas de sucursal", la gráfica, el nº de facturas y "recientes" have to line
 * up.
 *
 * The bug: OperationalDashboardController recomputed every sales widget from
 * branch_id EXCEPT my_sales, which the parent still filtered by warehouse_id —
 * so a modern POS sale (branch_id set, warehouse_id NULL) showed in "recientes"
 * and in the branch total, but "Mis ventas" stayed at 0.
 *
 * This exercises the exact fix: recomputeMySales() + applySaleScope() resolve
 * "Mis ventas" by branch, with warehouse_id only as the legacy fallback.
 */
class OperationalDashboardCoherenceTest extends TestCase
{
    private TestableOperationalDashboard $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->controller = new TestableOperationalDashboard;
    }

    public function test_my_sales_counts_modern_branch_sales_the_parent_would_miss(): void
    {
        $branchId = $this->branch('Sucursal 2');
        $warehouseId = $this->warehouse('WH2', $branchId);

        // 4 modern POS sales by the cashier: branch_id set, warehouse_id NULL.
        foreach (['SL_0021', 'SL_0022', 'SL_0023', 'SL_0024'] as $ref) {
            $this->sale($ref, 7, $branchId, null, 100, 100);
        }
        // Another cashier, same branch — part of the branch total, not "mine".
        $this->sale('SL_0099', 8, $branchId, null, 250, 250);
        // A legacy sale with branch_id NULL, resolvable only by warehouse_id.
        $this->sale('SL_LEG', 7, null, $warehouseId, 40, 40);

        $scope = $this->scope(personalUserId: 7, effectiveBranchIds: [$branchId], effectiveWarehouseIds: [$warehouseId]);
        $mine = $this->controller->recomputeMySales($scope, $this->windowFrom(), $this->windowTo());
        $branchTotal = $this->branchTotal($scope);

        // Mis ventas = las 4 modernas + la legacy del propio cajero = 5 / 440.
        $this->assertSame(440.0, $mine['total']);
        $this->assertSame(5, $mine['invoices']);
        $this->assertSame(0.0, $mine['due']);

        // Coherencia: "Mis ventas" nunca supera "Ventas de sucursal" (690 / 6).
        $this->assertSame(690.0, $branchTotal['total']);
        $this->assertSame(6, $branchTotal['invoices']);
        $this->assertLessThanOrEqual($branchTotal['total'], $mine['total']);
    }

    public function test_my_sales_is_empty_for_a_cashier_outside_the_branch_scope(): void
    {
        $b2 = $this->branch('Sucursal 2');
        $b3 = $this->branch('Sucursal 3');
        $this->sale('SL_B2', 7, $b2, null, 100, 100);

        $scope = $this->scope(personalUserId: 7, effectiveBranchIds: [$b3], effectiveWarehouseIds: []);
        $mine = $this->controller->recomputeMySales($scope, $this->windowFrom(), $this->windowTo());

        $this->assertSame(0.0, $mine['total']);
        $this->assertSame(0, $mine['invoices']);
    }

    public function test_all_company_scope_counts_the_cashier_sales_across_every_branch(): void
    {
        $b2 = $this->branch('Sucursal 2');
        $b3 = $this->branch('Sucursal 3');
        $this->sale('SL_B2', 7, $b2, null, 100, 100);
        $this->sale('SL_B3', 7, $b3, null, 60, 60);
        $this->sale('SL_B3_OTHER', 8, $b3, null, 5000, 5000);

        // "Toda la empresa" => effectiveBranchIds = every active branch.
        $scope = $this->scope(personalUserId: 7, effectiveBranchIds: [$b2, $b3], effectiveWarehouseIds: []);
        $mine = $this->controller->recomputeMySales($scope, $this->windowFrom(), $this->windowTo());

        $this->assertSame(160.0, $mine['total']);
        $this->assertSame(2, $mine['invoices']);
    }

    public function test_a_sale_outside_the_window_is_excluded_from_my_sales(): void
    {
        $b2 = $this->branch('Sucursal 2');
        $this->sale('SL_IN', 7, $b2, null, 100, 100, today()->toDateString());
        // Stamped tomorrow — what a UTC-skewed evening sale looks like.
        $this->sale('SL_TOMORROW', 7, $b2, null, 999, 999, today()->addDay()->toDateString());

        $scope = $this->scope(personalUserId: 7, effectiveBranchIds: [$b2], effectiveWarehouseIds: []);
        $mine = $this->controller->recomputeMySales($scope, $this->windowFrom(), $this->windowTo());

        $this->assertSame(100.0, $mine['total']);
        $this->assertSame(1, $mine['invoices']);
    }

    // --- helpers -------------------------------------------------------------

    private function windowFrom(): string
    {
        return today()->subDays(6)->toDateString();
    }

    private function windowTo(): string
    {
        return today()->toDateString();
    }

    private function branchTotal(DashboardScope $scope): array
    {
        $q = DB::table('sales')->whereNull('deleted_at')
            ->whereBetween('date', [$this->windowFrom(), $this->windowTo()]);
        $this->controller->applySaleScope($q, $scope);
        $row = $q->selectRaw('COALESCE(SUM(GrandTotal),0) total, COUNT(*) invoices')->first();

        return ['total' => (float) $row->total, 'invoices' => (int) $row->invoices];
    }

    private function scope(int $personalUserId, array $effectiveBranchIds, array $effectiveWarehouseIds): DashboardScope
    {
        return new DashboardScope(
            isOwner: false,
            managedBranchIds: [],
            operationalBranchIds: [],
            allowedBranchIds: $effectiveBranchIds,
            selectedBranchId: 0,
            effectiveBranchIds: $effectiveBranchIds,
            effectiveWarehouseIds: $effectiveWarehouseIds,
            personalUserId: $personalUserId,
            canSeeTeam: false,
            canSeeFinancialManagement: false,
            consolidatedView: true,
            hasBranch: true,
        );
    }

    private function branch(string $name): int
    {
        return DB::table('branches')->insertGetId([
            'name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function warehouse(string $name, int $branchId): int
    {
        return DB::table('warehouses')->insertGetId([
            'name' => $name, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sale(string $ref, int $userId, ?int $branchId, ?int $warehouseId, float $grand, float $paid, ?string $date = null): int
    {
        return DB::table('sales')->insertGetId([
            'date' => $date ?? today()->toDateString(), 'time' => '12:00:00', 'Ref' => $ref, 'is_pos' => 1,
            'user_id' => $userId, 'branch_id' => $branchId, 'inventory_location_id' => null,
            'cash_drawer_id' => null, 'warehouse_id' => $warehouseId,
            'statut' => 'completed', 'payment_statut' => 'paid',
            'GrandTotal' => $grand, 'paid_amount' => $paid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('branches', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('sales', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->string('time')->nullable();
            $t->string('Ref')->nullable();
            $t->tinyInteger('is_pos')->default(1);
            $t->integer('user_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('branch_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->integer('cash_drawer_id')->nullable();
            $t->string('statut')->nullable();
            $t->string('payment_statut')->nullable();
            $t->decimal('GrandTotal', 15, 2)->default(0);
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }
}

/**
 * Exposes the two protected methods under test without booting the whole
 * MySQL-coupled parent controller.
 */
class TestableOperationalDashboard extends OperationalDashboardController
{
    public function recomputeMySales(DashboardScope $scope, string $from, string $to): array
    {
        return parent::recomputeMySales($scope, $from, $to);
    }

    public function applySaleScope($query, DashboardScope $scope, string $alias = 'sales')
    {
        return parent::applySaleScope($query, $scope, $alias);
    }
}
