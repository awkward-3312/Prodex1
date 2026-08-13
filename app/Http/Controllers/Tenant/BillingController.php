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

class BillingController extends Controller
{
    public function plans()
    {
        $plans = Plan::where('is_active', true)->orderBy('price')->get();
        $tenant = tenant();
        $subscription = $tenant ? TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->with('plan')
            ->latest()
            ->first() : null;

        return view('billing.plans', compact('plans', 'subscription'));
    }

    public function checkout(Request $request, $planId)
    {
        $plan = Plan::where('is_active', true)->findOrFail($planId);
        $tenant = tenant();
        $cycle = $request->query('cycle', 'monthly');
        $amount = $plan->getPriceForCycle($cycle);

        // Free plans: activate immediately without payment
        if ($plan->isFree()) {
            return $this->activateFreePlan($tenant, $plan, $cycle);
        }

        $gateways = PaymentGatewayFactory::getAvailableGateways();

        $subscription = $tenant ? TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIAL])
            ->with('plan')
            ->latest()
            ->first() : null;

        $settings = GeneralSetting::instance();

        // Check for an existing pending payment on the current subscription.
        $pendingPayment = null;
        $latestSubscription = $tenant ? TenantSubscription::where('tenant_id', $tenant->id)->latest()->first() : null;
        if ($latestSubscription) {
            $pendingPayment = TenantBillingPayment::where('tenant_subscription_id', $latestSubscription->id)
                ->where('status', TenantBillingPayment::STATUS_PENDING)
                ->latest()
                ->first();
        }

        return view('billing.checkout', [
            'plan'           => $plan,
            'cycle'          => $cycle,
            'amount'         => $amount,
            'gateways'       => $gateways,
            'subscription'   => $subscription,
            'pendingPayment' => $pendingPayment,
            'currencyCode'   => $settings->currency_code ?? 'USD',
            'currencySymbol' => $settings->currency_symbol ?? '$',
            'bankDetails'    => $settings->getBankDetails(),
        ]);
    }

    /**
     * Activate a free plan immediately without payment.
     */
    protected function activateFreePlan($tenant, Plan $plan, string $cycle)
    {
        $subscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->latest()
            ->first();

        if ($subscription) {
            $subscription->update([
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => 0,
                'currency'      => GeneralSetting::currencyCode(),
                'status'        => TenantSubscription::STATUS_ACTIVE,
                'starts_at'     => now(),
                'ends_at'       => null,
                'cancelled_at'  => null,
            ]);
        } else {
            TenantSubscription::create([
                'tenant_id'     => $tenant->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $cycle,
                'amount'        => 0,
                'currency'      => GeneralSetting::currencyCode(),
                'status'        => TenantSubscription::STATUS_ACTIVE,
                'starts_at'     => now(),
                'ends_at'       => null,
            ]);
        }

        return redirect()->route('billing.plans')
            ->with('success', 'You have been switched to the ' . $plan->name . ' plan.');
    }

    public function processPayment(Request $request, $planId)
    {
        $plan = Plan::where('is_active', true)->findOrFail($planId);
        $tenant = tenant();

        // Block submission if a pending payment already exists for this tenant.
        $existingPending = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->where('status', TenantBillingPayment::STATUS_PENDING)
            ->first();

        if ($existingPending) {
            return back()->withErrors([
                'payment' => 'You already have a pending payment awaiting review. Please cancel it first if you need to submit a new one.',
            ]);
        }

        $rules = [
            'gateway'       => ['required', 'string', 'in:stripe,paypal,paystack,flutterwave,mollie,offline'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ];

        $gatewayKey = $request->input('gateway');

        // Offline payments require a proof of payment upload
        if ($gatewayKey === 'offline') {
            $rules['payment_proof'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        }

        $request->validate($rules);

        $billingCycle = $request->input('billing_cycle');
        $amount       = $plan->getPriceForCycle($billingCycle);
        $systemCurrency = GeneralSetting::currencyCode();

        $gateway = PaymentGatewayFactory::resolve($gatewayKey);
        if (! $gateway) {
            return back()->withErrors(['gateway' => 'Selected payment gateway is not available.']);
        }

        // Block if there is already a pending upgrade alongside an active subscription.
        // Only a GENUINELY active subscription counts here — a row still marked
        // "active" whose ends_at has already passed (the expiry cron hasn't run
        // yet) is effectively expired and must be treated as a renewal, not an
        // upgrade, otherwise it spawns a duplicate active row.
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
            return back()->withErrors(['plan' => 'You already have a pending upgrade request. Please wait for admin approval or cancel it first.']);
        }

        // Reuse existing pending subscription or create a new one (never overwrite active sub).
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
            // Active sub exists, no pending — create a separate pending upgrade subscription.
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

        $lifecycle = app(SubscriptionLifecycleService::class);

        // ── Offline payment: save proof and mark as pending verification ──
        if ($gatewayKey === 'offline') {
            try {
                $proofPath = null;
                if ($request->hasFile('payment_proof')) {
                    $uploadDir = upload_public_path('payment-proofs');
                    if (! \Illuminate\Support\Facades\File::isDirectory($uploadDir)) {
                        \Illuminate\Support\Facades\File::makeDirectory($uploadDir, 0755, true);
                    }
                    $file = $request->file('payment_proof');
                    $filename = 'proof_' . $tenant->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                    $file->move($uploadDir, $filename);
                    $proofPath = upload_path('payment-proofs') . '/' . $filename;
                }

                $payment = $lifecycle->createPayment($subscription, [
                    'amount'                 => $amount,
                    'currency'               => $systemCurrency,
                    'gateway_currency'       => $systemCurrency,
                    'gateway_amount'         => $amount,
                    'exchange_rate'          => 1,
                    'conversion_applied'     => false,
                    'billing_cycle'          => $billingCycle,
                    'gateway'                => 'offline',
                    'gateway_payment_id'     => 'offline_' . uniqid(),
                    'payment_proof_path'     => $proofPath,
                ]);

                return redirect()->route('billing.offline-submitted', ['payment' => $payment->id]);
            } catch (\Throwable $e) {
                Log::error("Offline billing checkout failed: {$e->getMessage()}", [
                    'tenant_id' => $tenant->id,
                    'exception' => $e,
                ]);
                return back()->withErrors(['payment' => 'Unable to process your submission. Please try again.']);
            }
        }

        // ── Online payment gateways ──

        // Resolve currency conversion
        $currencyConfig = PaymentGatewayFactory::getGatewayCurrencyConfig($gatewayKey);
        $conversion = CurrencyConversionService::resolve(
            $amount,
            $systemCurrency,
            $currencyConfig['supported_currencies'],
            $currencyConfig['default_currency']
        );

        $payment = $lifecycle->createPayment($subscription, [
            'amount'                 => $amount,
            'currency'               => $systemCurrency,
            'gateway_currency'       => $conversion['gateway_currency'],
            'gateway_amount'         => $conversion['gateway_amount'],
            'exchange_rate'          => $conversion['exchange_rate'],
            'conversion_applied'     => $conversion['conversion_applied'],
            'billing_cycle'          => $billingCycle,
            'gateway'                => $gatewayKey,
        ]);

        try {
            $successUrl = route('billing.success') . '?payment=' . $payment->id;
            $cancelUrl  = route('billing.failed') . '?payment=' . $payment->id;

            $checkoutUrl = $gateway->createCheckoutSession($payment, $successUrl, $cancelUrl);

            return redirect()->away($checkoutUrl);
        } catch (\Throwable $e) {
            Log::error("Tenant billing checkout failed: {$e->getMessage()}", [
                'tenant_id'  => $tenant->id,
                'payment_id' => $payment->id,
                'exception'  => $e,
            ]);

            $payment->markFailed();

            return back()->withErrors(['payment' => 'Unable to initiate payment. Please try again.']);
        }
    }

    /**
     * Allow tenant to cancel their own pending payment so they can resubmit.
     */
    public function cancelPayment(TenantBillingPayment $payment)
    {
        $tenant = tenant();

        if ($payment->tenant_id !== $tenant->id) {
            abort(403);
        }

        if ($payment->status !== TenantBillingPayment::STATUS_PENDING) {
            return back()->with('error', 'Only pending payments can be cancelled.');
        }

        $payment->update([
            'status' => TenantBillingPayment::STATUS_FAILED,
            'notes'  => trim(($payment->notes ? $payment->notes . "\n" : '') . 'Cancelled by tenant.'),
        ]);

        return redirect()->route('billing.plans')
            ->with('success', 'Your pending payment has been cancelled. You can now submit a new one.');
    }

    public function callback(Request $request, string $gateway)
    {
        $tenant    = tenant();
        $paymentId = $request->query('payment_id');
        $payment   = null;

        if ($paymentId) {
            $query = TenantBillingPayment::with(['plan', 'subscription']);
            if ($tenant) {
                $query->where('tenant_id', $tenant->id);
            }
            $payment = $query->find($paymentId);
        }

        // PayPal requires an explicit server-side capture before the
        // PAYMENT.CAPTURE.COMPLETED webhook will fire.  Capture here so
        // payment is confirmed even if the webhook is delayed or misconfigured.
        if ($payment && ! $payment->isPaid() && $gateway === 'paypal') {
            $urlToken        = $request->query('token');
            $storedOrderId   = $payment->gateway_payment_id;

            // Use the stored order ID we created at checkout; only fall back
            // to the URL token when it matches.  This prevents an attacker
            // from swapping in a different (cheaper) PayPal order ID.
            $orderId = $storedOrderId;
            if ($urlToken && $storedOrderId && $urlToken !== $storedOrderId) {
                Log::warning('PayPal callback token mismatch', [
                    'payment_id' => $payment->id,
                    'url_token'  => $urlToken,
                    'stored'     => $storedOrderId,
                ]);
                $orderId = null;
            }

            if ($orderId) {
                try {
                    $gw = PaymentGatewayFactory::resolve('paypal');
                    if ($gw) {
                        $capture = $gw->captureOrder($orderId);
                        if ($capture['success']) {
                            $payment->markPaid($capture['order_id'], $capture['capture_id']);
                            $sub = $payment->subscription;
                            if ($sub && $sub->status !== TenantSubscription::STATUS_ACTIVE) {
                                $sub->activate();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('PayPal capture on callback failed', [
                        'payment_id' => $payment->id,
                        'order_id'   => $orderId,
                        'error'      => $e->getMessage(),
                    ]);
                }
                $payment->refresh();
            }
        }

        // Paystack: verify the transaction server-side on return.
        // Never trust the redirect alone — always confirm via Paystack API.
        if ($payment && ! $payment->isPaid() && $gateway === 'paystack') {
            $this->verifyPaystackTransaction($payment);
            $payment->refresh();
        }

        // Flutterwave: verify the transaction server-side on return (after 3DS).
        if ($payment && ! $payment->isPaid() && $gateway === 'flutterwave') {
            $this->verifyFlutterwaveTransaction($payment);
            $payment->refresh();
        }

        // Stripe: verify the checkout session server-side on return.
        if ($payment && ! $payment->isPaid() && $gateway === 'stripe') {
            $this->verifyStripeTransaction($payment);
            $payment->refresh();
        }

        // Mollie: verify the payment server-side on return.
        if ($payment && ! $payment->isPaid() && $gateway === 'mollie') {
            $this->verifyMollieTransaction($payment);
            $payment->refresh();
        }

        if ($payment && $payment->isPaid()) {
            return redirect('/app/billing/success?payment_id=' . $payment->id);
        }

        return redirect('/app/billing/success?payment_id=' . ($paymentId ?? ''));
    }

    public function success(Request $request)
    {
        $paymentId = $request->query('payment');
        $payment = $paymentId ? TenantBillingPayment::with(['plan', 'subscription'])->find($paymentId) : null;

        if (! $payment) {
            return redirect()->route('billing.plans')
                ->with('warning', 'Payment record not found.');
        }

        // PayPal: capture the order when user returns from PayPal checkout.
        // PayPal requires an explicit capture call — without it PAYMENT.CAPTURE.COMPLETED
        // never fires and the payment stays pending.
        // Use the URL `token` param first (PayPal appends it to return_url), then
        // fall back to the order ID we stored in gateway_payment_id at checkout.
        if (! $payment->isPaid() && $payment->gateway === 'paypal') {
            $orderId = $request->query('token')   // e.g. ?token=ORDER_ID&PayerID=...
                    ?? $payment->gateway_payment_id; // fallback: stored at checkout

            if ($orderId) {
                try {
                    /** @var \App\Services\PaymentGateways\PaypalGateway|null $gateway */
                    $gateway = PaymentGatewayFactory::resolve('paypal');
                    if ($gateway) {
                        $capture = $gateway->captureOrder($orderId);
                        if ($capture['success']) {
                            $payment->markPaid($capture['order_id'], $capture['capture_id']);
                            $subscription = $payment->subscription;
                            if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
                                $subscription->activate();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('PayPal capture on return failed', [
                        'payment_id' => $payment->id,
                        'order_id'   => $orderId,
                        'error'      => $e->getMessage(),
                    ]);
                }
                $payment->refresh()->load(['plan', 'subscription']);
            }
        }

        // Paystack: verify the transaction server-side when user returns.
        // Paystack redirects to callback_url after payment — we must verify
        // via the API to confirm the charge actually succeeded.
        if (! $payment->isPaid() && $payment->gateway === 'paystack') {
            $this->verifyPaystackTransaction($payment);
            $payment->refresh()->load(['plan', 'subscription']);
        }

        // Flutterwave: verify after 3DS return
        if (! $payment->isPaid() && $payment->gateway === 'flutterwave') {
            $this->verifyFlutterwaveTransaction($payment);
            $payment->refresh()->load(['plan', 'subscription']);
        }

        // Stripe: verify checkout session on return
        if (! $payment->isPaid() && $payment->gateway === 'stripe') {
            $this->verifyStripeTransaction($payment);
            $payment->refresh()->load(['plan', 'subscription']);
        }

        // Mollie: verify payment on return
        if (! $payment->isPaid() && $payment->gateway === 'mollie') {
            $this->verifyMollieTransaction($payment);
            $payment->refresh()->load(['plan', 'subscription']);
        }

        return view('billing.success', [
            'payment'   => $payment,
            'confirmed' => $payment->isPaid(),
        ]);
    }

    /**
     * One-shot server-side confirmation for pending payments.
     *
     * PayPal: captures the order via PayPal API.
     * Paystack: verifies the transaction via Paystack API.
     *
     * Called by the frontend once after polling has not resolved the payment.
     * Throttled to 10/min per user by the route middleware.
     * Guards: must belong to tenant, must be pending, must be recent.
     */
    public function confirmPayment(Request $request, $paymentId)
    {
        $tenant  = tenant();
        $payment = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->with(['plan', 'subscription'])
            ->find($paymentId);

        if (! $payment) {
            return response()->json(['confirmed' => false, 'message' => 'Payment not found.'], 404);
        }

        if ($payment->isPaid()) {
            return response()->json(['confirmed' => true]);
        }

        if (! in_array($payment->gateway, ['paypal', 'paystack', 'flutterwave', 'mollie'], true) || ! $payment->gateway_payment_id) {
            return response()->json(['confirmed' => false, 'message' => 'Not a capturable payment.'], 422);
        }

        // Reject stale orders — PayPal voids unapproved orders after ~3 hours;
        // Paystack references expire similarly.
        if ($payment->created_at->lt(now()->subHours(3))) {
            return response()->json(['confirmed' => false, 'message' => 'Order expired. Please start a new payment.'], 422);
        }

        try {
            if ($payment->gateway === 'paypal') {
                return $this->confirmPaypalPayment($payment);
            }

            if ($payment->gateway === 'paystack') {
                return $this->confirmPaystackPayment($payment);
            }

            if ($payment->gateway === 'flutterwave') {
                return $this->confirmFlutterwavePayment($payment);
            }

            if ($payment->gateway === 'mollie') {
                return $this->confirmMolliePayment($payment);
            }

            return response()->json(['confirmed' => false, 'message' => 'Unsupported gateway.'], 422);

        } catch (\Throwable $e) {
            Log::error("{$payment->gateway} confirmPayment exception", [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['confirmed' => false, 'message' => 'Confirmation failed: ' . $e->getMessage()], 500);
        }
    }

    protected function confirmPaypalPayment(TenantBillingPayment $payment)
    {
        /** @var \App\Services\PaymentGateways\PaypalGateway|null $gateway */
        $gateway = PaymentGatewayFactory::resolve('paypal');

        if (! $gateway) {
            return response()->json(['confirmed' => false, 'message' => 'Gateway unavailable.'], 503);
        }

        $capture = $gateway->captureOrder($payment->gateway_payment_id);

        if (! $capture['success']) {
            Log::warning('PayPal confirmPayment: capture not successful', [
                'payment_id' => $payment->id,
                'capture'    => $capture,
            ]);
            return response()->json(['confirmed' => false, 'message' => 'Capture not completed yet.']);
        }

        $payment->markPaid($capture['order_id'], $capture['capture_id']);

        $subscription = $payment->fresh()->subscription;
        if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
            $subscription->activate();
        }

        Log::info('PayPal confirmPayment: payment confirmed', ['payment_id' => $payment->id]);
        return response()->json(['confirmed' => true]);
    }

    protected function confirmPaystackPayment(TenantBillingPayment $payment)
    {
        /** @var \App\Services\PaymentGateways\PaystackGateway|null $gateway */
        $gateway = PaymentGatewayFactory::resolve('paystack');

        if (! $gateway) {
            return response()->json(['confirmed' => false, 'message' => 'Gateway unavailable.'], 503);
        }

        $verification = $gateway->verifyTransaction($payment->gateway_payment_id);

        if (! $verification['success']) {
            Log::warning('Paystack confirmPayment: verification not successful', [
                'payment_id'   => $payment->id,
                'verification' => $verification,
            ]);
            return response()->json(['confirmed' => false, 'message' => 'Payment not confirmed yet.']);
        }

        $payment->markPaid($payment->gateway_payment_id, $verification['transaction_id']);

        $subscription = $payment->fresh()->subscription;
        if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
            $subscription->activate();
        }

        Log::info('Paystack confirmPayment: payment confirmed', ['payment_id' => $payment->id]);
        return response()->json(['confirmed' => true]);
    }

    /**
     * Verify a Paystack transaction server-side and mark as paid if successful.
     * Used on callback/success return to confirm payment before webhook arrives.
     */
    protected function verifyPaystackTransaction(TenantBillingPayment $payment): void
    {
        $reference = $payment->gateway_payment_id;
        if (! $reference) {
            return;
        }

        try {
            /** @var \App\Services\PaymentGateways\PaystackGateway|null $gateway */
            $gateway = PaymentGatewayFactory::resolve('paystack');

            if (! $gateway) {
                return;
            }

            $verification = $gateway->verifyTransaction($reference);

            if ($verification['success']) {
                $payment->markPaid($reference, $verification['transaction_id']);

                $subscription = $payment->subscription;
                if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
                    $subscription->activate();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Paystack verification on return failed', [
                'payment_id' => $payment->id,
                'reference'  => $reference,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify a Flutterwave transaction server-side and mark as paid if successful.
     */
    protected function verifyFlutterwaveTransaction(TenantBillingPayment $payment): void
    {
        $reference = $payment->gateway_payment_id;
        if (! $reference) {
            return;
        }

        try {
            /** @var \App\Services\PaymentGateways\FlutterwaveGateway|null $gateway */
            $gateway = PaymentGatewayFactory::resolve('flutterwave');

            if (! $gateway) {
                // Try webhook resolution (gateway might be disabled but credentials exist)
                $gateway = PaymentGatewayFactory::resolveForWebhook('flutterwave');
            }

            if (! $gateway) {
                return;
            }

            $verification = $gateway->verifyTransaction($reference);

            if ($verification['success']) {
                $payment->markPaid($reference, $verification['transaction_id']);

                $subscription = $payment->subscription;
                if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
                    $subscription->activate();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Flutterwave verification on return failed', [
                'payment_id' => $payment->id,
                'reference'  => $reference,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify a Stripe checkout session server-side and mark as paid if successful.
     */
    protected function verifyStripeTransaction(TenantBillingPayment $payment): void
    {
        $sessionId = $payment->gateway_payment_id;
        if (! $sessionId) {
            return;
        }

        try {
            $gateway = PaymentGatewayFactory::resolve('stripe');
            if (! $gateway) {
                return;
            }

            $verification = $gateway->verifyPaymentStatus($sessionId);

            if ($verification['status'] === 'paid') {
                $payment->markPaid(
                    $verification['gateway_payment_id'] ?? $sessionId,
                    $verification['transaction_id'] ?? ''
                );

                $subscription = $payment->subscription;
                if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
                    $subscription->activate();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Stripe verification on return failed', [
                'payment_id' => $payment->id,
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify a Mollie payment server-side and mark as paid if successful.
     */
    protected function verifyMollieTransaction(TenantBillingPayment $payment): void
    {
        $paymentRef = $payment->gateway_payment_id;
        if (! $paymentRef) {
            return;
        }

        try {
            $gateway = PaymentGatewayFactory::resolve('mollie')
                ?? PaymentGatewayFactory::resolveForWebhook('mollie');

            if (! $gateway) {
                return;
            }

            $verification = $gateway->verifyPaymentStatus($paymentRef);

            if ($verification['status'] === 'paid') {
                $payment->markPaid(
                    $verification['gateway_payment_id'] ?? $paymentRef,
                    $verification['transaction_id'] ?? ''
                );

                $subscription = $payment->subscription;
                if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
                    $subscription->activate();
                }
            }
        } catch (\Throwable $e) {
            Log::error('Mollie verification on return failed', [
                'payment_id' => $payment->id,
                'reference'  => $paymentRef,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    protected function confirmMolliePayment(TenantBillingPayment $payment)
    {
        $gateway = PaymentGatewayFactory::resolve('mollie')
            ?? PaymentGatewayFactory::resolveForWebhook('mollie');

        if (! $gateway) {
            return response()->json(['confirmed' => false, 'message' => 'Gateway unavailable.'], 503);
        }

        $verification = $gateway->verifyPaymentStatus($payment->gateway_payment_id);

        if ($verification['status'] !== 'paid') {
            return response()->json(['confirmed' => false, 'message' => 'Payment not confirmed yet.']);
        }

        $payment->markPaid(
            $verification['gateway_payment_id'] ?? $payment->gateway_payment_id,
            $verification['transaction_id'] ?? ''
        );

        $subscription = $payment->fresh()->subscription;
        if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
            $subscription->activate();
        }

        Log::info('Mollie confirmPayment: payment confirmed', ['payment_id' => $payment->id]);
        return response()->json(['confirmed' => true]);
    }

    protected function confirmFlutterwavePayment(TenantBillingPayment $payment)
    {
        /** @var \App\Services\PaymentGateways\FlutterwaveGateway|null $gateway */
        $gateway = PaymentGatewayFactory::resolve('flutterwave')
            ?? PaymentGatewayFactory::resolveForWebhook('flutterwave');

        if (! $gateway) {
            return response()->json(['confirmed' => false, 'message' => 'Gateway unavailable.'], 503);
        }

        $verification = $gateway->verifyTransaction($payment->gateway_payment_id);

        if (! $verification['success']) {
            return response()->json(['confirmed' => false, 'message' => 'Payment not confirmed yet.']);
        }

        $payment->markPaid($payment->gateway_payment_id, $verification['transaction_id']);

        $subscription = $payment->fresh()->subscription;
        if ($subscription && $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
            $subscription->activate();
        }

        Log::info('Flutterwave confirmPayment: payment confirmed', ['payment_id' => $payment->id]);
        return response()->json(['confirmed' => true]);
    }

    /**
     * Confirmation page after offline payment proof is submitted.
     */
    public function offlineSubmitted(Request $request)
    {
        $tenant  = tenant();
        $payment = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->where('gateway', 'offline')
            ->with(['plan', 'subscription'])
            ->findOrFail($request->query('payment'));

        $settings = GeneralSetting::instance();

        return view('billing.offline_submitted', [
            'payment'  => $payment,
            'plan'     => $payment->plan,
            'appName'  => $settings->app_name ?? config('app.name'),
        ]);
    }

    public function failed(Request $request)
    {
        $paymentId = $request->query('payment');
        $payment = $paymentId ? TenantBillingPayment::with(['plan'])->find($paymentId) : null;

        return view('billing.failed', compact('payment'));
    }

    public function paymentStatus(Request $request, $paymentId)
    {
        $tenant  = tenant();
        $payment = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->with(['plan', 'subscription'])
            ->find($paymentId);

        if (! $payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $data = [
            'status'         => $payment->status,
            'is_paid'        => $payment->isPaid(),
            'invoice_number' => $payment->invoice_number,
        ];

        if ($payment->isPaid()) {
            $data['plan_name']     = $payment->plan->name ?? null;
            $data['billing_cycle'] = $payment->billing_cycle;
            $data['amount']        = number_format($payment->amount, 2);
            $data['currency']      = $payment->currency;
            $data['gateway_label'] = $payment->gateway_label;
            $data['valid_until']   = $payment->subscription?->ends_at?->format('M d, Y');
        }

        return response()->json($data);
    }

    public function history()
    {
        $tenant = tenant();

        $subscription = TenantSubscription::where('tenant_id', $tenant->id)
            ->with('plan')
            ->latest()
            ->first();

        $payments = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->with('plan', 'subscription')
            ->latest()
            ->paginate(20);

        return view('billing.history', compact('subscription', 'payments'));
    }

    public function retry(Request $request, $paymentId)
    {
        $tenant = tenant();
        $oldPayment = TenantBillingPayment::where('tenant_id', $tenant->id)
            ->where('id', $paymentId)
            ->where('status', TenantBillingPayment::STATUS_FAILED)
            ->firstOrFail();

        return redirect()->route('billing.checkout', [
            'plan'  => $oldPayment->plan_id,
            'cycle' => $oldPayment->billing_cycle,
        ]);
    }
}
