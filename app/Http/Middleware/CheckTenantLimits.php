<?php

namespace App\Http\Middleware;

use App\Services\TenantLimitsService;
use Closure;
use Illuminate\Http\Request;

class CheckTenantLimits
{
    protected TenantLimitsService $limits;

    public function __construct(TenantLimitsService $limits)
    {
        $this->limits = $limits;
    }

    /**
     * Usage: middleware('tenant.limit:max_products')
     *        middleware('tenant.limit:max_users')
     *
     * Only blocks create/store actions (POST). GET/PUT/DELETE pass through
     * so that tenants can still view and manage existing resources.
     */
    public function handle(Request $request, Closure $next, string ...$limitKeys)
    {
        if (empty($limitKeys)) {
            return $next($request);
        }

        // Only enforce on creation (POST requests)
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        foreach ($limitKeys as $limitKey) {
            if ($this->limits->hasReachedLimit($limitKey)) {
                return $this->deny($request, $limitKey);
            }
        }

        return $next($request);
    }

    protected function deny(Request $request, string $limitKey): mixed
    {
        $meta = \App\Models\Central\Plan::AVAILABLE_LIMITS[$limitKey] ?? null;
        $label = $meta['label'] ?? ucfirst(str_replace('_', ' ', $limitKey));
        $max = $this->limits->getMaxAllowed($limitKey);
        $current = $this->limits->getCurrentUsage($limitKey);

        $message = "You have reached the maximum number of {$label} ({$current}/{$max}) allowed by your current plan. Please upgrade to add more.";

        if ($request->expectsJson()) {
            return response()->json([
                'status'   => 'limit_reached',
                'message'  => $message,
                'limit'    => $limitKey,
                'current'  => $current,
                'max'      => $max,
            ], 403);
        }

        return redirect()->back()->with('error', $message);
    }
}
