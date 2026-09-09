<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
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
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('Invalid dLocal callback state.', ['message' => $e->getMessage()]);
            abort(400, 'Invalid dLocal callback state.');
        }

        $status = strtoupper((string) $request->input('status'));
        $successUrl = (string) ($state['success_url'] ?? '');
        $cancelUrl = (string) ($state['cancel_url'] ?? '');

        // The browser callback never marks a payment as paid. The signed webhook
        // remains the source of truth. APPROVED/COMPLETED only return the user to
        // the existing status page while PRODEX waits for the webhook/API check.
        if (in_array($status, ['REJECTED', 'FAILED', 'CANCELLED', 'CANCELED', 'ERROR'], true)) {
            return redirect()->away($cancelUrl !== '' ? $cancelUrl : rtrim((string) config('app.url'), '/'));
        }

        return redirect()->away($successUrl !== '' ? $successUrl : rtrim((string) config('app.url'), '/'));
    }
}
