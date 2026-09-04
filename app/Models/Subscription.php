<?php

namespace App\Models;

use App\Services\ExternalChannelInventoryService;
use App\Services\LocationAwareSaleStockService;
use App\Services\WarehouseInventoryModeResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'user_id',
        'client_id',
        'product_id',
        'warehouse_id', // Warehouse selection added
        'total_cycles',
        'cycle_type', // e.g., 12 for monthly (1 year), 52 for weekly
        'billing_cycle', // monthly, weekly, yearly
        'remaining_cycles', // Decreases with each payment
        'price_per_cycle',
        'price_per_unit',
        'quantity',
        'next_billing_date',
        'status', // active, canceled,
    ];

    protected $casts = [
        'client_id' => 'integer',
        'user_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'total_cycles' => 'integer',
        'remaining_cycles' => 'integer',
        'price_per_cycle' => 'double',
        'price_per_unit' => 'double',
        'quantity' => 'double',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function invoices()
    {
        return $this->hasMany(Sale::class, 'subscription_id');
    }

    /**
     * MS7-B2-4 — the single, safe entry point for subscription billing.
     * Self-contained and idempotent: locks this subscription row, re-verifies
     * it is still due (protects against a double scheduler run or a second
     * concurrent caller — GenerateSubscriptionInvoices AND
     * SubscriptionController::store()'s immediate first-cycle invoice both
     * call this same method), creates the Sale/SaleDetail/payment, applies
     * the physical stock effect (location-native for a MODE_LOCATION_PRIMARY
     * warehouse — reusing LocationAwareSaleStockService, the SAME engine
     * MS7-B1/B2-1/B2-3 use, never a parallel one — legacy product_warehouse
     * otherwise, now guarded against going negative), and ONLY THEN advances
     * remaining_cycles/status/next_billing_date — all inside ONE
     * transaction, so a failure anywhere (validation, insufficient stock,
     * payment) rolls back the whole document: no orphaned Sale, no stock
     * drift, no billing date advanced without a physical effect behind it.
     *
     * Returns null when there is nothing to do (already processed, cancelled,
     * exhausted, or not yet due) — never throws for that; throws only for a
     * genuine failure (missing product/warehouse/location, insufficient
     * stock, an untracked batch/serial product) so the caller can log it
     * without silently reporting success.
     */
    public function generateInvoice(): ?Sale
    {
        return DB::transaction(function () {
            $subscription = static::whereKey($this->id)->lockForUpdate()->first();
            if (! $subscription
                || $subscription->status !== 'active'
                || (int) $subscription->remaining_cycles <= 0
                || Carbon::parse($subscription->next_billing_date)->gt(Carbon::today())) {
                return null; // already billed this period by a concurrent run, or no longer due.
            }

            $warehouseId = (int) $subscription->warehouse_id;
            if (! $warehouseId) {
                throw new \RuntimeException("Subscription #{$subscription->id}: no warehouse configured.");
            }

            $product = Product::find($subscription->product_id);
            if (! $product) {
                throw new \RuntimeException("Subscription #{$subscription->id}: product #{$subscription->product_id} not found.");
            }

            // MS7-B2-4 — a subscription warehouse in MODE_LOCATION_PRIMARY
            // goes location-native; every other mode keeps the exact legacy
            // product_warehouse flow. No variant support exists on
            // Subscription today (no product_variant_id column) — not
            // invented here.
            $isNative = app(WarehouseInventoryModeResolver::class)->isLocationPrimary($warehouseId);
            $location = null;
            $validated = null;

            if ($isNative) {
                try {
                    $location = app(ExternalChannelInventoryService::class)->resolveFulfillmentLocation($warehouseId);
                } catch (ValidationException $e) {
                    throw new \RuntimeException("Subscription #{$subscription->id}: ".$e->validator->errors()->first());
                }

                $rawLine = [[
                    'product_id' => $subscription->product_id,
                    'product_variant_id' => null,
                    'quantity' => (float) $subscription->quantity,
                    'sale_unit_id' => null,
                    'pack_multiplier' => 1,
                ]];

                try {
                    $validated = app(LocationAwareSaleStockService::class)->validateAndLock($rawLine);
                } catch (ValidationException $e) {
                    throw new \RuntimeException("Subscription #{$subscription->id}: ".$e->validator->errors()->first());
                }

                // §10/§11/§12 — a recurring automatic charge is never a
                // physical fulfillment decision: no auto-FEFO batch pick, no
                // auto-selected serial. FAIL CLOSED for a tracked product.
                foreach ($validated['lines'] as $line) {
                    if (($line['requires_batch'] ?? false) || ($line['requires_serial'] ?? false)) {
                        throw new \RuntimeException("Subscription #{$subscription->id}: product requires physical batch or serial/IMEI assignment; cannot auto-bill.");
                    }
                }

                $have = app(ExternalChannelInventoryService::class)->availableQuantity($location->id, $subscription->product_id, null);
                if ($have < $validated['lines'][0]['quantity_base']) {
                    throw new \RuntimeException("Subscription #{$subscription->id}: insufficient stock (need {$validated['lines'][0]['quantity_base']}, have {$have}).");
                }
            } else {
                // Legacy — lock + verify sufficiency BEFORE decrementing so a
                // subscription can never push product_warehouse negative
                // (§9 — the ONLY behavioural change on the legacy branch).
                $pw = product_warehouse::where('warehouse_id', $warehouseId)
                    ->where('product_id', $subscription->product_id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                $need = (float) $subscription->quantity;
                if ($pw && (float) $pw->qte < $need) {
                    throw new \RuntimeException("Subscription #{$subscription->id}: insufficient stock (need {$need}, have {$pw->qte}).");
                }
            }

            $sale = Sale::create([
                'date' => now(),
                'Ref' => 'SUB-'.strtoupper(uniqid()), // Unique reference
                'is_pos' => false,
                'subscription_id' => $subscription->id,
                'client_id' => $subscription->client_id,
                'GrandTotal' => $subscription->price_per_cycle,
                'warehouse_id' => $warehouseId,
                'inventory_location_id' => $isNative ? $location->id : null,
                'user_id' => $subscription->user_id,
                'statut' => 'completed',
                'discount' => 0,
                'shipping' => 0,
                'paid_amount' => 0,
                'payment_statut' => 'unpaid',
                'shipping_status' => 'pending',
            ]);

            // Fetch product unit for the sale detail line (display only —
            // never used for a base-quantity conversion; Subscription never
            // had a real unit_id column, so this dead lookup never actually
            // affected the decrement amount even in the legacy code).
            $unit = $product->unitSale?->id ?? null;

            $detail = SaleDetail::create([
                'sale_id' => $sale->id,
                'date' => now(),
                'sale_unit_id' => $unit,
                'quantity' => $subscription->quantity,
                'product_id' => $subscription->product_id,
                'total' => $subscription->price_per_cycle,
                'price' => $subscription->price_per_unit,
            ]);

            if ($isNative) {
                $svc = app(LocationAwareSaleStockService::class);
                $line = $validated['lines'][0];
                $snapshot = $svc->buildSnapshot(LocationAwareSaleStockService::DOC_SALE, $warehouseId, $location->id, [[
                    'source_detail_id' => $detail->id,
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'quantity_base' => $line['quantity_base'],
                ]], 1);
                $sale->update(['inventory_effect_snapshot' => $snapshot]);
                $svc->applySnapshot($snapshot, $sale->id);
            } else {
                $pw = product_warehouse::where('warehouse_id', $warehouseId)
                    ->where('product_id', $subscription->product_id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                if ($pw) {
                    $pw->qte = (float) $pw->qte - (float) $subscription->quantity;
                    $pw->save();
                }
            }

            // Payment side effect — INSIDE the same atomic unit (§15): a
            // failure here rolls back the Sale and the stock effect too.
            PaymentSale::create([
                'sale_id' => $sale->id,
                'date' => now(),
                'montant' => $sale->GrandTotal,
                'Ref' => app('App\Http\Controllers\PaymentSalesController')->getNumberOrder(),
                'change' => 0,
                'payment_method_id' => null,
                'user_id' => $sale->user_id ?? 1,
                'notes' => 'Auto payment for subscription #'.$subscription->id,
            ]);

            // Billing state advances ONLY after the physical + financial
            // effects above have fully succeeded (§14/§20) — never before.
            $subscription->remaining_cycles -= 1;
            if ($subscription->remaining_cycles <= 0) {
                $subscription->status = 'completed';
            }
            $subscription->next_billing_date = match ($subscription->billing_cycle) {
                'weekly' => Carbon::parse($subscription->next_billing_date)->addWeek(),
                'monthly' => Carbon::parse($subscription->next_billing_date)->addMonth(),
                'yearly' => Carbon::parse($subscription->next_billing_date)->addYear(),
            };
            $subscription->save();

            // Keep the caller's in-memory copy ($this) in sync with what was
            // actually persisted (existing callers read $subscription->id
            // etc. right after calling this method).
            $this->setRawAttributes($subscription->getAttributes(), true);

            return $sale;
        });
    }
}
