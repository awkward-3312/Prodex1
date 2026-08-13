<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\SubscriptionReminder;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantSubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $query = TenantSubscription::with([
            'tenant',
            'plan',
            'payments' => fn ($q) => $q->where('status', '!=', TenantBillingPayment::STATUS_SUPERSEDED)
                                       ->latest()
                                       ->limit(1),
        ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('tenant_id', 'like', "%{$search}%")
                  ->orWhereHas('tenant', function ($q) use ($search) {
                      $q->where('data->company_name', 'like', "%{$search}%")
                        ->orWhere('data->admin_email', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $subscriptions = $query->orderByDesc('created_at')->paginate(20)->withQueryString();

        // Identify tenants that have both an active AND a pending subscription (pending upgrades).
        $pendingUpgradeSubIds = collect();
        $tenantIds = $subscriptions->pluck('tenant_id')->unique();
        if ($tenantIds->isNotEmpty()) {
            $activeTenants = TenantSubscription::whereIn('tenant_id', $tenantIds)
                ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
                ->pluck('tenant_id')
                ->unique();

            if ($activeTenants->isNotEmpty()) {
                $pendingUpgradeSubIds = TenantSubscription::whereIn('tenant_id', $activeTenants)
                    ->where('status', TenantSubscription::STATUS_PENDING)
                    ->pluck('id');
            }
        }

        return view('central.super.subscriptions.index', compact('subscriptions', 'pendingUpgradeSubIds'));
    }

    /**
     * Audit log of automatic subscription / trial reminders.
     */
    public function reminders(Request $request): View
    {
        $query = SubscriptionReminder::with(['tenant', 'subscription.plan'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id');

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($channel = $request->input('channel')) {
            $query->where('channel', $channel);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('tenant_id', 'like', "%{$search}%")
                  ->orWhere('target', 'like', "%{$search}%")
                  ->orWhereHas('tenant', function ($q) use ($search) {
                      $q->where('data->company_name', 'like', "%{$search}%")
                        ->orWhere('data->admin_email', 'like', "%{$search}%");
                  });
            });
        }

        $reminders = $query->paginate(30)->withQueryString();

        return view('central.super.subscriptions.reminders', compact('reminders'));
    }

    public function show(TenantSubscription $subscription): View
    {
        $subscription->load([
            'tenant.domains',
            'plan',
            'payments' => fn ($q) => $q->where('status', '!=', TenantBillingPayment::STATUS_SUPERSEDED)
                                       ->latest(),
        ]);

        return view('central.super.subscriptions.show', compact('subscription'));
    }

    public function activate(Request $request, TenantSubscription $subscription): RedirectResponse
    {
        if (! $subscription->canTransitionTo(TenantSubscription::STATUS_ACTIVE)) {
            return back()->with('error', "Cannot activate subscription from \"{$subscription->status}\" status.");
        }

        // Pending subscriptions require at least one paid payment before activation.
        if ($subscription->status === TenantSubscription::STATUS_PENDING) {
            $hasPaidPayment = $subscription->payments()
                ->where('status', TenantBillingPayment::STATUS_PAID)
                ->exists();

            if (! $hasPaidPayment) {
                $pendingPayment = $subscription->payments()
                    ->where('status', TenantBillingPayment::STATUS_PENDING)
                    ->latest()
                    ->first();

                if ($pendingPayment) {
                    return redirect()->route('super.payments.show', $pendingPayment)
                        ->with('error', 'Payment must be approved before activating this subscription. Please review and approve the payment first.');
                }

                return back()->with('error', 'Cannot activate: no confirmed payment found for this subscription.');
            }
        }

        $subscription->activate();

        return back()->with('success', 'Subscription activated successfully.');
    }

    public function suspend(Request $request, TenantSubscription $subscription): RedirectResponse
    {
        if (! $subscription->canTransitionTo(TenantSubscription::STATUS_SUSPENDED)) {
            return back()->with('error', "Cannot suspend subscription from \"{$subscription->status}\" status.");
        }

        $subscription->transitionTo(TenantSubscription::STATUS_SUSPENDED);

        return back()->with('success', 'Subscription suspended.');
    }

    public function cancel(Request $request, TenantSubscription $subscription): RedirectResponse
    {
        if (! $subscription->canTransitionTo(TenantSubscription::STATUS_CANCELLED)) {
            return back()->with('error', "Cannot cancel subscription from \"{$subscription->status}\" status.");
        }

        $subscription->cancel();

        return back()->with('success', 'Subscription cancelled.');
    }

    public function edit(TenantSubscription $subscription): View
    {
        $subscription->load(['tenant.domains', 'plan']);
        $plans = Plan::where('is_active', true)->orderBy('price')->get();

        return view('central.super.subscriptions.edit', compact('subscription', 'plans'));
    }

    public function update(Request $request, TenantSubscription $subscription): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id'       => ['required', 'exists:plans,id'],
            'status'        => ['required', 'in:pending,active,trial,cancelled,suspended,expired,failed'],
            'trial_ends_at' => ['nullable', 'date'],
            'ends_at'       => ['nullable', 'date'],
        ]);

        $newStatus = $validated['status'];

        // Block activating a subscription if the tenant has not been provisioned.
        $tenant = $subscription->tenant;
        $tenantIsProvisioned = in_array($tenant->status, [
            Tenant::STATUS_ACTIVE,
            Tenant::STATUS_SUSPENDED,
            Tenant::STATUS_CANCELLED,
        ], true);

        if (! $tenantIsProvisioned && $newStatus === 'active') {
            return back()
                ->withInput()
                ->with('error', __('super.tenants.activate_requires_provision_error'));
        }

        // Validate status transition via the state machine.
        if ($newStatus !== $subscription->status) {
            if (! $subscription->canTransitionTo($newStatus)) {
                return back()
                    ->withInput()
                    ->with('error', "Cannot transition subscription from \"{$subscription->status}\" to \"{$newStatus}\".");
            }

            $extra = collect($validated)->except('status')->toArray();

            if (! $subscription->transitionTo($newStatus, $extra)) {
                return back()
                    ->withInput()
                    ->with('error', "Transition to \"{$newStatus}\" failed — check that the subscription meets the required conditions.");
            }
        } else {
            // Status unchanged — just update the other fields.
            $subscription->update(collect($validated)->except('status')->toArray());
        }

        return redirect()->route('super.subscriptions.index')->with('success', 'Subscription updated.');
    }
}
