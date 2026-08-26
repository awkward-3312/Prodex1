<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_registers') || ! Schema::hasColumn('cash_registers', 'warehouse_id')) {
            return;
        }

        // Native POS sessions are identified by branch_id + inventory_location_id
        // + cash_drawer_id. warehouse_id is only a legacy compatibility pointer,
        // so branch-native cash drawers must be able to create sessions without it.
        DB::statement('ALTER TABLE cash_registers MODIFY warehouse_id INT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_registers') || ! Schema::hasColumn('cash_registers', 'warehouse_id')) {
            return;
        }

        // Never destroy or fabricate operational history during rollback.
        // Restoring NOT NULL is only safe when no native session contains NULL.
        $nullCount = DB::table('cash_registers')->whereNull('warehouse_id')->count();
        if ($nullCount > 0) {
            throw new RuntimeException(
                'Cannot restore cash_registers.warehouse_id to NOT NULL while native POS sessions without a legacy warehouse exist.'
            );
        }

        DB::statement('ALTER TABLE cash_registers MODIFY warehouse_id INT UNSIGNED NOT NULL');
    }
};
