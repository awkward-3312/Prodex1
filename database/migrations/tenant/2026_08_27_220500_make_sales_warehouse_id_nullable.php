<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'warehouse_id')) {
            return;
        }

        // warehouse_id is no longer the operational owner of a POS sale. It is a
        // legacy compatibility pointer to a distribution-center warehouse and can
        // legitimately be NULL for branch/location-native sales.
        DB::statement('ALTER TABLE `sales` MODIFY `warehouse_id` INT NULL');
    }

    public function down(): void
    {
        // Intentionally keep warehouse_id nullable. Re-introducing NOT NULL would
        // corrupt the modern POS model or require inventing a warehouse for sales
        // that belong only to Branch -> InventoryLocation -> CashDrawer.
    }
};
