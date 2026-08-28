<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalesOperationalReportingArchitectureTest extends TestCase
{
    public function test_sales_reporting_scope_prefers_branch_and_keeps_legacy_fallback(): void
    {
        $source = file_get_contents(base_path('app/Services/SalesReportingScopeService.php'));

        $this->assertStringContainsString('branch_id', $source);
        $this->assertStringContainsString('whereNull("{$alias}.branch_id")', $source);
        $this->assertStringContainsString('warehouse_id', $source);
        $this->assertStringContainsString('role_id === 1', $source);
    }

    public function test_sale_read_routes_are_overridden_after_legacy_api_routes(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_pos_reports.php'));
        $provider = file_get_contents(base_path('app/Providers/RouteServiceProvider.php'));

        $this->assertStringContainsString("Route::get('dashboard_data', 'OperationalDashboardController@dashboard_data')", $routes);
        $this->assertStringContainsString("Route::get('sales', 'OperationalSalesController@index')", $routes);
        $this->assertStringContainsString("report/get_sales_by_user", $routes);
        $this->assertStringContainsString("report/seller_report", $routes);
        $this->assertStringContainsString("report/warehouse_report", $routes);
        $this->assertStringContainsString("report/sales_warehouse", $routes);

        $this->assertLessThan(
            strpos($provider, "'tenant_pos_reports.php'"),
            strpos($provider, "'tenant_api.php'")
        );
    }

    public function test_operational_report_controllers_do_not_write_warehouse_identity(): void
    {
        foreach ([
            'app/Http/Controllers/OperationalSalesController.php',
            'app/Http/Controllers/OperationalDashboardController.php',
            'app/Http/Controllers/OperationalReportController.php',
            'app/Http/Controllers/OperationalBranchReportController.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringNotContainsString("warehouse_id =", $source, $path);
            $this->assertStringNotContainsString("update(['warehouse_id'", $source, $path);
        }
    }

    public function test_sales_rows_expose_operational_identity_without_breaking_legacy_frontend_key(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/OperationalSalesController.php'));

        $this->assertStringContainsString("'branch_name'", $source);
        $this->assertStringContainsString("'inventory_location_name'", $source);
        $this->assertStringContainsString("'cash_drawer_name'", $source);
        $this->assertStringContainsString("'warehouse_name' => $scope->displayLocation", $source);
    }
}
