<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosCashRegisterWarehouseNullableArchitectureTest extends TestCase
{
    public function test_native_cash_registers_allow_null_legacy_warehouse(): void
    {
        $migration = file_get_contents(base_path('database/migrations/tenant/2026_08_26_091500_make_cash_register_warehouse_nullable.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/PosCashRegisterController.php'));

        $this->assertStringContainsString(
            'ALTER TABLE cash_registers MODIFY warehouse_id INT UNSIGNED NULL',
            $migration
        );
        $this->assertStringContainsString("'warehouse_id' => \$warehouseId", $controller);
        $this->assertStringContainsString("'branch_id' => \$branchId", $controller);
        $this->assertStringContainsString("'inventory_location_id' => \$locationId", $controller);
        $this->assertStringContainsString("'cash_drawer_id' => \$cashDrawerId", $controller);
    }
}
