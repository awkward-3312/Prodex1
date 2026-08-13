<?php

namespace App\Http\Middleware;

use App\Models\Central\TenantSubscription;
use Closure;
use Illuminate\Http\Request;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();

        if (! $tenant) {
            return $next($request);
        }

        // A tenant can legitimately have more than one row with status
        // active/trial (e.g. a stale row whose ends_at has passed but the
        // expiry cron hasn't flipped it to "expired" yet, plus a freshly
        // renewed/activated row). Grant access if ANY of them is genuinely
        // active rather than gambling on an arbitrary first() row.
        $hasActive = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_TRIAL,
            ])
            ->get()
            ->contains(fn (TenantSubscription $subscription) => $subscription->isActive());

        if (! $hasActive) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status'  => 'subscription_required',
                    'message' => 'Your subscription is inactive or expired. Please renew to continue.',
                ], 403);
            }

            return redirect()->route('billing.plans')
                ->with('error', 'Your subscription is inactive or expired. Please renew to continue.');
        }

        return $next($request);
    }
}
