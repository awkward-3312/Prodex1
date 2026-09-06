<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contrato estructural del alcance analítico del Panel.
 *
 * - El DashboardController resuelve el alcance en UN solo lugar
 *   (DashboardScopeService) y consume el DashboardScope: sin `if role == ...`,
 *   `if record_view ...` ni `if manager ...` disperso por cada consulta.
 * - Las métricas FINANCIAL_MANAGEMENT y TEAM se emiten SÓLO tras la comprobación
 *   de autoridad (no se ocultan en el frontend).
 * - `hasRecordView()` global queda intacto; `real_time_sales_counter_data` queda
 *   intacto (fuera de alcance).
 */
class DashboardAnalyticsScopeArchitectureTest extends TestCase
{
    private function read(string $relativePath): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);
    }

    private function controller(): string
    {
        return $this->read('app/Http/Controllers/DashboardController.php');
    }

    public function test_dashboard_data_resolves_scope_through_the_central_service(): void
    {
        $c = $this->controller();

        $this->assertStringContainsString('use App\Services\DashboardScopeService;', $c);
        $this->assertStringContainsString('app(DashboardScopeService::class)->resolve(', $c);
        $this->assertStringContainsString('DashboardScope $scope', $c);
        $this->assertStringContainsString('$scope->effectiveWarehouseIds', $c);
        $this->assertStringContainsString('$scope->personalUserId', $c);
    }

    public function test_financial_and_team_metrics_are_gated_by_authority_not_by_frontend(): void
    {
        $c = $this->controller();

        $this->assertStringContainsString('if ($scope->canSeeFinancialManagement) {', $c);
        $this->assertStringContainsString('if ($scope->canSeeTeam) {', $c);

        // Las claves financieras y de equipo se asignan dentro de esos bloques.
        $this->assertMatchesRegularExpression(
            '/if \(\$scope->canSeeFinancialManagement\) \{.*?\$payload\[.financial.\].*?\}/s',
            $c
        );
        $this->assertMatchesRegularExpression(
            '/if \(\$scope->canSeeTeam\) \{\s*\$payload\[.sales_by_cashier.\]/s',
            $c
        );
    }

    public function test_no_branch_scope_returns_empty_payload_without_warehouse_fallback(): void
    {
        $c = $this->controller();

        $this->assertStringContainsString('if (! $scope->hasBranch) {', $c);
        // La respuesta "sin sucursal" no calcula ninguna métrica.
        $this->assertMatchesRegularExpression(
            '/if \(! \$scope->hasBranch\) \{\s*return response\(\)->json\(\[/s',
            $c
        );
    }

    public function test_my_sales_metric_is_always_personal(): void
    {
        $c = $this->controller();

        $this->assertStringContainsString("\$payload['my_sales'] = \$this->MySales(", $c);
        $this->assertStringContainsString('public function MySales(DashboardScope $scope', $c);
        // MySales filtra SIEMPRE por el usuario autenticado.
        $this->assertMatchesRegularExpression(
            '/function MySales\(.*?->where\(.user_id., \$scope->personalUserId\)/s',
            $c
        );
    }

    public function test_today_profit_is_computed_on_a_single_scope(): void
    {
        $c = $this->controller();

        // La utilidad vive en FinancialReport y sus sumandos comparten
        // $array_warehouses_id (mismo alcance); ya no hay filtro `user_id` propio
        // mezclado con COGS de sucursal.
        $this->assertStringContainsString('public function FinancialReport($request, DashboardScope $scope', $c);
        $this->assertMatchesRegularExpression(
            '/function FinancialReport\(.*?\$array_warehouses_id = \$this->scopedWarehouseIds\(\$scope/s',
            $c
        );
        $this->assertMatchesRegularExpression(
            "/function FinancialReport\(.*?\\\$data\['today_profit'\] = \\\$completedSalesTotal - \\\$cogsFIFO - \\\$expenses_total \+ \\\$service\['profit'\]/s",
            $c
        );
    }

    public function test_dashboard_panel_methods_no_longer_use_record_view(): void
    {
        $c = $this->controller();

        // `hasRecordView()` sólo puede quedar en real_time_sales_counter_data.
        $this->assertSame(
            1,
            substr_count($c, 'hasRecordView'),
            'El Panel dejó de usar record_view como palanca de granularidad; sólo real_time_sales_counter_data lo conserva.'
        );

        // Ninguno de los métodos de métrica del Panel conserva el filtro binario.
        foreach (['SalesChart', 'PurchasesChart', 'TopCustomers', 'Top_Products_Year', 'SalesByPayment', 'StockValue', 'Payment_chart'] as $method) {
            $this->assertMatchesRegularExpression(
                '/function '.$method.'\(DashboardScope \$scope/',
                $c,
                $method.' debe recibir el DashboardScope resuelto.'
            );
        }
    }

    public function test_operational_dashboard_reuses_the_same_scope_model(): void
    {
        $c = $this->read('app/Http/Controllers/OperationalDashboardController.php');

        // Reutiliza el resolver del padre; no re-implementa detección de rol.
        $this->assertStringContainsString('$scope = $this->resolveDashboardScope($request);', $c);
        $this->assertStringContainsString('DashboardScope $scope', $c);
        // Ya no usa el binario record_view del SalesReportingScopeService.
        $this->assertStringNotContainsString('applyRecordVisibility', $c);
        $this->assertStringNotContainsString('SalesReportingScopeService', $c);
        // Estado vacío + gating de equipo.
        $this->assertStringContainsString('if (! $scope->hasBranch) {', $c);
        $this->assertStringContainsString('if ($scope->canSeeTeam) {', $c);
        $this->assertMatchesRegularExpression(
            '/else \{\s*unset\(\$payload\[.sales_by_cashier.\], \$payload\[.sales_by_branch.\]\);/s',
            $c
        );
        // PERSONAL: ventas recientes propias cuando no hay autoridad de equipo.
        $this->assertMatchesRegularExpression(
            '/if \(! \$scope->canSeeTeam\) \{\s*\$recent->where\(.sales\.user_id., \$scope->personalUserId\)/s',
            $c
        );
    }

    public function test_real_time_sales_counter_data_is_untouched(): void
    {
        $c = $this->controller();

        $this->assertStringContainsString('public function real_time_sales_counter_data(Request $request)', $c);
        $this->assertStringContainsString("inRole('real_time_sales_counter')", $c);
        // conserva su propio modelo (no migra al DashboardScope).
        $this->assertMatchesRegularExpression(
            '/function real_time_sales_counter_data\(.*?\$view_records = \$user->hasRecordView\(\);/s',
            $c
        );
    }

    public function test_record_view_global_semantics_are_intact(): void
    {
        $user = $this->read('app/Models/User.php');

        // El método global permanece con su short-circuit de owner.
        $this->assertStringContainsString('public function hasRecordView()', $user);
        $this->assertStringContainsString('if ((int) $this->role_id === 1)', $user);

        // El resto de PRODEX sigue usándolo (listados/reportes).
        $salesScope = $this->read('app/Services/SalesReportingScopeService.php');
        $this->assertStringContainsString('$user->hasRecordView()', $salesScope);
    }

    public function test_scope_service_does_not_depend_on_record_view_or_legacy_all_warehouses(): void
    {
        $svc = $this->read('app/Services/DashboardScopeService.php');

        // Sólo el CÓDIGO (sin comentarios) — la prosa sí explica por qué NO se
        // usan estas señales.
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $svc);

        $this->assertStringContainsString('OWNER_ROLE_ID', $svc);
        $this->assertStringContainsString('manager_employee_id', $code);
        $this->assertStringNotContainsString('hasRecordView', $code);
        $this->assertStringNotContainsString("'record_view'", $code);
        $this->assertStringNotContainsString('->record_view', $code);
        $this->assertStringNotContainsString("'is_all_warehouses'", $code);
        $this->assertStringNotContainsString('->is_all_warehouses', $code);
    }

    public function test_frontend_dashboard_is_open_and_consumes_the_scope_contract(): void
    {
        $vue = $this->read('resources/src/views/app/dashboard/next/index.vue');
        $adapter = $this->read('resources/src/views/app/dashboard/next/adapter.js');

        // El Panel ya no se bloquea por el permiso legacy `dashboard`.
        $this->assertMatchesRegularExpression('/hasDashboardPermission\(\)\s*\{\s*return true;/s', $vue);

        // Consume el contrato de alcance.
        $this->assertStringContainsString('canSeeFinancial', $vue);
        $this->assertStringContainsString('canSeeTeam', $vue);
        $this->assertStringContainsString('hasBranch', $vue);
        $this->assertStringContainsString('No tienes una sucursal asignada', $vue);

        $this->assertStringContainsString('can_see_financial', $adapter);
        $this->assertStringContainsString('can_see_team', $adapter);
        $this->assertStringContainsString('has_branch', $adapter);
        $this->assertStringContainsString('mySales', $adapter);
        $this->assertStringContainsString('salesByCashier', $adapter);
    }
}
