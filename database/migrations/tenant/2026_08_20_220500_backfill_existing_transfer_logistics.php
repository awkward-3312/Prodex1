<?php

use App\Models\Transfer;
use App\Services\TransferLogisticsService;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfers')
            || ! Schema::hasColumn('transfers', 'logistics_status')
            || ! Schema::hasColumn('transfers', 'receiving_token')) {
            return;
        }

        // Historical completed transfers already credited their destination through
        // the legacy workflow. Mark them terminal without inventing a receiver.
        DB::table('transfers')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('approval_status', 'approved')->orWhereNull('approval_status');
            })
            ->where('statut', 'completed')
            ->where('logistics_status', 'pending')
            ->update([
                'logistics_status' => 'received',
                'updated_at' => now(),
            ]);

        // Historical "sent" transfers are real stock currently in transit: legacy
        // code already removed it from origin and has not credited destination. Give
        // each one a secure receipt token and surface it to the destination receiver.
        $sent = DB::table('transfers')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('approval_status', 'approved')->orWhereNull('approval_status');
            })
            ->where('statut', 'sent')
            ->where('logistics_status', 'pending')
            ->orderBy('id')
            ->get(['id', 'date', 'time', 'receiving_token']);

        foreach ($sent as $row) {
            $token = $row->receiving_token ?: $this->uniqueToken();
            $dispatchedAt = $this->historicalTimestamp($row->date, $row->time);

            DB::table('transfers')->where('id', $row->id)->update([
                'receiving_token' => $token,
                'logistics_status' => 'in_transit',
                'dispatched_at' => $dispatchedAt,
                'updated_at' => now(),
            ]);

            // Notification generation is idempotent (transfer + user + type unique)
            // and obeys the same destination warehouse + transfer_receive rules as
            // all new dispatches.
            if (Schema::hasTable('transfer_notifications')) {
                $transfer = Transfer::with(['from_warehouse', 'to_warehouse'])->find($row->id);
                if ($transfer) {
                    app(TransferLogisticsService::class)->notifyDestinationReceivers($transfer);
                }
            }
        }
    }

    public function down(): void
    {
        // Do not try to infer which in-transit/received rows were historical after
        // users may have processed them. Logistics backfills are intentionally one-way.
    }

    private function uniqueToken(): string
    {
        do {
            $token = 'TRF-'.now()->format('ymd').'-'.strtoupper(Str::random(12));
        } while (DB::table('transfers')->where('receiving_token', $token)->exists());

        return $token;
    }

    private function historicalTimestamp($date, $time): Carbon
    {
        try {
            return Carbon::parse(trim((string) $date.' '.(string) ($time ?: '00:00:00')));
        } catch (\Throwable) {
            return now();
        }
    }
};
