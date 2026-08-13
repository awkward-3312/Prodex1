<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ExpireSupersededTenantSubscriptions extends Migration
{
    /**
     * Data fix: tenants that paid for a plan kept their old trial (or previous
     * plan) subscription row in a live status, so lists and counts still showed
     * them on the free trial. Keep only the newest active/trial row per tenant
     * and expire the older ones. Going forward activate() does this itself.
     */
    public function up(): void
    {
        $live = ['active', 'trial'];

        $latestIds = DB::connection('central')
            ->table('tenant_subscriptions')
            ->selectRaw('MAX(id) as id')
            ->whereIn('status', $live)
            ->groupBy('tenant_id')
            ->pluck('id');

        DB::connection('central')
            ->table('tenant_subscriptions')
            ->whereIn('status', $live)
            ->whereNotIn('id', $latestIds)
            ->update(['status' => 'expired']);
    }

    public function down(): void
    {
        // Irreversible data fix — superseded rows stay expired.
    }
}
