<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosCashRegisterWarehouseNullableUpgradeTest extends TestCase
{
    public function test_tenant_upgrade_runs_nullable_cash_register_migrations(): void
    {
        $command = file_get_contents(base_path('app/Console/Commands/ProdexTenantUpgrade.php'));

        $this->assertStringContainsString(
            'database/migrations/tenant/2026_08_26_091500_make_cash_register_warehouse_nullable.php',
            $command
        );
        $this->assertStringContainsString(
            'database/migrations/tenant/2026_08_27_143000_enforce_cash_register_warehouse_nullable.php',
            $command
        );
    }

    public function test_repair_migration_keeps_legacy_warehouse_optional(): void
    {
        $migration = file_get_contents(base_path('database/migrations/tenant/2026_08_27_143000_enforce_cash_register_warehouse_nullable.php'));

        $this->assertStringContainsString(
            'ALTER TABLE cash_registers MODIFY warehouse_id INT UNSIGNED NULL',
            $migration
        );
        $this->assertStringNotContainsString('INT UNSIGNED NOT NULL', $migration);
    }
}
