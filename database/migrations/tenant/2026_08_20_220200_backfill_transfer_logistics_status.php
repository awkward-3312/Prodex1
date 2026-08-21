<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfers') || ! Schema::hasColumn('transfers', 'logistics_status')) {
            return;
        }

        // Historical completed transfers already credited destination inventory in
        // the legacy workflow. Mark them as historical receipts without fabricating
        // a receiver identity or receipt detail rows.
        DB::table('transfers')
            ->whereNull('deleted_at')
            ->where('statut', 'completed')
            ->where(function ($q) {
                $q->where('approval_status', 'approved')->orWhereNull('approval_status');
            })
            ->where('logistics_status', 'pending')
            ->update([
                'logistics_status' => 'received',
                'received_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);

        // Historical sent transfers already had source stock deducted. Bring them
        // into the new in-transit workflow and give each a secure receiving token.
        DB::table('transfers')
            ->whereNull('deleted_at')
            ->where('statut', 'sent')
            ->where(function ($q) {
                $q->where('approval_status', 'approved')->orWhereNull('approval_status');
            })
            ->where('logistics_status', 'pending')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    do {
                        $token = 'TRF-'.now()->format('ymd').'-'.strtoupper(Str::random(12));
                    } while (DB::table('transfers')->where('receiving_token', $token)->exists());

                    DB::table('transfers')->where('id', $row->id)->update([
                        'receiving_token' => $token,
                        'logistics_status' => 'in_transit',
                        'dispatched_at' => $row->updated_at ?: $row->created_at,
                        'dispatched_by_user_id' => $row->user_id,
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Intentionally non-destructive: historical state may have received new
        // receipts after deployment and must never be blindly reverted.
    }
};
