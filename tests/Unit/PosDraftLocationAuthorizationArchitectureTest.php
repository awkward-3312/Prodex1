<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosDraftLocationAuthorizationArchitectureTest extends TestCase
{
    public function test_create_draft_translates_virtual_location_selector_before_warehouse_scope(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));

        $this->assertStringContainsString('prepareLocationPosDraftRequest', $middleware);
        $this->assertStringContainsString('PosController@CreateDraft', $middleware);
        $this->assertStringContainsString('InventoryLocation::active()', $middleware);
        $this->assertStringContainsString('No tienes permiso para poner en espera una venta de esta ubicación de inventario.', $middleware);
        $this->assertStringContainsString("'warehouse_id' => \$compatibilityWarehouseId", $middleware);
        $this->assertStringContainsString("'inventory_location_id' => (int) \$location->id", $middleware);
    }

    public function test_create_draft_keeps_legacy_warehouse_requests_unchanged(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));

        $this->assertStringContainsString('If the id is not an InventoryLocation, this is a true legacy warehouse', $middleware);
        $this->assertStringContainsString('if (! $location) return;', $middleware);
    }
}
