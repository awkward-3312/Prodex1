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

    public function test_location_only_cashier_does_not_need_user_warehouse_access_to_hold_sale(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));

        $this->assertStringNotContainsString('allowedWarehouseIds($user)', $this->draftPreparationMethod($middleware));
        $this->assertStringContainsString("Warehouse::whereNull('deleted_at')", $middleware);
        $this->assertStringContainsString('PosController@CreateDraft', $middleware);
        $this->assertStringContainsString("\$path === 'api/pos/create_pos' || \$path === 'api/pos/create_draft'", $middleware);
    }

    public function test_create_draft_keeps_legacy_warehouse_requests_unchanged(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));

        $this->assertStringContainsString('If the id is not an InventoryLocation, this is a true legacy warehouse', $middleware);
        $this->assertStringContainsString('if (! $location) return;', $middleware);
    }

    private function draftPreparationMethod(string $middleware): string
    {
        $start = strpos($middleware, 'private function prepareLocationPosDraftRequest');
        $end = strpos($middleware, 'private function isPosCreateDraftRequest', $start ?: 0);

        if ($start === false || $end === false) {
            return $middleware;
        }

        return substr($middleware, $start, $end - $start);
    }
}
