<?php

namespace Tests\Unit;

use App\Http\Middleware\EnforceWarehouseScope;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\WarehouseScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regresión: el Dashboard px-next (resources/src/views/app/dashboard/next/index.vue)
 * pedía GET /api/dashboard_data?warehouse_id=0 en su primera carga. Para un
 * usuario no-owner, EnforceWarehouseScope trataba "0" como una bodega real,
 * lanzaba AuthorizationException (403) y el interceptor de axios llevaba toda la
 * SPA a not_authorize.
 *
 * "0" (y cualquier valor no positivo) es el centinela "todas / ninguna" de toda
 * la app; NO es una bodega. Sólo un warehouse_id > 0 es una selección real y se
 * valida por alcance.
 */
class DashboardWarehouseScopeSelectorTest extends TestCase
{
    private function user(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 501;
        $user->is_all_warehouses = 0; // cajero / gerente restringido
        return $user;
    }

    private function makeGet(string $uri): Request
    {
        return Request::create($uri, 'GET');
    }

    private function invoke(Request $request, User $user, WarehouseScopeService $warehouseScope): void
    {
        $method = new ReflectionMethod(EnforceWarehouseScope::class, 'validateRequestSelectors');
        $method->invoke(
            app(EnforceWarehouseScope::class),
            $request,
            $user,
            $warehouseScope,
            Mockery::mock(InventoryLocationScopeService::class),
            false, // locationTransfer
            false  // locationPosSale
        );
    }

    public function test_dashboard_data_with_warehouse_id_zero_does_not_assert_scope(): void
    {
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('assertAccess')->never();

        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=0&from=2026-01-01&to=2026-01-07'), $user, $warehouseScope);
        $this->addToAssertionCount(1);
    }

    public function test_dashboard_data_without_warehouse_id_does_not_assert_scope(): void
    {
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('assertAccess')->never();

        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=&from=2026-01-01&to=2026-01-07'), $user, $warehouseScope);
        $this->addToAssertionCount(1);
    }

    public function test_negative_warehouse_id_does_not_assert_scope(): void
    {
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('assertAccess')->never();

        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=-1'), $user, $warehouseScope);
        $this->addToAssertionCount(1);
    }

    public function test_real_warehouse_id_still_asserts_scope_and_rejects_when_not_allowed(): void
    {
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('assertAccess')
            ->once()
            ->with($user, 5, Mockery::type('string'))
            ->andThrow(new AuthorizationException('No tienes permiso para consultar esa bodega.'));

        $this->expectException(AuthorizationException::class);
        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=5&from=2026-01-01&to=2026-01-07'), $user, $warehouseScope);
    }

    public function test_real_warehouse_id_passes_when_allowed(): void
    {
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        // Un id real (>0) sí se valida por alcance (aquí no lanza => permitido).
        $warehouseScope->shouldReceive('assertAccess')->atLeast()->once()->with($user, 5, Mockery::type('string'));

        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=5'), $user, $warehouseScope);
        $this->addToAssertionCount(1);
    }

    public function test_px_next_dashboard_omits_warehouse_id_when_zero(): void
    {
        $src = file_get_contents(base_path('resources/src/views/app/dashboard/next/index.vue'));
        // No debe volver a mandar warehouse_id: 0 incondicionalmente.
        $this->assertStringNotContainsString('params: { warehouse_id: this.warehouseId, from: this.dateFrom, to: this.dateTo }', $src);
        $this->assertStringContainsString('if (this.warehouseId) params.warehouse_id = this.warehouseId;', $src);
    }

    public function test_middleware_treats_non_positive_warehouse_selector_as_no_selection(): void
    {
        $src = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));
        $this->assertStringContainsString('if ((int) $value <= 0) return;', $src);
        $this->assertStringContainsString('(int) $request->query(\'warehouse_id\') > 0', $src);
    }

    /**
     * El fix NO amplía la visibilidad de datos: el dashboard sigue aplicando el
     * alcance por sucursal/almacén al no-owner (warehouse_id ausente/0 => "todo
     * dentro de MI alcance", nunca "todo el tenant").
     */
    public function test_dashboard_still_scopes_data_by_branch_and_warehouse(): void
    {
        $ctrl = file_get_contents(base_path('app/Http/Controllers/OperationalDashboardController.php'));
        $this->assertStringContainsString('$scope->apply($base, $user, \'sales\', $warehouseId, $branchId)', $ctrl);
        $this->assertStringContainsString('$scope->applyRecordVisibility($base, $user, \'sales\')', $ctrl);

        $scope = file_get_contents(base_path('app/Services/SalesReportingScopeService.php'));
        // Non-owner sin selector => se limita a sus branchIds / warehouseIds.
        $this->assertStringContainsString('$branchIds = $this->allowedBranchIds($user);', $scope);
        $this->assertStringContainsString('$warehouseIds = $this->allowedWarehouseIds($user);', $scope);
        $this->assertStringContainsString("\$q->whereRaw('1 = 0');", $scope);
    }
}
