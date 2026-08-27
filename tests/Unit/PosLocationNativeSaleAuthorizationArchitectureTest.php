<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosLocationNativeSaleAuthorizationArchitectureTest extends TestCase
{
    public function test_location_native_pos_sale_is_not_authorized_by_legacy_warehouse_pointer(): void
    {
        $middleware = file_get_contents(base_path('app/Http/Middleware/EnforceWarehouseScope.php'));

        $this->assertStringContainsString('isLocationPosSale($request)', $middleware);
        $this->assertStringContainsString("$protectedKeys = ['default_warehouse_id', 'from_warehouse_id', 'from_warehouse'];", $middleware);
        $this->assertStringContainsString("$locationScope->canAccess($user, $locationId)", $middleware);
        $this->assertStringContainsString('No tienes permiso para operar con la ubicación de inventario seleccionada.', $middleware);
    }

    public function test_location_native_sale_still_requires_branch_location_and_drawer_assignment_validation(): void
    {
        $assignment = file_get_contents(base_path('app/Services/UserOperationalAssignmentService.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/PosController.php'));

        $this->assertStringContainsString('validateRequestedOperationalAssignment(', $assignment);
        $this->assertStringContainsString("throw new AuthorizationException('No tiene acceso a la sucursal seleccionada.')", $assignment);
        $this->assertStringContainsString("throw new AuthorizationException('No tiene acceso a la ubicación de inventario seleccionada.')", $assignment);
        $this->assertStringContainsString('$assignmentService->validateRequestedAssignment(', $controller);
    }

    public function test_location_sale_stock_is_owned_by_inventory_location_not_cash_drawer(): void
    {
        $sale = file_get_contents(base_path('app/Models/Sale.php'));
        $stockService = file_get_contents(base_path('app/Services/PosLocationSaleStockService.php'));

        $this->assertStringContainsString("$sale->inventory_location_id", $sale);
        $this->assertStringContainsString('PosLocationSaleStockService::class', $sale);
        $this->assertStringContainsString('InventoryService::class', $stockService);
        $this->assertStringNotContainsString('cash_drawer_id)->decrease', $stockService);
    }
}
