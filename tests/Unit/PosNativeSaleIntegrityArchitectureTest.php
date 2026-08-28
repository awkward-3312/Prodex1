<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosNativeSaleIntegrityArchitectureTest extends TestCase
{
    public function test_browser_no_longer_sends_location_as_warehouse_for_modern_sale(): void
    {
        $bridge = file_get_contents(base_path('resources/src/utils/posOperationalLocationBridge.js'));

        $this->assertStringContainsString('delete data.warehouse_id;', $bridge);
        $this->assertStringContainsString('data.branch_id = Number(', $bridge);
        $this->assertStringContainsString('data.inventory_location_id = Number(location.id);', $bridge);
        $this->assertStringNotContainsString('data.warehouse_id = compatibilityId || Number(location.id);', $bridge);
    }

    public function test_modern_create_pos_is_normalized_in_tenant_stack_before_warehouse_scope(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_pos_location.php'));
        $provider = file_get_contents(base_path('app/Providers/RouteServiceProvider.php'));

        $normalizer = strpos($provider, 'NormalizeModernPosSaleRequest::class');
        $warehouseScope = strpos($provider, 'EnforceWarehouseScope::class');

        $this->assertNotFalse($normalizer);
        $this->assertNotFalse($warehouseScope);
        $this->assertLessThan($warehouseScope, $normalizer);
        $this->assertStringNotContainsString("Route::post('/pos/create_pos', 'PosController@CreatePOS')", $routes);
        $this->assertStringContainsString("'tenant_api.php'", $provider);
        $this->assertStringContainsString("'tenant_pos_location.php'", $provider);
    }

    public function test_server_rebuilds_critical_pos_amounts_from_master_data(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/NormalizeModernPosSaleRequest.php'));

        $this->assertStringContainsString("'Unit_price'", $middleware);
        $this->assertStringContainsString("'tax_percent'", $middleware);
        $this->assertStringContainsString("'subtotal'", $middleware);
        $this->assertStringContainsString("'GrandTotal'", $middleware);
        $this->assertStringContainsString("'TaxNet'", $middleware);
        $this->assertStringContainsString('PromotionEngine::class', $middleware);
        $this->assertStringContainsString('authoritativePointsDiscount', $middleware);
        $this->assertStringContainsString('realCompatibilityWarehouseId', $middleware);
    }

    public function test_legitimate_line_discounts_are_preserved_but_minimum_price_is_enforced(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/NormalizeModernPosSaleRequest.php'));

        $this->assertStringContainsString("\$row['discount'] ?? \$product->discount", $middleware);
        $this->assertStringContainsString("\$row['discount_Method']", $middleware);
        $this->assertStringContainsString('El descuento deja el producto por debajo del precio mínimo permitido.', $middleware);
        $this->assertStringContainsString('Fiscal values come from product master data', $middleware);
    }

    public function test_legacy_pos_requests_are_explicitly_left_untouched(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/NormalizeModernPosSaleRequest.php'));

        $this->assertStringContainsString("if (! \$request->filled('branch_id') || ! \$request->filled('inventory_location_id')) return false;", $middleware);
        $this->assertStringContainsString('if (! $this->isModernCreatePos($request))', $middleware);
    }
}
