<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\EmailNotificationService;
use App\Services\CurrencyConversionService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use App\Services\PaymentGateways\PaypalGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingApiController extends Controller
{
    private function authorizeBilling(): void
    {
        $user = auth()->user();
        if ($user && ($user->id === 1 || $this->hasBillingPermission($user))) {
            return;
        }
        abort(403, 'No estás autorizado para acceder a facturación.');
    }

    private function hasBillingPermission($user): bool
    {
        if (method_exists($user, 'hasRole') && method_exists($user, 'roles')) {
            $roles = $user->roles;
            foreach ($roles as $role) {
                $perms = $role->permissions->pluck('name')->toArray();
                if (in_array('billing_view', $perms, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function currentPlan(): JsonResponse
    {
        $this->authorizeBilling();
        $tenant = tenant();

        $activeSub = TenantSubscription::with('plan')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_TRIAL,
                TenantSubscription::STATUS_CANCELLED,
            ])
            ->latest()
            ->first();

        $pendingSub = TenantSubscription::with('plan')
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_PENDING)
            ->latest()
            ->first();

        $subscription = $activeSub ?? $pendingSub ?? TenantSubscription::with('plan')
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'subscription'    => null,
                'has_active'      => false,
                'pending_upgrade' => null,
                'currency_code'   => GeneralSetting::currencyCode(),
                'currency_symbol' => GeneralSetting::currencySymbol(),
            ]);
        }

        $plan = $subscription->plan;
        $pendingUpgrade = null;
        if ($activeSub && $pendingSub && $pendingSub->id !== $activeSub->id) {
            $pendingPlan = $pendingSub->plan;
            $pendingUpgrade = [
                'id'             => $pendingSub->id,
                'plan_id'        => $pendingSub->plan_id,
                'plan_name'      => $pendingPlan?->name,
                'billing_cycle'  => $pendingSub->billing_cycle,
                'amount'         => (float) $pendingSub->amount,
                'currency'       => $pendingSub->currency,
                'created_at'     => $pendingSub->created_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'subscription' => [
                'id'             => $subscription->id,
                'status'         => $subscription->status,
                'billing_cycle'  => $subscription->billing_cycle,
                'amount'         => (float) $subscription->amount,
                'currency'       => $subscription->currency,
                'starts_at'      => ($subscription->starts_at ?? $subscription->created_at)?->toIso8601String(),
                'ends_at'        => $subscription->ends_at?->toIso8601String(),
                'trial_ends_at'  => $subscription->trial_ends_at?->toIso8601String(),
                'cancelled_at'   => $subscription->cancelled_at?->toIso8601String(),
                'days_remaining' => $subscription->daysRemaining(),
                'is_active'      => $subscription->isActive(),
                'is_on_trial'    => $subscription->isOnTrial(),
                'is_cancelled'   => $subscription->isCancelled(),
                'can_resume'     => $subscription->canResume(),
            ],
            'plan' => $plan ? [
                'id'           => $plan->id,
                'name'         => $plan->name,
                'slug'         => $plan->slug,
                'price'        => (float) $plan->price,
                'yearly_price' => (float) ($plan->yearly_price ?? $plan->price * 12),
                'limits'       => $plan->getFormattedLimits(),
                'features'     => $plan->getActiveFeatures(),
            ] : null,
            'has_active'      => $subscription->isActive() || $subscription->isOnTrial(),
            'pending_upgrade' => $pendingUpgrade,
            'currency_code'   => GeneralSetting::currencyCode(),
            'currency_symbol' => GeneralSetting::currencySymbol(),
        ]);
    }

    public function plans(): JsonResponse
    {
        $this->authorizeBilling();
        $tenant = tenant();

        $currentSub = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->latest()
            ->first();

        $pendingSub = TenantSubscription::with('plan')
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_PENDING)
            ->latest()
            ->first();

        $hasPendingUpgrade = $currentSub && $pendingSub && $pendingSub->id !== $currentSub->id;

        $plans = Plan::where('is_active', true)
            ->where(function ($q) use ($currentSub) {
                $q->where('is_private', false);
                if ($currentSub?->plan_id) {
                    $q->orWhere('id', $currentSub->plan_id);
                }
            })
            ->orderBy('price')
            ->get();

        $plansData = $plans->map(function ($plan) use ($currentSub) {
            return [
                'id'              => $plan->id,
                'name'            => $plan->name,
                'slug'            => $plan->slug,
                'price'           => (float) $plan->price,
                'yearly_price'    => (float) ($plan->yearly_price ?? $plan->price * 12),
                'savings_percent' => $plan->getYearlySavingsPercent(),
                'limits'          => $plan->getFormattedLimits(),
                'features'        => $plan->getActiveFeatures(),
                'all_features'    => Plan::AVAILABLE_FEATURES,
                'is_current'      => $currentSub && $currentSub->plan_id === $plan->id,
            ];
        });

        return response()->json([
            'plans'               => $plansData,
            'current_plan_id'     => $currentSub?->plan_id,
            'current_plan_price'  => $currentSub?->plan ? (float) $currentSub->plan->price : null,
            'has_active'          => $currentSub && ($currentSub->isActive() || $currentSub->isOnTrial()),
            'pending_upgrade'     => $hasPendingUpgrade ? [
                'plan_id'   => $pendingSub->plan_id,
                'plan_name' => $pendingSub->plan?->name,
            ] : null,
            'currency_code'       => GeneralSetting::currencyCode(),
            'currency_symbol'     => GeneralSetting::currencySymbol(),
        ]);
    }

    public function checkoutData(Plan $plan): JsonResponse
    {
        $this->authorizeBilling();
        $gateways = PaymentGatewayFactory::getAvailableGateways();

        $gateways['offline'] = [
            'key'   => 'offline',
            'label' => 'Transferencia bancaria',
            'icon'  => 'bi-bank',
            'color' => '#0d9488',
        ];

        $tenant = tenant();
        $currentSub = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->latest()
            ->first();

        $pendingSub = TenantSubscription::with('plan')
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_PENDING)
            ->latest()
            ->first();

        $hasPendingUpgrade = $currentSub && $pendingSub && $pendingSub->id !== $currentSub->id;
        $settings       = GeneralSetting::instance();
        $systemCurrency = $settings->currency_code ?? 'HNL';
        $currencySymbol = $settings->currency_symbol ?? 'L';

        return response()->json([
            'plan' => [
                'id'              => $plan->id,
                'name'            => $plan->name,
                'price'           => (float) $plan->price,
                'yearly_price'    => (float) ($plan->yearly_price ?? $plan->price * 12),
                'savings_percent' => $plan->getYearlySavingsPercent(),
                'limits'          => $plan->getFormattedLimits(),
                'features'        => $plan->getActiveFeatures(),
            ],
            'gateways'           => array_values($gateways),
            'is_upgrade'         => $currentSub && $plan->price > ($currentSub->plan->price ?? 0),
            'current_plan_name'  => $currentSub?->plan?->name,
            'currency_code'      => $systemCurrency,
            'currency_symbol'    => $currencySymbol,
            'bank_details'       => $settings->getBankDetails(),
            'pending_upgrade'    => $hasPendingUpgrade ? [
                'plan_id'   => $pendingSub->plan_id,
                'plan_name' => $pendingSub->plan?->name,
            ] : null,
        ]);
    }

    public function offlinePayment(Request $request): JsonResponse
    {
        $this->authorizeBilling();

        $request->validate([
            'plan_id'        => 'required|integer',
            'billing_cycle'  => 'required|in:monthly,yearly',
            'offline_method' => 'required|string|max:50',
            'payment_proof'  => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $proofPath = null;
        if ($request->hasFile('payment_proof')) {
            $uploadDir = upload_public_path('payment-proofs');
            if (! \Illuminate\Support\Facades\File::isDirectory($uploadDir)) {
                \Illuminate\Support\Facades\File::makeDirectory($uploadDir, 0755, true);
            }
            $file = $request->file('payment_proof');
            $filename = 'proof_' . tenant()->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move($uploadDir, $filename);
            $proofPath = upload_path('payment-proofs') . '/' . $filename;
        }

        $plan = Plan::where('is_active', true)->findOrFail($request->plan_id);
        $tenant = tenant();
        $cycle  = $request->billing_cycle;
        $amount = $plan->getPriceForCycle($cycle);

        $activeSub = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->latest()->get()->first(fn (TenantSubscription $sub) => $sub->isActive());
        $existingPending = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_PENDING)->latest()->first();

        if ($activeSub && $existingPending && $existingPending->id !== $activeSub->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ya tienes una solicitud de cambio de plan pendiente. Espera la aprobación del administrador o cancélala primero.',
            ], 422);
        }

        if ($existingPending) {
            $existingPending->update([
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => $amount,
                'currency'      => GeneralSetting::currencyCode(),
                'starts_at'     => $existingPending->starts_at ?? now(),
            ]);
            $subscription = $existingPending;
            TenantBillingPayment::where('tenant_subscription_id', $subscription->id)
                ->where('status', TenantBillingPayment::STATUS_PENDING)->get()
                ->each(fn (TenantBillingPayment $old) => $old->markSuperseded());
        } else {
            $subscription = TenantSubscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => $amount,
                'currency'      => GeneralSetting::currencyCode(),
                'status'        => TenantSubscription::STATUS_PENDING,
                'starts_at'     => now(),
            ]);
        }

        $payment = TenantBillingPayment::create([
            'tenant_id'              => $tenant->id,
            'tenant_subscription_id' => $subscription->id,
            'plan_id'                => $plan->id,
            'gateway'                => 'manual',
            'amount'                 => $amount,
            'currency'               => GeneralSetting::currencyCode(),
            'billing_cycle'          => $cycle,
            'status'                 => TenantBillingPayment::STATUS_PENDING,
            'metadata'               => [
                'plan_name'      => $plan->name,
                'email'          => auth()->user()->email ?? '',
                'offline_method' => $request->offline_method,
                'payment_proof'  => $proofPath,
            ],
        ]);

        return response()->json([
            'success'    => true,
            'message'    => 'La solicitud de pago por transferencia se envió correctamente y está pendiente de aprobación.',
            'payment_id' => $payment->id,
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $this->authorizeBilling();
        $request->validate([
            'plan_id'       => 'required|integer',
            'gateway'       => 'required|string',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $plan = Plan::where('is_active', true)->findOrFail($request->plan_id);
        $tenant  = tenant();
        $cycle   = $request->billing_cycle;
        $gateway = $request->gateway;
        $amount  = $plan->getPriceForCycle($cycle);
        $systemCurrency = GeneralSetting::currencyCode();

        $activeSub = TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->latest()->get()->first(fn (TenantSubscription $sub) => $sub->isActive());
        $existingPending = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_PENDING)->latest()->first();

        if ($activeSub && $existingPending && $existingPending->id !== $activeSub->id) {
            return response()->json([
                'success' => false,
                'message' => 'Ya tienes una solicitud de cambio de plan pendiente. Espera la aprobación del administrador o cancélala primero.',
            ], 422);
        }

        $currencyConfig = PaymentGatewayFactory::getGatewayCurrencyConfig($gateway);
        $conversion = CurrencyConversionService::resolve(
            $amount,
            $systemCurrency,
            $currencyConfig['supported_currencies'],
            $currencyConfig['default_currency']
        );

        if ($existingPending) {
            $existingPending->update([
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => $amount,
                'currency'      => $systemCurrency,
                'starts_at'     => $existingPending->starts_at ?? now(),
            ]);
            $subscription = $existingPending;
            TenantBillingPayment::where('tenant_subscription_id', $subscription->id)
                ->where('status', TenantBillingPayment::STATUS_PENDING)->get()
                ->each(fn (TenantBillingPayment $old) => $old->markSuperseded());
        } else {
            $subscription = TenantSubscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => $amount,
                'currency'      => $systemCurrency,
                'status'        => TenantSubscription::STATUS_PENDING,
                'starts_at'     => now(),
            ]);
        }

        $payment = TenantBillingPayment::create([
            'tenant_id'              => $tenant->id,
            'tenant_subscription_id' => $subscription->id,
            'plan_id'                => $plan->id,
            'gateway'                => $gateway,
            'amount'                 => $amount,
            'currency'               => $systemCurrency,
            'gateway_currency'       => $conversion['gateway_currency'],
            'gateway_amount'         => $conversion['gateway_amount'],
            'exchange_rate'          => $conversion['exchange_rate'],
            'conversion_applied'     => $conversion['conversion_applied'],
            'billing_cycle'          => $cycle,
            'status'                 => TenantBillingPayment::STATUS_PENDING,
            'metadata'               => [
                'plan_name' => $plan->name,
                'email'     => auth()->user()->email ?? '',
            ],
        ]);

        try {
            $gatewayInstance = PaymentGatewayFactory::resolve($gateway);
            if (! $gatewayInstance) {
                $payment->update(['status' => TenantBillingPayment::STATUS_FAILED]);
                $subscription->update(['status' => TenantSubscription::STATUS_FAILED]);
                return response()->json([
                    'success' => false,
                    'message' => 'La pasarela de pago seleccionada no está disponible.',
                ], 422);
            }

            $successUrl = url('/billing/callback/' . $gateway . '?payment_id=' . $payment->id);
            $cancelUrl  = url('/app/billing/failed?payment_id=' . $payment->id);
            $cycleLabel = $cycle === 'yearly' ? 'Anual' : 'Mensual';

            $result = $gatewayInstance->createCheckoutUrl(
                amount:      $conversion['gateway_amount'],
                currency:    $conversion['gateway_currency'],
                productName: $plan->name . ' (' . $cycleLabel . ')',
                description: 'Suscripción ' . strtolower($cycleLabel),
                metadata:    [
                    'payment_id' => $payment->id,
                    'tenant_id'  => $tenant->id,
                    'email'      => auth()->user()->email ?? '',
                ],
                successUrl:  $successUrl,
                cancelUrl:   $cancelUrl,
            );

            $payment->update([
                'transaction_id'     => $result['session_id'],
                'gateway_payment_id' => $result['session_id'],
            ]);

            return response()->json([
                'success'     => true,
                'payment_url' => $result['url'],
                'payment_id'  => $payment->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Billing API: payment init failed', [
                'gateway' => $gateway,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            $payment->update(['status' => TenantBillingPayment::STATUS_FAILED]);
            $subscription->update(['status' => TenantSubscription::STATUS_FAILED]);

            $message = 'No se pudo iniciar el pago. Inténtalo nuevamente.';
            if (config('app.debug')) {
                $message .= ' Depuración: ' . $e->getMessage();
            }

            return response()->json(['success' => false, 'message' => $message], 422);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeBilling();
        $tenant = tenant();
        $payments = TenantBillingPayment::with('plan')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        $items = $payments->getCollection()->map(function ($p) {
            return [
                'id'             => $p->id,
                'invoice_number' => $p->invoice_number,
                'plan_name'      => $p->plan->name ?? 'N/D',
                'amount'         => (float) $p->amount,
                'tax'            => (float) $p->tax,
                'total'          => $p->total,
                'currency'       => $p->currency,
                'gateway'        => $p->gateway,
                'gateway_label'  => $p->gateway_label,
                'status'         => $p->status,
                'billing_cycle'  => $p->billing_cycle,
                'paid_at'        => $p->paid_at?->toIso8601String(),
                'created_at'     => $p->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'payments'        => $items,
            'current_page'    => $payments->currentPage(),
            'last_page'       => $payments->lastPage(),
            'total'           => $payments->total(),
            'currency_code'   => GeneralSetting::currencyCode(),
            'currency_symbol' => GeneralSetting::currencySymbol(),
        ]);
    }

    public function paymentDetail(int $id): JsonResponse
    {
        $this->authorizeBilling();
        $payment = TenantBillingPayment::with('plan', 'subscription')
            ->where('tenant_id', tenant()->id)
            ->findOrFail($id);

        if ($payment->status === TenantBillingPayment::STATUS_PENDING
            && $payment->gateway === 'stripe'
            && $payment->gateway_payment_id
        ) {
            try {
                $gateway = PaymentGatewayFactory::resolve('stripe');
                if ($gateway) {
                    $verification = $gateway->verifyPaymentStatus($payment->gateway_payment_id);
                    if ($verification['status'] === 'paid') {
                        $payment->update([
                            'status'         => TenantBillingPayment::STATUS_PAID,
                            'paid_at'        => now(),
                            'transaction_id' => $verification['transaction_id'] ?? $payment->transaction_id,
                        ]);
                        $sub = $payment->subscription;
                        if ($sub && $sub->status !== TenantSubscription::STATUS_ACTIVE) $sub->activate();
                        $payment->refresh()->load(['plan', 'subscription']);
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Stripe verify in paymentDetail failed: {$e->getMessage()}");
            }
        }

        return response()->json([
            'payment' => [
                'id'                 => $payment->id,
                'invoice_number'     => $payment->invoice_number,
                'plan_name'          => $payment->plan->name ?? 'N/D',
                'amount'             => (float) $payment->amount,
                'tax'                => (float) $payment->tax,
                'total'              => $payment->total,
                'currency'           => $payment->currency,
                'gateway'            => $payment->gateway,
                'gateway_label'      => $payment->gateway_label,
                'status'             => $payment->status,
                'billing_cycle'      => $payment->billing_cycle,
                'transaction_id'     => $payment->transaction_id,
                'gateway_payment_id' => $payment->gateway_payment_id,
                'paid_at'            => $payment->paid_at?->toIso8601String(),
                'created_at'         => $payment->created_at->toIso8601String(),
            ],
            'subscription' => $payment->subscription ? [
                'status'    => $payment->subscription->status,
                'starts_at' => $payment->subscription->starts_at?->toIso8601String(),
                'ends_at'   => $payment->subscription->ends_at?->toIso8601String(),
            ] : null,
            'currency_code'   => GeneralSetting::currencyCode(),
            'currency_symbol' => GeneralSetting::currencySymbol(),
        ]);
    }

    public function retryPayment(int $id): JsonResponse
    {
        $this->authorizeBilling();
        $payment = TenantBillingPayment::where('tenant_id', tenant()->id)
            ->where('status', TenantBillingPayment::STATUS_FAILED)
            ->findOrFail($id);
        return response()->json([
            'plan_id'       => $payment->plan_id,
            'billing_cycle' => $payment->billing_cycle,
        ]);
    }

    public function cancelSubscription(): JsonResponse
    {
        $this->authorizeBilling();
        $tenant = tenant();
        $subscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->latest()->first();

        if (! $subscription) {
            return response()->json(['success' => false, 'message' => 'No se encontró una suscripción activa.'], 422);
        }

        $subscription->cancel();
        Log::info("Billing: Subscription {$subscription->id} cancelled for tenant {$tenant->id}.");
        $endDate = $subscription->ends_at?->locale('es')->translatedFormat('d M Y');

        return response()->json([
            'success' => true,
            'message' => 'La suscripción fue cancelada. Permanecerá activa hasta ' . ($endDate ?: 'la fecha de finalización') . '.',
            'subscription' => [
                'id'           => $subscription->id,
                'status'       => $subscription->status,
                'ends_at'      => $subscription->ends_at?->toIso8601String(),
                'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
                'can_resume'   => $subscription->canResume(),
            ],
        ]);
    }

    public function resumeSubscription(): JsonResponse
    {
        $this->authorizeBilling();
        $tenant = tenant();
        $subscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_CANCELLED)
            ->latest()->first();

        if (! $subscription || ! $subscription->canResume()) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró una suscripción que pueda reanudarse. Es posible que el período de facturación ya haya vencido.',
            ], 422);
        }

        $subscription->resume();
        Log::info("Billing: Subscription {$subscription->id} resumed for tenant {$tenant->id}.");

        return response()->json([
            'success' => true,
            'message' => 'La suscripción se reanudó correctamente.',
            'subscription' => [
                'id'      => $subscription->id,
                'status'  => $subscription->status,
                'ends_at' => $subscription->ends_at?->toIso8601String(),
            ],
        ]);
    }

    public function cancelPendingUpgrade(): JsonResponse
    {
        $this->authorizeBilling();
        $tenant = tenant();
        $pendingSub = TenantSubscription::where('tenant_id', $tenant->id)
            ->where('status', TenantSubscription::STATUS_PENDING)
            ->latest()->first();

        if (! $pendingSub) {
            return response()->json(['success' => false, 'message' => 'No se encontró un cambio de plan pendiente.'], 422);
        }

        TenantBillingPayment::where('tenant_subscription_id', $pendingSub->id)
            ->where('status', TenantBillingPayment::STATUS_PENDING)->get()
            ->each(fn (TenantBillingPayment $p) => $p->markFailed());

        $pendingSub->update(['status' => TenantSubscription::STATUS_CANCELLED]);
        Log::info("Billing: Pending upgrade subscription {$pendingSub->id} cancelled for tenant {$tenant->id}.");

        return response()->json([
            'success' => true,
            'message' => 'La solicitud de cambio de plan pendiente fue cancelada.',
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->authorizeBilling();
        $tenant = tenant();
        $payments = TenantBillingPayment::with('plan')
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantBillingPayment::STATUS_PAID)
            ->orderByDesc('paid_at')
            ->paginate($request->get('per_page', 15));

        $items = $payments->getCollection()->map(function ($p) {
            return [
                'id'             => $p->id,
                'invoice_number' => $p->invoice_number,
                'plan_name'      => $p->plan->name ?? 'N/D',
                'amount'         => (float) $p->amount,
                'tax'            => (float) $p->tax,
                'total'          => $p->total,
                'currency'       => $p->currency,
                'gateway'        => $p->gateway,
                'gateway_label'  => $p->gateway_label,
                'billing_cycle'  => $p->billing_cycle,
                'paid_at'        => $p->paid_at?->toIso8601String(),
                'created_at'     => $p->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'invoices'        => $items,
            'current_page'    => $payments->currentPage(),
            'last_page'       => $payments->lastPage(),
            'total'           => $payments->total(),
            'currency_code'   => GeneralSetting::currencyCode(),
            'currency_symbol' => GeneralSetting::currencySymbol(),
        ]);
    }

    public function downloadInvoice(int $id)
    {
        $this->authorizeBilling();
        $payment = TenantBillingPayment::with('plan', 'subscription', 'tenant')
            ->where('tenant_id', tenant()->id)
            ->where('status', TenantBillingPayment::STATUS_PAID)
            ->findOrFail($id);

        $data = [
            'payment'      => $payment,
            'plan'         => $payment->plan,
            'subscription' => $payment->subscription,
            'tenant'       => $payment->tenant,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.billing_invoice_pdf', $data)->setPaper('a4');
        $filename = 'Factura-' . ($payment->invoice_number ?? $payment->id) . '.pdf';
        return $pdf->download($filename);
    }

    public function planUsage(): JsonResponse
    {
        $this->authorizeBilling();
        $summary = app(\App\Services\TenantLimitsService::class)->getPlanSummary();
        return response()->json($summary);
    }

    public function capturePaypal(Request $request): JsonResponse
    {
        $this->authorizeBilling();
        $request->validate([
            'payment_id' => 'required|integer',
            'token'      => 'required|string|max:50',
        ]);

        $tenant  = tenant();
        $payment = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->with(['plan', 'subscription'])
            ->find($request->payment_id);

        if (! $payment) return response()->json(['captured' => false, 'message' => 'No se encontró el pago.'], 404);
        if ($payment->status === TenantBillingPayment::STATUS_PAID) return response()->json(['captured' => true]);
        if ($payment->gateway !== 'paypal') return response()->json(['captured' => false, 'message' => 'Este pago no corresponde a PayPal.'], 422);
        if ($payment->status !== TenantBillingPayment::STATUS_PENDING) return response()->json(['captured' => false, 'message' => 'Este pago no puede ser capturado.'], 422);
        if ($payment->created_at->lt(now()->subHours(3))) return response()->json(['captured' => false, 'message' => 'La orden venció.'], 422);

        $expectedOrderId = $payment->gateway_payment_id;
        if ($expectedOrderId && $expectedOrderId !== $request->token) {
            return response()->json(['captured' => false, 'message' => 'El token no coincide con la orden.'], 422);
        }

        try {
            $gateway = PaymentGatewayFactory::resolve('paypal');
            if (! $gateway) return response()->json(['captured' => false, 'message' => 'La pasarela de pago no está disponible.'], 503);

            $capture = $gateway->captureOrder($request->token);
            if (! $capture['success']) {
                Log::warning('PayPal API capture: not successful', ['payment_id' => $payment->id, 'capture' => $capture]);
                return response()->json(['captured' => false, 'message' => 'La captura del pago no se completó.']);
            }

            $payment->update([
                'status'             => TenantBillingPayment::STATUS_PAID,
                'paid_at'            => now(),
                'gateway_payment_id' => $capture['order_id'] ?? $payment->gateway_payment_id,
                'transaction_id'     => $capture['capture_id'] ?? $payment->transaction_id,
            ]);

            $subscription = $payment->fresh()->subscription;
            if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) $subscription->activate();

            Log::info("PayPal API capture: payment {$payment->id} confirmed for tenant {$tenant->id}.");
            EmailNotificationService::paymentSuccess($tenant, [
                '{{amount}}' => GeneralSetting::currencySymbol() . number_format((float) $payment->amount, 2),
            ], $subscription);

            return response()->json(['captured' => true]);
        } catch (\Throwable $e) {
            Log::error('PayPal API capture exception', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            return response()->json([
                'captured' => false,
                'message'  => 'No se pudo capturar el pago. El webhook volverá a intentarlo automáticamente.',
            ], 500);
        }
    }
}
