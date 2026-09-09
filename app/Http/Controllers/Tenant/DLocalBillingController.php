<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\CurrencyConversionService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DLocalBillingController extends Controller
{
    public function process(Request $request, $planId)
    {
        $plan = Plan::where('is_active', true)->findOrFail($planId);
        $tenant = tenant();

        if (! $tenant) {
            abort(404);
        }

        if ($plan->isFree()) {
            return redirect()->route('billing.plans')->with('error', 'This plan does not require a payment.');
        }

        $validated = $request->validate([
            'billing_cycle'      => ['required', 'in:monthly,yearly'],
            'dlocal_country'     => ['required', 'string', 'size:2', 'in:HN,GT,SV,NI,CR,PA,MX,CO,PE,CL,BR,AR,UY,PY,BO,DO'],
            'dlocal_name'        => ['required', 'string', 'min:2', 'max:100'],
            'dlocal_document'    => ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9 .-]+$/'],
            'dlocal_birth_date'  => ['required', 'date_format:d-m-Y'],
            'dlocal_phone'       => ['nullable', 'string', 'max:20'],
        ]);

        $existingPayment = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->where('status', TenantBillingPayment::STATUS_PENDING)
            ->first();

        if ($existingPayment) {
            return back()->withErrors([
                'payment' => 'You already have a pending payment. Cancel it before starting another checkout.',
            ]);
        }

        $gateway = PaymentGatewayFactory::resolve('dlocal');
        if (! $gateway) {
            return back()->withErrors(['gateway' => 'dLocal is not available.']);
        }

        $billingCycle = $validated['billing_cycle'];
        $amount = $plan->getPriceForCycle($billingCycle);
        $systemCurrency = GeneralSetting::currencyCode();

        $activeSub = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->latest()
            ->get()
            ->first(fn (TenantSubscription $sub) => $sub->isActive());

        $existingPending = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_PENDING)
            ->latest()
            ->first();

        if ($activeSub && $existingPending && $existingPending->id !== $activeSub->id) {
            return back()->withErrors([
                'plan' => 'You already have a pending upgrade request. Please cancel it before starting another one.',
            ]);
        }

        if ($existingPending) {
            $existingPending->update([
                'plan_id'       => $plan->id,
                'billing_cycle' => $billingCycle,
                'amount'        => $amount,
                'currency'      => $systemCurrency,
                'starts_at'     => $existingPending->starts_at ?? now(),
            ]);
            $subscription = $existingPending;
        } elseif (! $activeSub) {
            $subscription = TenantSubscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $billingCycle,
                'amount'        => $amount,
                'currency'      => $systemCurrency,
                'status'        => TenantSubscription::STATUS_PENDING,
                'starts_at'     => now(),
            ]);
        } else {
            $subscription = TenantSubscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $billingCycle,
                'amount'        => $amount,
                'currency'      => $systemCurrency,
                'status'        => TenantSubscription::STATUS_PENDING,
                'starts_at'     => now(),
            ]);
        }

        try {
            $targetCurrency = PaymentGatewayFactory::getDLocalCountryCurrency($validated['dlocal_country']);
            $conversion = CurrencyConversionService::resolve(
                (float) $amount,
                $systemCurrency,
                [$targetCurrency],
                $targetCurrency
            );

            $lifecycle = app(SubscriptionLifecycleService::class);
            $payment = $lifecycle->createPayment($subscription, [
                'amount'             => $amount,
                'currency'           => $systemCurrency,
                'gateway_currency'   => $conversion['gateway_currency'],
                'gateway_amount'     => $conversion['gateway_amount'],
                'exchange_rate'      => $conversion['exchange_rate'],
                'conversion_applied' => $conversion['conversion_applied'],
                'billing_cycle'      => $billingCycle,
                'gateway'            => 'dlocal',
                'metadata'           => [
                    'dlocal_country' => strtoupper($validated['dlocal_country']),
                    'dlocal_payer' => [
                        'name'           => $validated['dlocal_name'],
                        'email'          => (string) ($tenant->admin_email ?? ''),
                        'document'       => $validated['dlocal_document'],
                        'birth_date'     => $validated['dlocal_birth_date'],
                        'phone'          => $validated['dlocal_phone'] ?? ($tenant->owner_phone ?? null),
                        'user_reference' => (string) $tenant->id,
                        'ip'             => $request->ip(),
                    ],
                ],
            ]);

            $successUrl = route('billing.success') . '?payment=' . $payment->id;
            $cancelUrl = route('billing.failed') . '?payment=' . $payment->id;
            $checkoutUrl = $gateway->createCheckoutSession($payment, $successUrl, $cancelUrl);

            return redirect()->away($checkoutUrl);
        } catch (\Throwable $e) {
            Log::error('dLocal tenant billing checkout failed.', [
                'tenant_id' => $tenant->id,
                'plan_id'   => $plan->id,
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);

            if (isset($payment) && $payment->isPending()) {
                $payment->markFailed();
            }

            // Keep an existing active subscription untouched. A new pending
            // upgrade row can safely remain failed/pending for audit and retry.
            return back()->withInput()->withErrors([
                'payment' => 'Unable to initiate dLocal payment. Please verify your information and try again.',
            ]);
        }
    }
}
