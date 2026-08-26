<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosCashDrawerAssignmentArchitectureTest extends TestCase
{
    public function test_optional_drawer_bypass_is_removed(): void
    {
        $provider = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
        $shim = file_get_contents(base_path('resources/static/prodex-pos-optional-cash-drawer.js'));

        $this->assertStringNotContainsString('PosOptionalCashDrawerAssignmentService', $provider);
        $this->assertStringNotContainsString('ensureCashDrawerAssigned = function', $shim);
        $this->assertStringNotContainsString('Sin caja física', $shim);
        $this->assertStringContainsString('Physical cash drawers are no longer bypassed', $shim);
    }

    public function test_user_access_flows_assign_drawer_by_branch_and_location(): void
    {
        foreach ([
            'app/Http/Controllers/Organization/UserAccessController.php',
            'app/Http/Controllers/Organization/UserAccessEditController.php',
            'app/Http/Controllers/Organization/EmployeeAccessController.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringContainsString('default_cash_drawer_id', $source, $path);
            $this->assertStringContainsString('cash_register_override_assignment', $source, $path);
            $this->assertStringContainsString('inventory_location_id', $source, $path);
            $this->assertStringContainsString('branch_id', $source, $path);
        }
    }

    public function test_user_interfaces_filter_drawers_to_default_operational_context(): void
    {
        foreach ([
            'resources/src/views/app/pages/people/CreateUser.vue',
            'resources/src/views/app/pages/people/EditUser.vue',
            'resources/src/views/app/pages/organization/employee_access.vue',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringContainsString('default_cash_drawer_id', $source, $path);
            $this->assertStringContainsString('defaultCashDrawerOptions', $source, $path);
            $this->assertStringContainsString('requires_cash_drawer', $source, $path);
        }
    }

    public function test_branches_expose_contextual_cash_drawer_management(): void
    {
        $branches = file_get_contents(base_path('resources/src/views/app/pages/organization/branches.vue'));
        $drawers = file_get_contents(base_path('resources/src/views/app/pages/settings/cash_drawers.vue'));

        $this->assertStringContainsString("'/app/settings/cash_drawers'", $branches);
        $this->assertStringContainsString('branch_id: branch.id', $branches);
        $this->assertStringContainsString('contextBranchId', $drawers);
        $this->assertStringContainsString("{ branch_id: this.contextBranchId }", $drawers);
    }
}
