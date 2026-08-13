<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\PromotionUsage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PromotionEngine
{
    /**
     * Resolve which promotions apply to a given cart context and return them sorted
     * with the highest-priority entry first.
     *
     * Constraints applied (in order):
     *   1. Promotion must be active.
     *   2. Warehouse must be one of the promotion's assigned warehouses.
     *   3. `now` must fall within the [starts_at, ends_at] window when set.
     *   4. `now` must fall within the daily time window when set.
     *   5. Cart subtotal must meet `min_cart_total` when set.
     *   6. Item count must meet `min_item_count` when set.
     *   7. When `product_scope` is 'specific', at least one cart product_id must
     *      appear in the promotion's assigned products.
     *   8. Code-gated promotions (those with a non-null `code`) require an exact
     *      match against the `code` value supplied in the context.
     *   9. Total usage cap (`usage_limit_total`) and per-customer usage cap
     *      (`usage_limit_per_customer`) must not be exhausted.
     *
     * Stacking rules:
     *   - The returned collection is sorted by priority desc, then discount desc.
     *   - When the highest-priority entry is `stackable=false`, only it is returned.
     *   - When the highest-priority entry is `stackable=true`, all stackable entries
     *     that pass the conditions are included.
     *
     * @param  array{
     *   warehouse_id: int,
     *   subtotal?: float,
     *   item_count?: int,
     *   product_ids?: array<int,int>,
     *   product_subtotals?: array<int, array{product_id:int, subtotal:float}>,
     *   code?: ?string,
     *   client_id?: ?int,
     *   now?: \Carbon\Carbon|string|null,
     * }  $ctx
     * @return Collection<int, Promotion>
     */
    public function applicable(array $ctx): Collection
    {
        $warehouseId = (int) ($ctx['warehouse_id'] ?? 0);
        $subtotal    = (float) ($ctx['subtotal'] ?? 0);
        $itemCount   = (int) ($ctx['item_count'] ?? 0);
        $productIds  = array_values(array_unique(array_map('intval', (array) ($ctx['product_ids'] ?? []))));
        $rawCode     = $ctx['code'] ?? null;
        $code        = $rawCode !== null && $rawCode !== '' ? trim((string) $rawCode) : null;
        $clientId    = isset($ctx['client_id']) && $ctx['client_id'] !== '' ? (int) $ctx['client_id'] : null;
        $now         = $this->resolveNow($ctx['now'] ?? null);

        if ($warehouseId <= 0) {
            return collect();
        }

        $candidates = Promotion::query()
            ->with(['warehouses:id', 'products:id'])
            ->where('is_active', true)
            ->whereHas('warehouses', fn ($q) => $q->where('warehouses.id', $warehouseId))
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($q) use ($code) {
                // Promotions with a non-null `code` are code-gated: they only enter
                // the candidate pool when the caller supplied a matching code.
                $q->whereNull('code')->orWhere('code', '');
                if ($code !== null) {
                    $q->orWhere('code', $code);
                }
            })
            ->get();

        $matches = $candidates->filter(function (Promotion $p) use ($now, $subtotal, $itemCount, $productIds, $clientId) {
            if (! $this->withinTimeOfDay($p, $now)) {
                return false;
            }
            if ($p->min_cart_total !== null && (float) $p->min_cart_total > 0 && $subtotal < (float) $p->min_cart_total) {
                return false;
            }
            if ($p->min_item_count !== null && (int) $p->min_item_count > 0 && $itemCount < (int) $p->min_item_count) {
                return false;
            }
            if ($p->product_scope === 'specific') {
                $eligibleIds = $p->products->pluck('id')->map(fn ($v) => (int) $v)->all();
                if (empty($eligibleIds) || empty(array_intersect($eligibleIds, $productIds))) {
                    return false;
                }
            }
            if (! $this->withinUsageCaps($p, $clientId)) {
                return false;
            }

            return true;
        })->values();

        $sorted = $matches->sortByDesc(function (Promotion $p) use ($subtotal) {
            return [$p->priority, $p->computeDiscount($subtotal)];
        })->values();

        if ($sorted->isEmpty()) {
            return $sorted;
        }

        $top = $sorted->first();
        if (! $top->stackable) {
            return collect([$top]);
        }

        return $sorted->filter(fn (Promotion $p) => $p->stackable)->values();
    }

    /**
     * Compute the total discount (in account currency) for the cart context, based
     * on the applicable promotions. Returns an array describing each applied
     * promotion so the caller can render a receipt line per offer.
     *
     * @param  array  $ctx
     * @return array{total_discount: float, applied: array<int, array{id:int,name:string,kind:string,amount:float,code:?string}>}
     */
    public function applyTo(array $ctx): array
    {
        $applicable = $this->applicable($ctx);
        $subtotal = (float) ($ctx['subtotal'] ?? 0);

        // Per-product subtotal map (product_id => summed line subtotal). Used to
        // scope specific-product promotions to the eligible lines only, instead
        // of applying the discount to the whole cart total.
        $productSubtotals = [];
        foreach ((array) ($ctx['product_subtotals'] ?? []) as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $line = (float) ($row['subtotal'] ?? 0);
            $productSubtotals[$pid] = ($productSubtotals[$pid] ?? 0) + $line;
        }

        $applied = [];
        $remaining = $subtotal;

        foreach ($applicable as $promotion) {
            if ($promotion->product_scope === 'specific' && ! empty($productSubtotals)) {
                // Discount only what the eligible products contribute to the cart.
                // Requires the caller to provide per-line subtotals; if absent, we
                // fall back to discounting the remaining cart total so old clients
                // don't silently zero out the discount.
                $eligibleIds = $promotion->products->pluck('id')->map(fn ($v) => (int) $v)->all();
                $eligibleSubtotal = 0.0;
                foreach ($eligibleIds as $pid) {
                    if (isset($productSubtotals[$pid])) {
                        $eligibleSubtotal += $productSubtotals[$pid];
                    }
                }
                $base = min($eligibleSubtotal, $remaining);
            } else {
                // Each subsequent stackable promotion discounts the remaining amount,
                // never the original subtotal, to avoid going below zero.
                $base = $remaining;
            }

            $amount = $promotion->computeDiscount($base);
            if ($amount <= 0) {
                continue;
            }
            $applied[] = [
                'id' => (int) $promotion->id,
                'name' => $promotion->name,
                'kind' => $promotion->kind,
                'amount' => $amount,
                'code' => $promotion->code,
            ];
            $remaining = max(0, $remaining - $amount);
        }

        return [
            'total_discount' => round($subtotal - $remaining, 2),
            'applied' => $applied,
        ];
    }

    /**
     * Look up the single promotion bound to a code, with the same filtering as
     * applicable() but explicit so a POS can tell the user *why* a code didn't
     * activate (expired, exhausted, wrong warehouse, etc).
     *
     * @return array{ok:bool, reason:?string, promotion:?Promotion}
     */
    public function lookupCode(string $code, array $ctx): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'reason' => 'Empty code.', 'promotion' => null];
        }

        $warehouseId = (int) ($ctx['warehouse_id'] ?? 0);
        $promotion = Promotion::query()
            ->with(['warehouses:id', 'products:id'])
            ->where('code', $code)
            ->first();

        if (! $promotion) {
            return ['ok' => false, 'reason' => 'Unknown code.', 'promotion' => null];
        }

        if (! $promotion->is_active) {
            return ['ok' => false, 'reason' => 'This code is currently inactive.', 'promotion' => $promotion];
        }

        if ($warehouseId > 0) {
            $warehouseIds = $promotion->warehouses->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (! in_array($warehouseId, $warehouseIds, true)) {
                return ['ok' => false, 'reason' => 'This code is not valid at this warehouse.', 'promotion' => $promotion];
            }
        }

        $now = $this->resolveNow($ctx['now'] ?? null);
        if ($promotion->starts_at && $now->lt($promotion->starts_at)) {
            return ['ok' => false, 'reason' => 'This code is not yet active.', 'promotion' => $promotion];
        }
        if ($promotion->ends_at && $now->gt($promotion->ends_at)) {
            return ['ok' => false, 'reason' => 'This code has expired.', 'promotion' => $promotion];
        }
        if (! $this->withinTimeOfDay($promotion, $now)) {
            return ['ok' => false, 'reason' => 'This code is only valid at certain hours.', 'promotion' => $promotion];
        }

        $clientId = isset($ctx['client_id']) && $ctx['client_id'] !== '' ? (int) $ctx['client_id'] : null;
        if (! $this->withinUsageCaps($promotion, $clientId)) {
            return ['ok' => false, 'reason' => 'This code has reached its usage limit.', 'promotion' => $promotion];
        }

        return ['ok' => true, 'reason' => null, 'promotion' => $promotion];
    }

    private function withinUsageCaps(Promotion $p, ?int $clientId): bool
    {
        if ($p->usage_limit_total !== null && (int) $p->usage_limit_total > 0) {
            $used = PromotionUsage::where('promotion_id', $p->id)->count();
            if ($used >= (int) $p->usage_limit_total) {
                return false;
            }
        }

        if ($clientId && $p->usage_limit_per_customer !== null && (int) $p->usage_limit_per_customer > 0) {
            $usedByCustomer = PromotionUsage::where('promotion_id', $p->id)
                ->where('client_id', $clientId)
                ->count();
            if ($usedByCustomer >= (int) $p->usage_limit_per_customer) {
                return false;
            }
        }

        return true;
    }

    private function resolveNow($value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable $e) {
                // fall through to now()
            }
        }

        return Carbon::now();
    }

    private function withinTimeOfDay(Promotion $p, Carbon $now): bool
    {
        if (! $p->time_of_day_start && ! $p->time_of_day_end) {
            return true;
        }

        $current = $now->format('H:i:s');
        $start = $p->time_of_day_start ?: '00:00:00';
        $end   = $p->time_of_day_end   ?: '23:59:59';

        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }

        // Inverted window (crosses midnight), e.g. 22:00 → 02:00.
        return $current >= $start || $current <= $end;
    }
}
