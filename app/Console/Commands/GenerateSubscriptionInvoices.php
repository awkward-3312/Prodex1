<?php

namespace App\Console\Commands;

use App\Models\ErrorLog;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateSubscriptionInvoices extends Command
{
    protected $signature = 'subscriptions:generate-invoices';

    protected $description = 'Generate invoices and auto-charge clients via Flutterwave';

    public function handle()
    {
        $today = Carbon::today();

        // MS7-B2-4 — only the ids are read here; each subscription is
        // re-fetched and row-locked INSIDE Subscription::generateInvoice()'s
        // own transaction, which re-verifies it is still due before doing
        // anything. This is what makes a double scheduler run (or this
        // command racing SubscriptionController::store()'s immediate
        // first-cycle invoice) safe: whichever caller wins the lock first
        // commits, and the other sees the now-advanced next_billing_date and
        // no-ops.
        $subscriptionIds = Subscription::whereDate('next_billing_date', '<=', $today)
            ->where('remaining_cycles', '>', 0)
            ->where('status', 'active')
            ->pluck('id');

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($subscriptionIds as $subscriptionId) {
            try {
                $subscription = Subscription::find($subscriptionId);
                if (! $subscription) {
                    $skipped++;
                    continue;
                }

                // Sale + SaleDetail + physical stock effect (native or
                // legacy) + payment + billing-state advance all happen
                // atomically inside generateInvoice() — see its own
                // docblock. A failure anywhere in there throws and rolls
                // back the whole document; it never leaves an orphaned
                // Sale, a stock drift, or an advanced billing date behind.
                $invoice = $subscription->generateInvoice();

                if (! $invoice) {
                    // Already processed by a concurrent run, or no longer
                    // eligible by the time the lock was acquired — a safe,
                    // silent no-op, not a failure.
                    $skipped++;
                    continue;
                }

                $processed++;

                // SMS — non-blocking, AFTER the billing transaction has
                // committed (an external call must never run while holding
                // the subscription/stock row locks above).
                try {
                    app('App\Http\Controllers\SalesController')->Send_Subscription_Payment_Success_SMS($subscriptionId, $invoice->id);
                    Log::info("SMS sent after successful payment for subscription #{$subscriptionId}");
                } catch (\Exception $e) {
                    Log::error("Failed sending SMS for subscription #{$subscriptionId}: ".$e->getMessage());

                    ErrorLog::create([
                        'context' => 'SMS after auto-charge success',
                        'message' => "Failed sending SMS for subscription #{$subscriptionId}",
                        'details' => json_encode([
                            'subscription_id' => $subscriptionId,
                            'client_id' => $subscription->client->id ?? null,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]),
                    ]);
                }
            } catch (\Throwable $e) {
                // MS7-B2-4 (§6) — one subscription's failure (insufficient
                // stock, missing fulfillment location, an untracked
                // batch/serial product, ...) never corrupts the others: it
                // was already rolled back inside its own transaction, and
                // the loop simply continues.
                $failed++;
                Log::error("Subscription billing failed for #{$subscriptionId}: ".$e->getMessage());

                ErrorLog::create([
                    'context' => 'Subscription auto-billing',
                    'message' => "Failed billing subscription #{$subscriptionId}",
                    'details' => json_encode([
                        'subscription_id' => $subscriptionId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]),
                ]);
            }
        }

        $this->info("✅ Subscription invoices: {$processed} processed, {$skipped} skipped, {$failed} failed.");
    }
}
