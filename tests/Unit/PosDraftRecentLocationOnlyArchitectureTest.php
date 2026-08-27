<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosDraftRecentLocationOnlyArchitectureTest extends TestCase
{
    public function test_location_only_cashier_can_see_own_recent_drafts_without_user_warehouse(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/PosController.php'));

        $this->assertStringContainsString('$warehouse_ids = UserWarehouse::where(\'user_id\', $user->id)', $source);
        $this->assertStringContainsString('if (! $is_all_warehouses && empty($warehouse_ids))', $source);
        $this->assertStringContainsString("$draft_sales->where('user_id', '=', $user->id);", $source);
        $this->assertStringContainsString('elseif (! $is_all_warehouses)', $source);
        $this->assertStringContainsString("$draft_sales->whereIn('warehouse_id', $warehouse_ids);", $source);
    }
}
