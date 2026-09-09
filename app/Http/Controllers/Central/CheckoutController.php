<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PendingRegistration;
use App\Models\Central\Plan;
use App\Services\CurrencyConversionService;
use App\Services\PaymentGateways\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function show(string $token)
    {
        $registration = PendingRegistration::where('token', $token)
            ->whereIn('status', [
                PendingRegistration::STATUS_PENDING,
                PendingRegistration::STATUS_PROCESSING,
            ])
            ->valid()
            ->firstOrFail();

        $plan     = Plan::findOrFail($registration->plan_id);
        $gateways = PaymentGatewayFactory::getAvailableGateways();

        $settings = GeneralSetting::instance();

        return view('central.checkout', [
            'registration'   => $registration,
            'plan'           => $plan,
            'cycle'          => $registration->billing_cycle,
            'amount'         => $registration->amount,
            'gateways'       => $gateways,
            'currencyCode'   => $settings->currency_code ?? 'USD',
            'currencySymbol' => $settings->currency_symbol ?? '$',
            'bankDetails'    => $settings->getBankDetails(),
        ]);
    }

    public function process(Request $request, string $token)
    {
        $registration = PendingRegistration::where('token', $token)
            ->whereIn('status', [
                PendingRegistration::STATUS_PENDING,
                PendingRegistration::STATUS_PROCESSING,
            ])
            ->valid()
            ->firstOrFail();

        $rules = [
            'gateway' => ['required', 'string', 'in:dlocal,stripe,paypal,paystack,flutterwave,mollie,offline'],
        ];

        $gatewayKey = $request->input('gateway');

        if ($gatewayKey === 'offline') {
            $rules['payment_proof'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        }

        if ($gatewayKey === 'dlocal') {
            $rules += [
                'dlocal_country'    => ['required', 'string', 'size:2', 'in:HN,GT,SV,NI,CR,PA,MX,CO,PE,CL,BR,AR,UY,PY,BO,DO'],
                'dlocal_name'       => ['required', 'string', 'min:2', 'max:100'],
                'dlocal_document'   => ['required', 'string', 'min:5', 'max:30', 'regex:/^[A-Za-z0-9 .-]+$/'],
                'dlocal_birth_date' => ['required', 'date_format:d-m-Y'],
                'dlocal_phone'      => ['nullable', 'string', 'max:20'],
            ];
        }

        $validated = $request->validate($rules);

        $gateway = PaymentGatewayFactory::resolve($gatewayKey);

        if (! $gateway) {
            return back()->withErrors(['gateway' => 'Selected payment gateway is not available.']);
        }

        $plan = Plan::findOrFail($registration->plan_id);

        if ($gatewayKey === 'offline') {
            try {
                $proofPath = null;
                if ($request->hasFile('payment_proof')) {
                    $uploadDir = upload_public_path('payment-proofs');
                    if (! \Illuminate\Support\Facades\File::isDirectory($uploadDir)) {
                        \Illuminate\Support\Facades\File::makeDirectory($uploadDir, 0755, true);
                    }
                    $file = $request->file('payment_proof');
                    $filename = 'proof_' . $registration->token . '_' . time() . '.' . $file->getClientOriginalExtension();
                    $file->move($uploadDir, $filename);
                    $proofPath = upload_path('payment-proofs') . '/' . $filename;
                }

                $registration->update([
                    'gateway'            => 'offline',
                    'gateway_session_id' => 'offline_' . uniqid(),
                    'payment_proof_path' => $proofPath,
                    'status'             => PendingRegistration::STATUS_PROCESSING,
                ]);

                return redirect()->route('central.checkout.offline.submitted', ['token' => $registration->token]);
            } catch (\Throwable $e) {
                Log::error("Offline checkout failed for registration {$registration->id}: {$e->getMessage()}", [
                    'exception' => $e,
                ]);
                return back()->withErrors(['payment' => 'Unable to process your submission. Please try again.']);
            }
        }

        $currencyConfig = PaymentGatewayFactory::getGatewayCurrencyConfig($gatewayKey);
        if ($gatewayKey === 'dlocal') {
            $targetCurrency = PaymentGatewayFactory::getDLocalCountryCurrency($validated['dlocal_country']);
            $currencyConfig = [
                'supported_currencies' => [$targetCurrency],
                'default_currency'     => $targetCurrency,
            ];
        }

        $conversion = CurrencyConversionService::resolve(
            (float) $registration->amount,
            $registration->currency,
            $currencyConfig['supported_currencies'],
            $currencyConfig['default_currency']
        );

        $successUrl = route('central.registration.preparing', ['token' => $registration->token]);
        $cancelUrl  = route('central.checkout', ['token' => $registration->token]) . '?cancelled=1';

        try {
            $metadata = [
                'registration_id'    => $registration->id,
                'registration_token' => $registration->token,
            ];

            if ($gatewayKey === 'dlocal') {
                $metadata['dlocal_country'] = strtoupper($validated['dlocal_country']);
                $metadata['dlocal_payer'] = [
                    'name'           => $validated['dlocal_name'],
                    'email'          => $registration->admin_email,
                    'document'       => $validated['dlocal_document'],
                    'birth_date'     => $validated['dlocal_birth_date'],
                    'phone'          => $validated['dlocal_phone'] ?? (($registration->metadata ?? [])['owner_phone'] ?? null),
                    'user_reference' => 'registration-' . $registration->id,
                    'ip'             => $request->ip(),
                ];
            }

            $result = $gateway->createCheckoutUrl(
                amount: $conversion['gateway_amount'],
                currency: $conversion['gateway_currency'],
                productName: $plan->name . ' Plan',
                description: ucfirst($registration->billing_cycle) . ' subscription',
                metadata: $metadata,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
            );

            $actualCurrency = $result['fallback_currency'] ?? $conversion['gateway_currency'];
            $actualAmount   = $result['fallback_amount']   ?? $conversion['gateway_amount'];

            $registrationMeta = $registration->metadata ?? [];
            if ($gatewayKey === 'dlocal') {
                $registrationMeta['dlocal_country'] = strtoupper($validated['dlocal_country']);
            }

            $registration->update([
                'gateway'            => $gatewayKey,
                'gateway_session_id' => $result['session_id'],
                'gateway_currency'   => $actualCurrency,
                'gateway_amount'     => $actualAmount,
                'exchange_rate'      => $conversion['exchange_rate'],
                'conversion_applied' => $conversion['conversion_applied'] || ($actualCurrency !== $conversion['gateway_currency']),
                'status'             => PendingRegistration::STATUS_PROCESSING,
                'metadata'           => $registrationMeta,
            ]);

            return redirect()->away($result['url']);
        } catch (\Throwable $e) {
            Log::error("Checkout failed for registration {$registration->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            return back()->withErrors(['payment' => 'Unable to initiate payment. Please try again.']);
        }
    }

    public function offlineSubmitted(string $token)
    {
        $registration = PendingRegistration::where('token', $token)
            ->where('gateway', 'offline')
            ->firstOrFail();

        $plan = Plan::findOrFail($registration->plan_id);
        $settings = GeneralSetting::instance();

        return view('central.checkout_offline_submitted', [
            'registration'   => $registration,
            'plan'           => $plan,
            'appName'        => $settings->app_name ?? config('app.name'),
        ]);
    }
}
