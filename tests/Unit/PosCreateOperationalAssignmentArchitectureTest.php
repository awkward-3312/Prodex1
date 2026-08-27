<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosCreateOperationalAssignmentArchitectureTest extends TestCase
{
    public function test_native_pos_assignment_is_validated_before_legacy_warehouse_fallback(): void
    {
        $source = file_get_contents(app_path('Services/UserOperationalAssignmentService.php'));

        $nativeGuard = "if (\$branchId && \$locationId)";
        $nativeValidation = "\$this->validateRequestedOperationalAssignment(";
        $legacyWarehouseGuard = "if (! \$warehouseId)";

        $guardPosition = strpos($source, $nativeGuard);
        $validationPosition = strpos($source, $nativeValidation, $guardPosition ?: 0);
        $legacyPosition = strpos($source, $legacyWarehouseGuard, $validationPosition ?: 0);

        $this->assertNotFalse($guardPosition, 'Native branch/location guard is missing.');
        $this->assertNotFalse($validationPosition, 'Native operational assignment validation is missing.');
        $this->assertNotFalse($legacyPosition, 'Legacy warehouse fallback is missing.');
        $this->assertLessThan($legacyPosition, $guardPosition);
        $this->assertLessThan($legacyPosition, $validationPosition);
    }

    public function test_pos_bridge_sends_real_operational_ids_with_sale(): void
    {
        $source = file_get_contents(resource_path('src/utils/posOperationalLocationBridge.js'));

        $this->assertStringContainsString("if (path === 'pos/create_pos')", $source);
        $this->assertStringContainsString('data.branch_id = Number(', $source);
        $this->assertStringContainsString('data.inventory_location_id = Number(location.id);', $source);
        $this->assertStringContainsString('data.cash_drawer_id = Number(context.effective.cash_drawer_id);', $source);
    }
}
