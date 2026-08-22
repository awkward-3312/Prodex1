<?php

namespace App\Http\Middleware;

use App\Services\TenantLimitsService;
use Closure;
use Illuminate\Http\Request;

class CheckTenantFeature
{
    protected TenantLimitsService $limits;

    public function __construct(TenantLimitsService $limits)
    {
        $this->limits = $limits;
    }

    /**
     * Usage: middleware('tenant.feature:pos')
     *        middleware('tenant.feature:hrm,accounting')  ← requires ANY listed feature.
     *
     * All checks go through TenantLimitsService so core operational capabilities
     * (for example stock transfers) remain available across active plans even if
     * an older plan row still carries a disabled feature flag.
     */
    public function handle(Request $request, Closure $next, string ...$features)
    {
        if (empty($features)) {
            return $next($request);
        }

        if (! $this->limits->getActivePlan()) {
            return $this->deny($request, $features);
        }

        foreach ($features as $feature) {
            if ($this->limits->hasFeature($feature)) {
                return $next($request);
            }
        }

        return $this->deny($request, $features);
    }

    protected function deny(Request $request, array $features): mixed
    {
        $label = $this->featureLabel($features[0] ?? '');

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'feature_unavailable',
                'message' => "The \"{$label}\" feature is not available in your current plan. Please upgrade to access it.",
            ], 403);
        }

        return redirect()->back()
            ->with('error', "The \"{$label}\" feature is not available in your current plan. Please upgrade to access it.");
    }

    protected function featureLabel(string $key): string
    {
        return \App\Models\Central\Plan::AVAILABLE_FEATURES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
