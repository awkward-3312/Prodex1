<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfers') || ! Schema::hasColumn('transfers', 'logistics_status')) {
            return;
        }

        // A pending transfer has never moved stock. Historical rows may still carry
        // the legacy "completed" selector; approving one of those would credit the
        // destination before a physical receiver confirms it. Normalize them to sent
        // so every future approval follows source -> transit -> destination receipt.
        DB::table('transfers')
            ->whereNull('deleted_at')
            ->where('approval_status', 'pending')
            ->where('statut', 'completed')
            ->update([
                'statut' => 'sent',
                'logistics_status' => 'pending',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring legacy "completed" on pending rows
        // would re-open the bypass this migration closes.
    }
};
