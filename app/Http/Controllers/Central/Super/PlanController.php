<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::withCount('tenantSubscriptions')->orderBy('price')->paginate(20);

        return view('central.super.plans.index', compact('plans'));
    }

    public function create(): View
    {
        $availableLimits = Plan::AVAILABLE_LIMITS;
        $availableFeatures = Plan::AVAILABLE_FEATURES;

        return view('central.super.plans.create', compact('availableLimits', 'availableFeatures'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:255', 'unique:plans,slug'],
            'price'            => ['required', 'numeric', 'min:0'],
            'yearly_price'     => ['required', 'numeric', 'min:0'],
            'is_active'        => ['boolean'],
            'is_private'       => ['boolean'],
            'is_trial'         => ['boolean'],
            'trial_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'limits'           => ['nullable', 'array'],
            'limits.*'         => ['nullable', 'integer', 'min:-1'],
            'features'         => ['nullable', 'array'],
            'features.*'       => ['string'],
        ]);

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['is_private'] = $request->boolean('is_private');
        $validated['is_trial'] = $request->boolean('is_trial');
        $validated['trial_days'] = $validated['is_trial'] ? (int) ($validated['trial_days'] ?? 14) : 0;
        $validated['limits'] = $this->sanitizeLimits($request->input('limits', []));
        $validated['features'] = $request->input('features', []);

        Plan::create($validated);

        return redirect()->route('super.plans.index')->with('success', 'Plan created successfully.');
    }

    public function edit(Plan $plan): View
    {
        $plan->loadCount('tenantSubscriptions');
        $availableLimits = Plan::AVAILABLE_LIMITS;
        $availableFeatures = Plan::AVAILABLE_FEATURES;

        return view('central.super.plans.edit', compact('plan', 'availableLimits', 'availableFeatures'));
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'slug'             => ['required', 'string', 'max:255', 'unique:plans,slug,' . $plan->id],
            'price'            => ['required', 'numeric', 'min:0'],
            'yearly_price'     => ['required', 'numeric', 'min:0'],
            'is_active'        => ['boolean'],
            'is_private'       => ['boolean'],
            'is_trial'         => ['boolean'],
            'trial_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'limits'           => ['nullable', 'array'],
            'limits.*'         => ['nullable', 'integer', 'min:-1'],
            'features'         => ['nullable', 'array'],
            'features.*'       => ['string'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['is_private'] = $request->boolean('is_private');
        $validated['is_trial'] = $request->boolean('is_trial');
        $validated['trial_days'] = $validated['is_trial'] ? (int) ($validated['trial_days'] ?? 14) : 0;
        $validated['limits'] = $this->sanitizeLimits($request->input('limits', []));
        $validated['features'] = $request->input('features', []);

        $plan->update($validated);

        return redirect()->route('super.plans.index')->with('success', 'Plan updated successfully.');
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->tenantSubscriptions()->exists()) {
            return back()->withErrors(['plan' => 'Cannot delete a plan that has active subscriptions.']);
        }

        $plan->delete();

        return redirect()->route('super.plans.index')->with('success', 'Plan deleted.');
    }

    /**
     * Strip out empty/null limit values, keep only valid entries.
     */
    private function sanitizeLimits(array $limits): array
    {
        $clean = [];

        foreach (array_keys(Plan::AVAILABLE_LIMITS) as $key) {
            if (isset($limits[$key]) && $limits[$key] !== '' && $limits[$key] !== null) {
                $value = (int) $limits[$key];
                if ($value !== 0) {
                    $clean[$key] = $value;
                }
            }
        }

        return $clean;
    }
}
