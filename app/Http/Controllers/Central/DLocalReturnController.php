<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\PendingRegistration;
use App\Models\Central\TenantBillingPayment;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class DLocalReturnController extends Controller
{
    public function __invoke(Request $request)
    {
        $gateway = PaymentGatewayFactory::resolveForWebhook('dlocal');

        if (! $gateway || ! method_exists($gateway, 'verifyCallback')) {
            abort(503, 'dLocal is not configured.');
        }

        if (! $gateway->verifyCallback($request->only(['paymentId', 'status', 'signature', 'date']))) {
            Log::warning('Invalid dLocal callback signature.', [
                'payment_id' => $request->input('paymentId'),
                'status'     => $request->input('status'),
            ]);
            abort(400, 'Invalid dLocal callback signature.');
        }

        try {
            $state = json_decode(
                Crypt::decryptString((string) $request->query('state')),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            Log::warning('Invalid dLocal callback state.', ['message' => $e->getMessage()]);
            abort(400, 'Invalid dLocal callback state.');
        }

        $dlocalPaymentId = (string) $request->input('paymentId');
        $status = strtoupper((string) $request->input('status'));
        $failed = in_array($status, ['REJECTED', 'FAILED', 'CANCELLED', 'CANCELED', 'ERROR'], true);

        if (! empty($state['registration_id'])) {
            $registration = PendingRegistration::find((int) $state['registration_id']);
            if (! $registration || ($registration->gateway_session_id && $registration->gateway_session_id !== $dlocalPaymentId)) {
                abort(404);
            }

            $successUrl = route('central.registration.preparing', ['token' => $registration->token]);
            $cancelUrl = route('central.checkout', ['token' => $registration->token]) . '?cancelled=1';

            return redirect()->away($failed ? $cancelUrl : $successUrl);
        }

        if (! empty($state['payment_id'])) {
            $payment = TenantBillingPayment::with(['tenant', 'subscription'])
                ->find((int) $state['payment_id']);

            if (! $payment || $payment->gateway !== 'dlocal') {
                abort(404);
            }

            if ($payment->gateway_payment_id && $payment->gateway_payment_id !== $dlocalPaymentId) {
                Log::warning('dLocal callback payment ID mismatch.', [
                    'payment_id' => $payment->id,
                    'expected'   => $payment->gateway_payment_id,
                    'received'   => $dlocalPaymentId,
                ]);
                abort(400, 'dLocal payment mismatch.');
            }

            // The callback itself is not enough to activate a subscription.
            // On an approved return, confirm once against dLocal's API; the
            // normal signed webhook remains the asynchronous source of truth.
            if (! $failed && ! $payment->isPaid() && $payment->gateway_payment_id) {
                try {
                    $verified = $gateway->verifyPaymentStatus($payment->gateway_payment_id);
                    if (($verified['status'] ?? null) === 'paid') {
                        app(SubscriptionLifecycleService::class)->markPaid($payment, [
                            'gateway_payment_id' => $verified['gateway_payment_id'] ?? $payment->gateway_payment_id,
                            'transaction_id'     => $verified['transaction_id'] ?? $payment->gateway_payment_id,
                        ]);
                        $payment->refresh();
                    }
                } catch (\Throwable $e) {
                    Log::warning('dLocal return verification failed; waiting for webhook.', [
                        'payment_id' => $payment->id,
                        'message'    => $e->getMessage(),
                    ]);
                }
            }

            $tenantBase = rtrim((string) $payment->tenant?->getTenantUrl(), '/');
            if ($tenantBase === '') {
                $tenantBase = rtrim((string) config('app.url'), '/');
            }

            $successUrl = $tenantBase . '/billing/success?payment=' . $payment->id;
            $cancelUrl = $tenantBase . '/billing/failed?payment=' . $payment->id;

            return redirect()->away($failed ? $cancelUrl : $successUrl);
        }

        abort(400, 'Unknown dLocal callback state.');
    }
}
