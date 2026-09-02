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
 * usuario no-owner, EnforceWarehouseScope trata "0" como una bodega real,
 * lanza AuthorizationException (403) y el interceptor de axios llevaba toda la
 * SPA a not_authorize.
 *
 * El fix es SÓLO de frontend: cuando warehouseId === 0 ("todos") el dashboard
 * omite el selector (paridad con el dashboard anterior, que mandaba cadena
 * vacía); un warehouse_id > 0 es una selección real y sí se envía.
 *
 * EnforceWarehouseScope se mantiene estricto como en main: la exención de
 * warehouse_id=0 se revirtió. El P1 de Codex mostró que endpoints como
 * dead_stock / stockAging tratan warehouse_id=0 como "sin filtro" y no
 * garantizan el mismo alcance downstream, así que aceptar 0 en el middleware
 * global abriría riesgo de fuga de datos. Cualquier GET que envíe
 * warehouse_id=0 explícitamente sigue pasando por assertAccess() y sigue
 * siendo rechazado; los negativos y los ids reales también.
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

    public function test_dashboard_data_with_warehouse_id_zero_still_asserts_scope_and_is_rejected(): void
    {
        // El middleware NO tiene excepción para 0: un GET que traiga
        // warehouse_id=0 explícitamente sigue pasando por assertAccess y es
        // rechazado para un usuario restringido. (El fix vive en el frontend,
        // que ya no envía el selector cuando vale 0.)
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('assertAccess')
            ->atLeast()->once()
            ->with($user, 0, Mockery::type('string'))
            ->andThrow(new AuthorizationException('No tienes permiso para consultar esa bodega.'));

        $this->expectException(AuthorizationException::class);
        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=0&from=2026-01-01&to=2026-01-07'), $user, $warehouseScope);
    }

    public function test_dashboard_data_without_warehouse_id_does_not_assert_scope(): void
    {
        // Cadena vacía (lo que manda el dashboard tras el fix cuando no hay
        // selección real): $value === '' corta el walk() y filled() es false
        // en el bloque GET, así que no hay comprobación de alcance.
        $user = $this->user();
        $warehouseScope = Mockery::mock(WarehouseScopeService::class);
        $warehouseScope->shouldReceive('assertAccess')->never();

        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=&from=2026-01-01&to=2026-01-07'), $user, $warehouseScope);
        $this->addToAssertionCount(1);
    }

    public function test_negative_warehouse_id_still_asserts_scope_and_is_rejected(): void
    {
        // -1 debe seguir pasando por assertAccess y ser rechazado.
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
            ->atLeast()->once()
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
        // Se llama dos veces (walk() + bloque GET), de ahí atLeast()->once().
        $warehouseScope->shouldReceive('assertAccess')->atLeast()->once()->with($user, 5, Mockery::type('string'));

        $this->invoke($this->makeGet('/api/dashboard_data?warehouse_id=5'), $user, $warehouseScope);
        $this->addToAssertionCount(1);
    }

    public function test_px_next_dashboard_omits_warehouse_id_when_zero(): void
    {
        $src = file_get_contents(base_path('resources/src/views/app/dashboard/next/index.vue'));
        // No debe volver a mandar warehouse_id: 0 incondicionalmente.
        $this->assertStringNotContainsString('params: { warehouse_id: this.warehouseId, from: this.dateFrom, to: this.dateTo }', $src);
        // Sólo se adjunta el selector cuando es una selección real (> 0).
        $this->assertStringContainsString('if (this.warehouseId) params.warehouse_id = this.warehouseId;', $src);
    }

    public function test_middleware_has_no_zero_selector_exemption(): void
    {
        $src = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));
        // El middleware quedó estricto como en main: sin ninguna exención para 0.
        $this->assertStringNotContainsString("(int) \$value === 0) return;", $src);
        $this->assertStringNotContainsString("!== 0", $src);
        $this->assertStringNotContainsString('if ((int) $value <= 0) return;', $src);
        // El bloque GET valida CUALQUIER warehouse_id numérico, incluido 0.
        $this->assertStringContainsString(
            "if (\$request->isMethod('get') && \$request->filled('warehouse_id') && is_numeric(\$request->query('warehouse_id'))) {",
            $src
        );
    }

    /**
     * El fix NO amplía la visibilidad de datos: el dashboard sigue aplicando el
     * alcance por sucursal/almacén al no-owner (warehouse_id ausente => "todo
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
