<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\SubscriptionReminder;
use App\Exceptions\PaddleApiException;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionCancellationService;
use App\Tenant;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class TenantSubscriptionController extends Controller
{
    public function __construct(private SubscriptionCancellationService $cancellation)
    {
    }

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
            return back()->with('error', "No se puede activar la suscripción desde el estado \"{$subscription->status}\".");
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
                        ->with('error', 'El pago debe aprobarse antes de activar esta suscripción. Revisa y aprueba primero el pago pendiente.');
                }

                return back()->with('error', 'No se puede activar la suscripción porque no se encontró un pago confirmado.');
            }
        }

        $subscription->activate();

        return back()->with('success', 'Suscripción activada correctamente.');
    }

    public function suspend(Request $request, TenantSubscription $subscription): RedirectResponse
    {
        if (! $subscription->canTransitionTo(TenantSubscription::STATUS_SUSPENDED)) {
            return back()->with('error', "No se puede suspender la suscripción desde el estado \"{$subscription->status}\".");
        }

        $subscription->transitionTo(TenantSubscription::STATUS_SUSPENDED);

        return back()->with('success', 'Suscripción suspendida.');
    }

    public function cancel(Request $request, TenantSubscription $subscription): RedirectResponse
    {
        if (! $subscription->canTransitionTo(TenantSubscription::STATUS_CANCELLED)) {
            return back()->with('error', "No se puede cancelar la suscripción desde el estado \"{$subscription->status}\".");
        }

        return $this->withSubscriptionLock($subscription, function () use ($subscription) {
            $subscription->refresh();

            if (! $subscription->canTransitionTo(TenantSubscription::STATUS_CANCELLED)) {
                return back()->with('error', "No se puede cancelar la suscripción desde el estado \"{$subscription->status}\".");
            }

            // Admin action = immediate revocation by design (super-admin
            // override), best-effort against Paddle so an unreachable API
            // never blocks it.
            $this->cancellation->cancelImmediately($subscription);

            return back()->with('success', 'Suscripción cancelada.');
        });
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

        return $this->withSubscriptionLock($subscription, function () use ($subscription, $newStatus, $validated) {
            $subscription->refresh();

            // Validate status transition via the state machine.
            if ($newStatus !== $subscription->status) {
                if (! $subscription->canTransitionTo($newStatus)) {
                    return back()
                        ->withInput()
                        ->with('error', "No se puede cambiar la suscripción del estado \"{$subscription->status}\" a \"{$newStatus}\".");
                }

                $extra = collect($validated)->except('status')->toArray();

                if ($newStatus === TenantSubscription::STATUS_CANCELLED) {
                    // Never flip straight to CANCELLED via transitionTo() here —
                    // route through the shared service so Paddle is notified
                    // too, exactly like the dedicated Cancel action.
                    $this->cancellation->cancelImmediately($subscription);
                    if (! empty($extra)) {
                        $subscription->update($extra);
                    }
                } elseif ($newStatus === TenantSubscription::STATUS_ACTIVE && $subscription->isPendingCancellation()) {
                    // A cancellation is still scheduled at Paddle — tell Paddle
                    // to remove it before applying the admin's reactivation
                    // locally. transitionTo() alone would only clear the local
                    // flag and leave Paddle's schedule in place.
                    try {
                        $this->cancellation->resumeScheduledCancellation($subscription);
                    } catch (PaddleApiException $e) {
                        return back()
                            ->withInput()
                            ->with('error', 'No se pudo comunicar la reanudación a Paddle. Intenta de nuevo en unos minutos.');
                    }

                    if (! $subscription->transitionTo($newStatus, $extra)) {
                        return back()
                            ->withInput()
                            ->with('error', "No se pudo cambiar la suscripción al estado \"{$newStatus}\". Verifica que cumpla las condiciones requeridas.");
                    }
                } elseif (! $subscription->transitionTo($newStatus, $extra)) {
                    return back()
                        ->withInput()
                        ->with('error', "No se pudo cambiar la suscripción al estado \"{$newStatus}\". Verifica que cumpla las condiciones requeridas.");
                }
            } else {
                // Status unchanged in the form, but if it's ACTIVE with a
                // cancellation still scheduled at Paddle, saving the form
                // must still be able to resume it — otherwise an admin who
                // doesn't touch the status dropdown can never remove
                // Paddle's schedule through this endpoint.
                if ($newStatus === TenantSubscription::STATUS_ACTIVE && $subscription->isPendingCancellation()) {
                    try {
                        $this->cancellation->resumeScheduledCancellation($subscription);
                    } catch (PaddleApiException $e) {
                        return back()
                            ->withInput()
                            ->with('error', 'No se pudo comunicar la reanudación a Paddle. Intenta de nuevo en unos minutos.');
                    }
                }

                $subscription->update(collect($validated)->except('status')->toArray());
            }

            return redirect()->route('super.subscriptions.index')->with('success', 'Suscripción actualizada.');
        });
    }

    /**
     * Serialize concurrent admin/customer requests for the same subscription
     * — same lock namespace as BillingApiController's withSubscriptionLock()
     * so an admin action and a customer action (or a webhook) can never
     * interleave writes to the same row.
     */
    private function withSubscriptionLock(TenantSubscription $subscription, \Closure $action): RedirectResponse
    {
        $lock = Cache::lock('billing:cancel:'.$subscription->id, 40);

        try {
            return $lock->block(5, $action);
        } catch (LockTimeoutException) {
            return back()->with('error', 'Ya hay una solicitud en curso para esta suscripción. Intenta de nuevo en unos segundos.');
        }
    }
}
