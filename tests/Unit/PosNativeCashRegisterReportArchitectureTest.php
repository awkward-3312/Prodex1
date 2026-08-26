<?php

namespace Tests\Unit;

use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\PosCashRegisterReportController;
use App\Http\Controllers\SafePosCashRegisterReportController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PosNativeCashRegisterReportArchitectureTest extends TestCase
{
    public function test_report_uses_native_operational_scope(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/PosCashRegisterReportController.php'));

        $this->assertStringContainsString('BranchScopeService', $controller);
        $this->assertStringContainsString('InventoryLocationScopeService', $controller);
        $this->assertStringContainsString("'branch_id'", $controller);
        $this->assertStringContainsString("'inventory_location_id'", $controller);
        $this->assertStringContainsString("'cash_drawer_id'", $controller);
        $this->assertStringContainsString('is_legacy_context', $controller);
        $this->assertStringContainsString('legacy_warehouse_id', $controller);
    }

    public function test_report_controller_signatures_are_php_compatible(): void
    {
        $base = new ReflectionMethod(CashRegisterController::class, 'report');
        $native = new ReflectionMethod(PosCashRegisterReportController::class, 'report');
        $safe = new ReflectionMethod(SafePosCashRegisterReportController::class, 'report');

        $this->assertSame($base->getNumberOfRequiredParameters(), $native->getNumberOfRequiredParameters());
        $this->assertSame($native->getNumberOfRequiredParameters(), $safe->getNumberOfRequiredParameters());
        $this->assertSame(1, $native->getNumberOfRequiredParameters());
    }

    public function test_report_frontend_exposes_native_filters(): void
    {
        $vue = file_get_contents(base_path('resources/src/views/app/pages/reports/Cash_Register_Report.vue'));

        $this->assertStringContainsString('Sucursal', $vue);
        $this->assertStringContainsString('Ubicación', $vue);
        $this->assertStringContainsString('Caja física', $vue);
        $this->assertStringContainsString('cash_registers_native', $vue);
        $this->assertStringContainsString('inventory_location_id', $vue);
        $this->assertStringContainsString('legacy_warehouse_id', $vue);
    }

    public function test_route_is_loaded_by_tenant_provider(): void
    {
        $provider = file_get_contents(base_path('app/Providers/RouteServiceProvider.php'));
        $routes = file_get_contents(base_path('routes/tenant_pos_reports.php'));

        $this->assertStringContainsString('tenant_pos_reports.php', $provider);
        $this->assertStringContainsString('report/cash_registers_native', $routes);
        $this->assertStringContainsString('SafePosCashRegisterReportController@report', $routes);
    }
}
