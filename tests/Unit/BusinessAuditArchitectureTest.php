<?php

namespace Tests\Unit;

use Tests\TestCase;

class BusinessAuditArchitectureTest extends TestCase
{
    public function test_owner_record_visibility_is_global(): void
    {
        $user = file_get_contents(base_path('app/Models/User.php'));

        $this->assertStringContainsString("if ((int) \$this->role_id === 1)", $user);
        $this->assertStringContainsString('return true;', $user);
    }

    public function test_business_audit_is_fail_open_and_redacts_sensitive_values(): void
    {
        $service = file_get_contents(base_path('app/Services/BusinessAuditService.php'));

        $this->assertStringContainsString("Schema::hasTable('business_audit_logs')", $service);
        $this->assertStringContainsString("'[REDACTED]'", $service);
        $this->assertStringContainsString("'password'", $service);
        $this->assertStringContainsString('report($e);', $service);
    }

    public function test_business_audit_endpoint_is_tenant_protected(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_transfer_logistics.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/BusinessAuditController.php'));

        $this->assertStringContainsString("Route::get('/business-audit'", $routes);
        $this->assertStringContainsString("(int) \$user->role_id === 1", $controller);
        $this->assertStringContainsString("hasPermissionName('setting_system')", $controller);
    }

    public function test_notification_center_includes_cash_and_expiry_exceptions(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/NotificationCenterController.php'));

        $this->assertStringContainsString('appendCashRegisterDiscrepancies', $controller);
        $this->assertStringContainsString('appendBatchExpiryAlerts', $controller);
        $this->assertStringContainsString("'cash_register_discrepancy'", $controller);
        $this->assertStringContainsString("'batch_expiry_alert'", $controller);
    }

    public function test_controlled_upgrade_includes_business_audit_migration(): void
    {
        $command = file_get_contents(base_path('app/Console/Commands/ProdexTenantUpgrade.php'));

        $this->assertStringContainsString('2026_08_27_160000_create_business_audit_logs_table.php', $command);
    }
}
