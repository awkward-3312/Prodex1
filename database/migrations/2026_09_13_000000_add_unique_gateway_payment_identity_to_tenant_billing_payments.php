<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces at the DB level what the app already relies on:
 * (gateway, gateway_payment_id) identifies at most one payment.
 * findOrCreateTransactionPayment() (Paddle) already keys firstOrCreate()
 * on exactly this pair, and every other gateway's checkout flow assigns a
 * fresh, gateway-unique id per payment row — this closes the race
 * firstOrCreate() alone can't (two concurrent requests both missing, both
 * inserting) and catches any future write path that doesn't go through it.
 *
 * NULL is safe: MySQL/InnoDB treats each NULL as distinct in a unique
 * index, so the many payment rows that legitimately have no
 * gateway_payment_id (manual entries recorded via Super\PaymentController's
 * store(), which never sets this field at all) never collide with each
 * other, regardless of gateway.
 *
 * Empty string ('') is NOT safe the same way — unlike NULL, '' is a real,
 * comparable value, and some gateway client stub/error shapes default to
 * '' rather than null (see e.g. StripeGateway::verifyPaymentStatus()'s
 * failure branch). The only place that value could actually reach this
 * column is TenantBillingPayment::markPaid(), which already guards it
 * (`if ($gatewayPaymentId)` — an empty string is falsy in PHP, so it's
 * silently skipped rather than written). Historical data predating that
 * guard is not guaranteed to be clean, so this migration normalizes any
 * existing '' to NULL before adding the constraint.
 *
 * Safety net: if real duplicate (gateway, gateway_payment_id) pairs exist
 * in production data for any other reason, this migration aborts with a
 * clear, actionable error instead of corrupting or silently dropping rows
 * — those need a human to look at the specific payments before this
 * constraint can land.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('central')->table('tenant_billing_payments')
            ->where('gateway_payment_id', '')
            ->update(['gateway_payment_id' => null]);

        // A unique index only flags a collision when EVERY column in the
        // key is non-null — a NULL gateway (or NULL gateway_payment_id)
        // exempts that row from the constraint entirely. The duplicate scan
        // must apply the exact same exemption, or it reports false-positive
        // "duplicates" for rows that would never actually conflict (e.g. two
        // legacy rows that both have gateway=NULL) and blocks a migration
        // that would have run cleanly.
        $duplicates = DB::connection('central')->table('tenant_billing_payments')
            ->select('gateway', 'gateway_payment_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('gateway')
            ->whereNotNull('gateway_payment_id')
            ->groupBy('gateway', 'gateway_payment_id')
            ->having('c', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates->take(10)->map(
                fn ($row) => "{$row->gateway}/{$row->gateway_payment_id} (x{$row->c})"
            )->implode(', ');

            throw new RuntimeException(
                'Cannot add the unique (gateway, gateway_payment_id) constraint on '
                . 'tenant_billing_payments: duplicate pairs already exist and must be '
                . 'resolved manually first (merge/relabel the extra rows), not silently '
                . "dropped by this migration. Duplicates found: {$sample}"
            );
        }

        Schema::connection('central')->table('tenant_billing_payments', function ($table) {
            $table->unique(['gateway', 'gateway_payment_id'], 'tenant_billing_payments_gateway_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenant_billing_payments', function ($table) {
            $table->dropUnique('tenant_billing_payments_gateway_identity_unique');
        });
    }
};
