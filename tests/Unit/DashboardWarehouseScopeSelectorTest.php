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
 * El único centinela que se acepta es warehouse_id === 0 (lo que manda el
 * dashboard px-next; el legacy mandaba cadena vacía). NO es una bodega. Cualquier
 * otro valor — incluidos los negativos — y cualquier otra clave protegida
 * (default_warehouse_id, from_warehouse_id, from_warehouse) conservan su
 * comprobación de alcance y siguen siendo rechazados por assertAccess().
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

    public function test_negative_warehouse_id_still_asserts_scope_and_is_rejected(): void
    {
        // -1 no es el centinela: debe seguir pasando por assertAccess y ser rechazado.
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('assertAccess')
            ->atLeast()->once()
            ->with($user, -1, Mockery::type('string'))
            ->andThrow(new AuthorizationException('No tienes permiso para consultar esa bodega.'));

        $this->expectException(AuthorizationException::class);
        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=-1'), $user, $warehouseScope);
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

    public function test_middleware_exempts_only_the_exact_warehouse_id_zero_sentinel(): void
    {
        $src = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));
        // Exención acotada: sólo la clave warehouse_id con valor exactamente 0.
        $this->assertStringContainsString("if (\$key === 'warehouse_id' && (int) \$value === 0) return;", $src);
        $this->assertStringContainsString("(int) \$request->query('warehouse_id') !== 0", $src);
        // No debe haber una exención genérica por <= 0.
        $this->assertStringNotContainsString('if ((int) $value <= 0) return;', $src);
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
