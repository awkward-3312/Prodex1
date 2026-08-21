<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_drawers')) return;

        // cash_drawers.warehouse_id was historically mandatory because the POS
        // operated only by warehouse. New cash drawers belong to a Branch and an
        // InventoryLocation. Keep warehouse_id nullable as a compatibility pointer
        // for legacy POS sessions until their cutover is complete.
        if (Schema::hasColumn('cash_drawers', 'warehouse_id')) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `cash_drawers` MODIFY `warehouse_id` INT UNSIGNED NULL');
            }
        }

        if (! Schema::hasColumn('cash_drawers', 'branch_id')
            || ! Schema::hasColumn('cash_drawers', 'inventory_location_id')
            || ! Schema::hasTable('warehouses')
            || ! Schema::hasTable('branches')) {
            return;
        }

        $drawers = DB::table('cash_drawers')
            ->whereNull('deleted_at')
            ->whereNotNull('warehouse_id')
            ->get(['id', 'warehouse_id', 'branch_id', 'inventory_location_id']);

        foreach ($drawers as $drawer) {
            $warehouse = DB::table('warehouses')
                ->where('id', $drawer->warehouse_id)
                ->whereNull('deleted_at')
                ->first(['id', 'branch_id']);

            if (! $warehouse || ! $warehouse->branch_id) continue;

            $branch = DB::table('branches')
                ->where('id', $warehouse->branch_id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first(['id', 'default_inventory_location_id']);

            if (! $branch) continue;

            $updates = [];
            if (! $drawer->branch_id) $updates['branch_id'] = $branch->id;

            if (! $drawer->inventory_location_id && $branch->default_inventory_location_id) {
                $validLocation = Schema::hasTable('inventory_locations')
                    && DB::table('inventory_locations')
                        ->where('id', $branch->default_inventory_location_id)
                        ->where('branch_id', $branch->id)
                        ->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->exists();

                if ($validLocation) {
                    $updates['inventory_location_id'] = $branch->default_inventory_location_id;
                }
            }

            if ($updates) {
                $updates['updated_at'] = now();
                DB::table('cash_drawers')->where('id', $drawer->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed. warehouse_id remains a
        // compatibility pointer; restoring NOT NULL could fail for new drawers.
    }
};
