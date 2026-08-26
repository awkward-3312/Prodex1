<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosNativeCashRegisterArchitectureTest extends TestCase
{
    public function test_pos_register_routes_use_native_location_controller(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_pos_register.php'));
        $provider = file_get_contents(base_path('app/Providers/RouteServiceProvider.php'));

        $this->assertStringContainsString('PosCashRegisterController', $routes);
        $this->assertStringContainsString('tenant_pos_register.php', $provider);
    }

    public function test_cash_register_model_persists_branch_and_inventory_location(): void
    {
        $model = file_get_contents(base_path('app/Models/CashRegister.php'));

        $this->assertStringContainsString("'branch_id'", $model);
        $this->assertStringContainsString("'inventory_location_id'", $model);
        $this->assertStringContainsString('function inventoryLocation()', $model);
    }

    public function test_native_register_summary_prefers_inventory_location_over_warehouse(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/PosCashRegisterController.php'));

        $this->assertStringContainsString("where($column('inventory_location_id'), $register->inventory_location_id)", $controller);
        $this->assertStringContainsString("'inventory_location' => optional($register->inventoryLocation)->name", $controller);
        $this->assertStringContainsString('warehouse_id is written only as a legacy compatibility pointer', $controller);
    }

    public function test_pos_bridge_rewrites_legacy_register_calls_to_native_routes(): void
    {
        $bridge = file_get_contents(base_path('resources/src/utils/posOperationalLocationBridge.js'));

        $this->assertStringContainsString("if (path === 'cash-registers/open') return 'pos/registers/open';", $bridge);
        $this->assertStringContainsString("if (path === 'cash-registers/close') return 'pos/registers/close';", $bridge);
        $this->assertStringContainsString('params.inventory_location_id', $bridge);
        $this->assertStringContainsString('delete data.warehouse_id', $bridge);
    }

    public function test_tenant_upgrade_includes_native_register_migration(): void
    {
        $command = file_get_contents(base_path('app/Console/Commands/ProdexTenantUpgrade.php'));

        $this->assertStringContainsString(
            '2026_08_25_181500_add_native_location_context_to_cash_registers.php',
            $command
        );
    }
}
