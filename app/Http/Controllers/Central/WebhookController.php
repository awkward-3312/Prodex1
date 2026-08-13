<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenantWorkspace;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PendingRegistration;
use App\Models\Central\Plan;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\EmailNotificationService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;

class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway)
    {
        $gatewayInstance = PaymentGatewayFactory::resolveForWebhook($gateway);

        if (! $gatewayInstance) {
            Log::warning("Webhook received for unsupported/unconfigured gateway: {$gateway}");
            return response('Gateway not configured.', 400);
        }

        $payload   = $request->getContent();
        $signature = $this->getSignatureHeader($request, $gateway);

        $result = $gatewayInstance->verifyWebhook($payload, $signature);

        if (! $result['valid']) {
            Log::warning("Webhook signature verification failed for {$gateway}.");
            return response('Invalid signature.', 400);
        }

        Log::info("Webhook received: {$gateway} / {$result['event_type']}", [
            'payment_id'      => $result['payment_id'] ?? null,
            'registration_id' => $result['registration_id'] ?? null,
            'status'          => $result['status'],
        ]);

        if ($result['status'] === 'paid') {
            $this->handlePaymentSuccess($result, $gateway);
        } elseif ($result['status'] === 'failed') {
            $this->handlePaymentFailure($result);
        } elseif ($result['status'] === 'refunded') {
            $this->handlePaymentRefund($result);
        }

        return response('OK', 200);
    }

    // ── Payment success ──────────────────────────────────────────────

    protected function handlePaymentSuccess(array $result, string $gateway): void
    {
        // New signup via PendingRegistration
        if (! empty($result['registration_id'])) {
            $this->handleRegistrationPayment($result, $gateway);
            return;
        }

        // Existing tenant billing payment (plan change / renewal)
        $payment = $this->findPayment($result);

        if (! $payment) {
            Log::warning("Webhook: Payment not found.", $result);
            return;
        }

        $lifecycle = app(SubscriptionLifecycleService::class);
        $outcome = $lifecycle->markPaid($payment, [
            'gateway_payment_id' => $result['gateway_payment_id'] ?? null,
            'transaction_id'     => $result['transaction_id'] ?? null,
        ]);

        if ($outcome['already_paid']) {
            Log::info("Webhook: Payment {$payment->id} already marked as paid.");
            return;
        }

        Log::info("Webhook: Payment {$payment->id} marked as paid for tenant {$payment->tenant_id}.", $outcome);
    }

    // ── New signup: create tenant + subscription + payment + provision ─

    protected function handleRegistrationPayment(array $result, string $gateway): void
    {
        $registration = PendingRegistration::find($result['registration_id']);

        if (! $registration) {
            Log::warning("Webhook: PendingRegistration not found.", $result);
            return;
        }

        if (in_array($registration->status, [PendingRegistration::STATUS_COMPLETED, PendingRegistration::STATUS_PROVISIONING], true)) {
            Log::info("Webhook: Registration {$registration->id} already processed.");
            return;
        }

        // The verified charge must cover the amount/currency we requested at
        // checkout. A short-payment or currency mismatch must never provision.
        $expectedAmount   = (float) ($registration->gateway_amount ?? 0);
        $expectedCurrency = strtoupper((string) ($registration->gateway_currency ?? ''));
        $paidAmount       = array_key_exists('amount', $result) && $result['amount'] !== null ? (float) $result['amount'] : null;
        $paidCurrency     = strtoupper((string) ($result['currency'] ?? ''));

        if ($expectedAmount > 0 && $paidAmount !== null && $paidAmount + 0.01 < $expectedAmount) {
            Log::error("Webhook: Registration {$registration->id} paid amount {$paidAmount} is less than expected {$expectedAmount} — not provisioning.");
            return;
        }

        if ($expectedCurrency !== '' && $paidCurrency !== '' && $paidCurrency !== $expectedCurrency) {
            Log::error("Webhook: Registration {$registration->id} paid currency {$paidCurrency} does not match expected {$expectedCurrency} — not provisioning.");
            return;
        }

        $registration->update([
            'status'             => PendingRegistration::STATUS_PAID,
            'gateway_payment_id' => $result['gateway_payment_id'] ?? null,
            'transaction_id'     => $result['transaction_id'] ?? null,
            'paid_at'            => now(),
        ]);

        try {
            $this->provisionFromRegistration($registration, $gateway);
        } catch (\Throwable $e) {
            Log::error("Webhook: Failed to provision from registration {$registration->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            $registration->update(['status' => PendingRegistration::STATUS_FAILED]);
        }
    }

    public function provisionFromRegistration(PendingRegistration $registration, string $gateway, bool $skipProvisioning = false): void
    {
        $plan      = Plan::findOrFail($registration->plan_id);
        $now       = now();
        $subdomain = $registration->subdomain;

        $meta = $registration->metadata ?? [];

        $settings = GeneralSetting::instance();
        $isShared = $settings->isSharedHosting();
        $holdAsPending = $isShared || $skipProvisioning;

        // 1. Create tenant (attributes at top level — VirtualColumn stores them in `data` JSON)
        $tenantAttrs = [
            'id'                  => Str::uuid()->toString(),
            'company_name'        => $registration->company_name,
            'admin_email'         => $registration->admin_email,
            'admin_password_hash' => $registration->admin_password_hash,
            'status'              => $holdAsPending ? Tenant::STATUS_PENDING : Tenant::STATUS_PROVISIONING,
        ];

        if (! empty($meta['admin_password_encrypted'])) {
            $tenantAttrs['admin_password_encrypted'] = $meta['admin_password_encrypted'];
        }

        if (! empty($meta['owner_phone'])) {
            $tenantAttrs['owner_phone'] = $meta['owner_phone'];
        }

        $tenant = Tenant::create($tenantAttrs);

        // 2. Create domain (store subdomain only — InitializeTenancyBySubdomain resolves by subdomain)
        if (! Domain::where('domain', $subdomain)->exists()) {
            $tenant->domains()->create(['domain' => $subdomain]);
        }

        // 3. Create subscription
        $subscription = TenantSubscription::create([
            'tenant_id'     => $tenant->id,
            'plan_id'       => $plan->id,
            'billing_cycle' => $registration->billing_cycle,
            'amount'        => $registration->amount,
            'currency'      => $registration->currency,
            'status'        => $holdAsPending ? 'pending' : TenantSubscription::STATUS_ACTIVE,
            'starts_at'     => $now,
            'ends_at'       => $registration->billing_cycle === 'yearly'
                ? $now->copy()->addYear()
                : $now->copy()->addMonth(),
        ]);

        // 4. Create billing payment record (already paid)
        TenantBillingPayment::create([
            'tenant_id'              => $tenant->id,
            'tenant_subscription_id' => $subscription->id,
            'plan_id'                => $plan->id,
            'amount'                 => $registration->amount,
            'currency'               => $registration->currency,
            'gateway_currency'       => $registration->gateway_currency,
            'gateway_amount'         => $registration->gateway_amount,
            'exchange_rate'          => $registration->exchange_rate,
            'conversion_applied'     => $registration->conversion_applied ?? false,
            'billing_cycle'          => $registration->billing_cycle,
            'gateway'                => $gateway,
            'gateway_payment_id'     => $registration->gateway_payment_id,
            'transaction_id'         => $registration->transaction_id,
            'payment_proof_path'     => $registration->payment_proof_path,
            'status'                 => TenantBillingPayment::STATUS_PAID,
            'paid_at'                => $registration->paid_at,
        ]);

        // 5. Link registration to tenant
        $registration->update([
            'status'    => $holdAsPending ? PendingRegistration::STATUS_PAID : PendingRegistration::STATUS_PROVISIONING,
            'tenant_id' => $tenant->id,
        ]);

        // 6. Shared hosting or explicit hold: send emails, await admin approval — NO auto-provisioning
        if ($holdAsPending) {
            try {
                $adminEmail = $settings->email;
                $tenantLocale = $tenant->locale ?? null;

                if ($adminEmail) {
                    \Illuminate\Support\Facades\Mail::to($adminEmail)->send(
                        new \App\Mail\TenantPendingApprovalMail(
                            $registration->company_name,
                            $subdomain,
                            $registration->admin_email,
                            now()->format('M d, Y H:i'),
                            $tenantLocale
                        )
                    );
                }

                \Illuminate\Support\Facades\Mail::to($registration->admin_email)->send(
                    new \App\Mail\TenantUnderReviewMail(
                        $registration->company_name,
                        $settings->app_name ?? config('app.name'),
                        $tenantLocale
                    )
                );
            } catch (\Throwable $e) {
                Log::warning("Failed to send shared hosting notification emails: {$e->getMessage()}");
            }

            Log::info("Webhook: Tenant {$tenant->id} created as PENDING (shared hosting) for registration {$registration->id}.");
            return;
        }

        // VPS mode: dispatch provisioning immediately
        $this->dispatchProvisioning($tenant->id);

        Log::info("Webhook: Tenant {$tenant->id} created and provisioning dispatched for registration {$registration->id}.");
    }

    // ── Payment failure ──────────────────────────────────────────────

    protected function handlePaymentFailure(array $result): void
    {
        if (! empty($result['registration_id'])) {
            $registration = PendingRegistration::find($result['registration_id']);
            if ($registration && $registration->isPending()) {
                $registration->update(['status' => PendingRegistration::STATUS_FAILED]);
                Log::info("Webhook: Registration {$registration->id} marked as failed.");
            }
            return;
        }

        $payment = $this->findPayment($result);

        if (! $payment) {
            Log::warning("Webhook: Payment not found for failure event.", $result);
            return;
        }

        $payment->markFailed();

        $subscription = $payment->subscription;
        if ($subscription) {
            $subscription->update(['status' => TenantSubscription::STATUS_FAILED]);
        }

        Log::info("Webhook: Payment {$payment->id} marked as failed for tenant {$payment->tenant_id}.");

        $tenant = $payment->tenant;
        if ($tenant) {
            EmailNotificationService::paymentFailed($tenant, [
                '{{amount}}' => GeneralSetting::currencySymbol() . number_format((float) $payment->amount, 2),
            ], $subscription);
        }
    }

    // ── Payment refund ───────────────────────────────────────────────

    protected function handlePaymentRefund(array $result): void
    {
        $payment = $this->findPayment($result);

        if (! $payment) {
            Log::warning('Webhook: Payment not found for refund event.', $result);
            return;
        }

        // Idempotency — avoid double-processing the same refund webhook
        if ($payment->isRefunded()) {
            Log::info("Webhook: Payment {$payment->id} already marked as refunded.");
            return;
        }

        // Record the refund ID for audit purposes
        $refundId = $result['gateway_payment_id'] ?? null;
        $payment->markRefunded($refundId);

        // Immediately revoke access — customer received their money back
        $subscription = $payment->subscription;
        if ($subscription) {
            $subscription->update([
                'status'       => TenantSubscription::STATUS_CANCELLED,
                'ends_at'      => now(),
                'cancelled_at' => now(),
            ]);
        }

        Log::info("Webhook: Payment {$payment->id} refunded (refund ID: {$refundId}). Subscription deactivated for tenant {$payment->tenant_id}.");
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function findPayment(array $result): ?TenantBillingPayment
    {
        // 1. Direct payment_id from our own metadata (most reliable)
        if (! empty($result['payment_id'])) {
            $payment = TenantBillingPayment::find($result['payment_id']);
            if ($payment) {
                return $payment;
            }
        }

        // 2. transaction_id — capture ID stored on markPaid(); used by refund events
        //    where the gateway returns the capture/sale ID, not the order ID.
        if (! empty($result['transaction_id'])) {
            $payment = TenantBillingPayment::where('transaction_id', $result['transaction_id'])->first();
            if ($payment) {
                return $payment;
            }
        }

        // 3. gateway_payment_id — order / session ID set at checkout creation
        if (! empty($result['gateway_payment_id'])) {
            return TenantBillingPayment::where('gateway_payment_id', $result['gateway_payment_id'])->first();
        }

        return null;
    }

    public function dispatchProvisioning(string $tenantId): void
    {
        ProvisionTenantWorkspace::dispatchAfterResponse($tenantId);

        Log::info("Provisioning dispatched for tenant {$tenantId}.");
    }

    protected function getSignatureHeader(Request $request, string $gateway): string
    {
        return match ($gateway) {
            'stripe'      => $request->header('Stripe-Signature', ''),
            'paypal'      => $request->header('PAYPAL-TRANSMISSION-SIG', ''),
            'paystack'    => $request->header('X-Paystack-Signature', ''),
            'flutterwave' => $request->header('verif-hash', ''),
            'mollie'      => '', // Mollie has no signature header; verified by re-fetching the payment
            default       => '',
        };
    }
}
