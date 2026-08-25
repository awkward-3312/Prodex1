<?php

namespace Tests\Unit;

use Tests\TestCase;

class GlobalNotificationCenterArchitectureTest extends TestCase
{
    public function test_center_aggregates_persistent_and_dynamic_sources(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/NotificationCenterController.php'));

        $this->assertStringContainsString("Schema::hasTable('transfer_notifications')", $controller);
        $this->assertStringContainsString("Schema::hasTable('notifications')", $controller);
        $this->assertStringContainsString("Schema::hasTable('transfer_discrepancies')", $controller);
        $this->assertStringContainsString("Schema::hasTable('product_warehouse')", $controller);
        $this->assertStringContainsString("'meetings' => 'Reuniones'", $controller);
        $this->assertStringContainsString("'assets' => 'Activos'", $controller);
        $this->assertStringContainsString("'purchases' => 'Compras'", $controller);
        $this->assertStringContainsString("'pos' => 'POS'", $controller);
        $this->assertStringContainsString("'hr' => 'RRHH'", $controller);
        $this->assertStringContainsString("'accounting' => 'Contabilidad'", $controller);
    }

    public function test_laravel_notifications_have_secure_read_endpoint(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/NotificationCenterController.php'));
        $routes = file_get_contents(base_path('routes/tenant_transfer_logistics.php'));

        $this->assertStringContainsString('markLaravelNotificationRead', $controller);
        $this->assertStringContainsString("->where('notifiable_type', get_class(\$user))", $controller);
        $this->assertStringContainsString("->where('notifiable_id', \$user->id)", $controller);
        $this->assertStringContainsString("Route::post('/notification-center/{notificationId}/read'", $routes);
    }

    public function test_known_database_notifications_resolve_to_native_app_routes(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/NotificationCenterController.php'));

        $this->assertStringContainsString("'/app/meeting/details/'", $controller);
        $this->assertStringContainsString("'/app/assets/edit/'", $controller);
        $this->assertStringContainsString("'/app/reports/quantity_alerts'", $controller);
        $this->assertStringContainsString("'/app/inventory/missing'", $controller);
    }

    public function test_bell_uses_only_the_global_center_and_spa_navigation(): void
    {
        $ui = file_get_contents(base_path('resources/static/prodex-erp-integrity-ui.js'));

        $this->assertStringContainsString('/api/notification-center', $ui);
        $this->assertStringContainsString('resolveRouter', $ui);
        $this->assertStringContainsString('router.push(action)', $ui);
        $this->assertStringContainsString('#notif-dd .notification-item{display:none!important}', $ui);
        $this->assertStringNotContainsString('stockAlertCount', $ui);
    }
}
