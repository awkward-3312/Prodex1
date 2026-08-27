<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosSalesWarehouseNullableArchitectureTest extends TestCase
{
    public function test_fresh_tenants_create_sales_with_nullable_warehouse_pointer(): void
    {
        $migration = file_get_contents(base_path('database/migrations/tenant/2026_03_24_203803_create_sales_table.php'));

        $this->assertStringContainsString("integer('warehouse_id')->nullable()->index('warehouse_id_sale')", $migration);
    }

    public function test_existing_tenants_receive_nullable_sales_warehouse_migration(): void
    {
        $migration = file_get_contents(base_path('database/migrations/tenant/2026_08_27_220500_make_sales_warehouse_id_nullable.php'));

        $this->assertStringContainsString('ALTER TABLE `sales` MODIFY `warehouse_id` INT NULL', $migration);
        $this->assertStringContainsString('Branch -> InventoryLocation -> CashDrawer', $migration);
    }

    public function test_sale_model_keeps_warehouse_as_legacy_compatibility_pointer(): void
    {
        $sale = file_get_contents(base_path('app/Models/Sale.php'));

        $this->assertStringContainsString('Legacy warehouse relation retained during inventory cutover.', $sale);
        $this->assertStringContainsString('$sale->warehouse_id = $context[\'warehouse_id\'] ?? null', $sale);
        $this->assertStringContainsString('$sale->inventory_location_id = $context[\'inventory_location_id\'] ?? null', $sale);
    }
}
