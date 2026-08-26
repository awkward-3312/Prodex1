<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosOptionalCashDrawerArchitectureTest extends TestCase
{
    public function test_native_pos_optional_drawer_service_is_bound(): void
    {
        $provider = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
        $service = file_get_contents(base_path('app/Services/PosOptionalCashDrawerAssignmentService.php'));
        $ui = file_get_contents(base_path('resources/static/prodex-pos-optional-cash-drawer.js'));

        $this->assertStringContainsString(
            'UserOperationalAssignmentService::class, PosOptionalCashDrawerAssignmentService::class',
            $provider
        );
        $this->assertStringContainsString('$requireDrawer = false;', $service);
        $this->assertStringContainsString("NO_DRAWER_VALUE = '-1'", $ui);
        $this->assertStringContainsString("delete data.cash_drawer_id", $ui);
    }
}
