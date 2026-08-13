<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: for any subscription with multiple pending payments,
        // mark all but the most recent as 'superseded'.
        $duplicates = DB::connection('central')
            ->table('tenant_billing_payments')
            ->select('tenant_subscription_id')
            ->whereNotNull('tenant_subscription_id')
            ->where('status', 'pending')
            ->groupBy('tenant_subscription_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tenant_subscription_id');

        foreach ($duplicates as $subscriptionId) {
            $latestId = DB::connection('central')
                ->table('tenant_billing_payments')
                ->where('tenant_subscription_id', $subscriptionId)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');

            if ($latestId) {
                DB::connection('central')
                    ->table('tenant_billing_payments')
                    ->where('tenant_subscription_id', $subscriptionId)
                    ->where('status', 'pending')
                    ->where('id', '!=', $latestId)
                    ->update([
                        'status'     => 'superseded',
                        'updated_at' => now(),
                    ]);
            }
        }

        // Add composite index for fast (subscription, status) lookups.
        Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
            $table->index(['tenant_subscription_id', 'status'], 'tbp_sub_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenant_billing_payments', function (Blueprint $table) {
            $table->dropIndex('tbp_sub_status_idx');
        });

        // Note: we do not roll back the 'superseded' status backfill — those rows
        // are still valid pending-payment history and rolling them back would
        // re-create the duplicate-payment problem.
    }
};
