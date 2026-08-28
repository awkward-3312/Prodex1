<?php

namespace Tests\Unit;

use Tests\TestCase;

class HeaderOperationalContextArchitectureTest extends TestCase
{
    public function test_header_context_reuses_effective_operational_assignment(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/HeaderOperationalContextController.php'));
        $routes = file_get_contents(base_path('routes/tenant_transfer_logistics.php'));

        $this->assertStringContainsString('UserOperationalAssignmentService', $controller);
        $this->assertStringContainsString('effectiveAssignment($user)', $controller);
        $this->assertStringContainsString("'branch_name'", $controller);
        $this->assertStringContainsString("'cash_drawer_name'", $controller);
        $this->assertStringContainsString("Route::get('/operational-context'", $routes);
    }

    public function test_header_freshness_tracks_real_report_responses(): void
    {
        $ui = file_get_contents(base_path('resources/static/prodex-erp-integrity-ui.js'));

        $this->assertStringContainsString('/api/operational-context', $ui);
        $this->assertStringContainsString('installRefreshTracker', $ui);
        $this->assertStringContainsString('isTrackedDataRequest', $ui);
        $this->assertStringContainsString('Actualizado ahora', $ui);
        $this->assertStringContainsString('dashboard_data', $ui);
        $this->assertStringContainsString('real_time_sales_counter_data', $ui);
    }
}
