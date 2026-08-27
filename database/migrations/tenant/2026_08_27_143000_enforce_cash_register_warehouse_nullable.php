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

        // Native POS sessions are identified by branch + inventory location + cash drawer.
        // warehouse_id is only a legacy compatibility pointer and must remain nullable.
        DB::statement('ALTER TABLE cash_registers MODIFY warehouse_id INT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Intentionally non-destructive. Once native POS sessions exist without a
        // legacy warehouse, restoring NOT NULL would either fail or require fake data.
    }
};
