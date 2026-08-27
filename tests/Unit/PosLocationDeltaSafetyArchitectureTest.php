<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosLocationDeltaSafetyArchitectureTest extends TestCase
{
    public function test_delta_polling_strips_legacy_warehouse_scope_and_never_redirects_navigation(): void
    {
        $script = file_get_contents(base_path('resources/static/prodex-pos-location-delta-safety.js'));

        $this->assertStringContainsString("raw === 'pos/get_products_pos_changes'", $script);
        $this->assertStringContainsString("/^pos\\/location-inventory\\/\\d+\\/changes$/i", $script);
        $this->assertStringContainsString('delete config.params.warehouse_id', $script);
        $this->assertStringContainsString("parsed.searchParams.delete('warehouse_id')", $script);
        $this->assertStringContainsString('skipErrorRedirect: true', $script);
        $this->assertStringContainsString('skipInitialLoader: true', $script);
        $this->assertStringContainsString('prodexPosLocationDeltaPoll: true', $script);
    }

    public function test_delta_safety_is_built_and_loaded_after_existing_pos_bridges(): void
    {
        $mix = file_get_contents(base_path('webpack.mix.js'));
        $layout = file_get_contents(base_path('resources/views/layouts/master.blade.php'));

        $this->assertStringContainsString('prodex-pos-location-delta-safety.js', $mix);
        $this->assertStringContainsString('/js/prodex-pos-location-delta-safety.js', $layout);

        $locationBridge = strpos($layout, '/js/prodex-pos-location-ui.js');
        $deltaSafety = strpos($layout, '/js/prodex-pos-location-delta-safety.js');
        $this->assertNotFalse($locationBridge);
        $this->assertNotFalse($deltaSafety);
        $this->assertGreaterThan($locationBridge, $deltaSafety);
    }

    public function test_backend_delta_still_authorizes_inventory_location_scope(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/PosLocationInventoryController.php'));

        $this->assertStringContainsString('$location = $this->authorizedSellableLocation($request, $locationId);', $controller);
        $this->assertStringContainsString('InventoryLocationScopeService::class)->canAccess($user, $locationId)', $controller);
    }
}
