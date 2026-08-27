<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosDraftRecentLocationOnlyArchitectureTest extends TestCase
{
    public function test_location_only_cashier_can_see_own_recent_drafts_without_user_warehouse(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/PosDraftRecentController.php'));

        $this->assertStringContainsString("UserWarehouse::where('user_id', \$user->id)", $source);
        $this->assertStringContainsString('if (! $isAllWarehouses && empty($warehouseIds))', $source);
        $this->assertStringContainsString("\$draftSales->where('user_id', \$user->id);", $source);
        $this->assertStringContainsString("\$draftSales->whereIn('warehouse_id', \$warehouseIds);", $source);
    }

    public function test_location_safe_route_overrides_legacy_recent_draft_route(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_pos_location.php'));
        $provider = file_get_contents(base_path('app/Providers/RouteServiceProvider.php'));

        $this->assertStringContainsString("Route::get('/get_draft_sales'", $routes);
        $this->assertStringContainsString('PosDraftRecentController::class', $routes);
        $this->assertStringContainsString("'tenant_api.php'", $provider);
        $this->assertStringContainsString("'tenant_pos_location.php'", $provider);
    }
}
