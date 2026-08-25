<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InventoryFinalizationArchitectureTest extends TestCase
{
    public function test_inventory_pages_are_registered_as_native_vue_routes(): void
    {
        $main = file_get_contents(base_path('resources/src/main.js'));
        $this->assertStringContainsString('path: "/app/inventory"', $main);
        $this->assertStringContainsString('path: "location-stock"', $main);
        $this->assertStringContainsString('path: "missing"', $main);
        $this->assertFileExists(base_path('resources/src/views/app/pages/inventory/location_stock.vue'));
        $this->assertFileExists(base_path('resources/src/views/app/pages/inventory/missing.vue'));
    }

    public function test_obsolete_history_navigation_shim_is_not_loaded_or_built(): void
    {
        $layout = file_get_contents(base_path('resources/views/layouts/master.blade.php'));
        $mix = file_get_contents(base_path('webpack.mix.js'));
        $this->assertStringNotContainsString('prodex-inventory-spa-navigation.js', $layout);
        $this->assertStringNotContainsString('prodex-inventory-spa-navigation.js', $mix);
        $this->assertFileDoesNotExist(base_path('resources/static/prodex-inventory-spa-navigation.js'));
    }

    public function test_receiving_request_rejects_negative_quantities(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/TransferLogisticsController.php'));
        $this->assertStringContainsString("'items.*.quantity_good' => ['nullable', 'numeric', 'min:0']", $controller);
        $this->assertStringContainsString("'items.*.quantity_defective' => ['nullable', 'numeric', 'min:0']", $controller);
        $this->assertStringContainsString("'items.*.quantity_missing' => ['nullable', 'numeric', 'min:0']", $controller);
    }

    public function test_notification_center_is_global_and_integrity_ui_no_longer_hides_business_actions(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_transfer_logistics.php'));
        $ui = file_get_contents(base_path('resources/static/prodex-erp-integrity-ui.js'));
        $this->assertStringContainsString("Route::get('/notification-center'", $routes);
        $this->assertStringContainsString('/api/notification-center', $ui);
        $this->assertStringNotContainsString('lockTransferActions', $ui);
        $this->assertStringNotContainsString('lockAutomaticDamageActions', $ui);
    }
}
