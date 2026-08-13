<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Flutterwave v3 gateway.
 *
 * Project rule: this integration MUST always use the Flutterwave v3 API
 * (`https://api.flutterwave.com/v3`) with classic API keys
 * (FLW_PUBLIC_KEY / FLW_SECRET_KEY / FLW_ENCRYPTION_KEY). Never v4, never
 * OAuth client_credentials. Webhooks are verified via the Secret Hash sent
 * in the `verif-hash` header (handled in the WebhookController).
 *
 * Reminder for operators: store keys in `.env`, never hardcoded.
 */
class FlutterwaveGateway implements PaymentGatewayInterface
{
    use HasGatewaySettings;

    /**
     * Flutterwave v3 API base URL — never change to /v4.
     */
    protected const API_BASE = 'https://api.flutterwave.com/v3';

    /**
     * Safely extract a string error message from a v3 API response body.
     */
    protected function extractMessage(array $body, string $fallback = 'Unknown error'): string
    {
        foreach (['message', 'error_description', 'error', 'detail'] as $key) {
            if (! isset($body[$key])) {
                continue;
            }
            $val = $body[$key];
            if (is_string($val) && $val !== '') {
                return $val;
            }
            if (is_array($val)) {
                return json_encode($val);
            }
        }

        return $fallback;
    }

    public function getKey(): string
    {
        return 'flutterwave';
    }

    protected function getRequiredCredentialKeys(): array
    {
        return ['public_key', 'secret_key'];
    }

    public function isAvailable(): bool
    {
        return $this->isGatewayAvailable();
    }

    public function getDisplayInfo(): array
    {
        return [
            'key'         => 'flutterwave',
            'label'       => 'Flutterwave',
            'description' => 'Accept payments via cards, mobile money, and bank transfers.',
            'icon'        => 'bi-globe2',
            'color'       => '#F5A623',
        ];
    }

    /**
     * Resolve the v3 secret key (used as Bearer token).
     */
    protected function getSecretKey(): string
    {
        $this->loadSettings();
        $secretKey = trim($this->resolvedCredentials['secret_key'] ?? '');

        if ($secretKey === '') {
            throw new \RuntimeException('Flutterwave secret key is not configured. Set FLW_SECRET_KEY in your .env / dashboard.');
        }

        return $secretKey;
    }

    // ──────────────────────────────────────────────
    //  Hosted checkout (v3 Standard Payments)
    // ──────────────────────────────────────────────

    public function createCheckoutSession(
        TenantBillingPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): string {
        $chargeAmount   = $payment->gateway_amount ?? $payment->amount;
        $chargeCurrency = $payment->gateway_currency ?? $payment->currency;

        $email = $payment->metadata['email']
            ?? auth()->user()?->email
            ?? null;

        $name = ($payment->plan->name ?? 'Customer');

        $result = $this->createCheckoutUrl(
            amount: (float) $chargeAmount,
            currency: $chargeCurrency,
            productName: ($payment->plan->name ?? 'Subscription') . ' Plan',
            description: ucfirst($payment->billing_cycle) . ' subscription',
            metadata: [
                'payment_id'    => $payment->id,
                'tenant_id'     => $payment->tenant_id,
                'email'         => $email,
                'customer_name' => $name,
            ],
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );

        // If a DCC fallback occurred, update the payment with the actual
        // currency/amount that was sent to Flutterwave.
        $updateData = [
            'gateway_payment_id' => $result['session_id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'flw_tx_ref' => $result['session_id'],
            ]),
        ];

        if (isset($result['fallback_currency']) && strtoupper($result['fallback_currency']) !== strtoupper($chargeCurrency)) {
            $updateData['gateway_currency'] = $result['fallback_currency'];
            $updateData['gateway_amount']   = $result['fallback_amount'];
            $updateData['conversion_applied'] = true;
        }

        $payment->update($updateData);

        return $result['url'];
    }

    /**
     * Create a Flutterwave v3 hosted checkout link.
     *
     * Returns ['url' => <hosted_checkout_url>, 'session_id' => <tx_ref>].
     */
    public function createCheckoutUrl(
        float $amount,
        string $currency,
        string $productName,
        string $description,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): array {
        $secretKey = $this->getSecretKey();
        $txRef     = 'flw' . bin2hex(random_bytes(16));

        // Resolve customer email + name. The registration flow has no auth user,
        // so look up the PendingRegistration when registration_id is set.
        $customerEmail = $metadata['email'] ?? null;
        $customerName  = $metadata['customer_name'] ?? null;

        if (! empty($metadata['registration_id'])) {
            $reg = \App\Models\Central\PendingRegistration::find($metadata['registration_id']);
            if ($reg) {
                $customerEmail = $customerEmail ?: $reg->admin_email;
                $customerName  = $customerName  ?: $reg->company_name;
            }
        }

        if (empty($customerEmail)) {
            $customerEmail = auth()->user()?->email;
        }
        if (empty($customerName)) {
            $customerName = auth()->user()?->username ?? 'Customer';
        }

        if (empty($customerEmail)) {
            throw new \RuntimeException('Customer email is required for Flutterwave payments.');
        }

        $meta = array_filter([
            'payment_id'      => $metadata['payment_id'] ?? null,
            'tenant_id'       => $metadata['tenant_id'] ?? null,
            'registration_id' => $metadata['registration_id'] ?? null,
        ], fn ($v) => $v !== null);

        $payload = [
            'tx_ref'         => $txRef,
            'amount'         => $amount,
            'currency'       => strtoupper($currency),
            'redirect_url'   => $successUrl,
            'customer'       => [
                'email' => $customerEmail,
                'name'  => $customerName,
            ],
            'customizations' => [
                'title'       => config('app.name'),
                'description' => $description,
            ],
            'meta' => $meta,
        ];

        // First attempt with the requested currency
        $originalCurrency = strtoupper($currency);
        $result = $this->postPaymentInit($secretKey, $payload);

        // "DCC Rate not set for currency" → Flutterwave account doesn't support
        // this currency. Try up to two fallbacks:
        //   1. The admin-configured default currency
        //   2. NGN as a hard last resort (most Flutterwave accounts support it)
        if ($result['needs_currency_fallback']) {
            $tried = [$originalCurrency];
            $fallbacks = array_unique(array_filter([
                $this->getDefaultCurrency(),
                'NGN',
            ]));

            foreach ($fallbacks as $fallbackCurrency) {
                if (in_array($fallbackCurrency, $tried, true)) {
                    continue;
                }
                $tried[] = $fallbackCurrency;

                Log::info("Flutterwave DCC fallback: retrying {$originalCurrency} → {$fallbackCurrency}", [
                    'original_amount' => $amount,
                ]);

                $rate = \App\Services\CurrencyConversionService::getExchangeRate($originalCurrency, $fallbackCurrency);
                $payload['currency'] = $fallbackCurrency;
                $payload['amount']   = round($amount * $rate, 2);

                $result = $this->postPaymentInit($secretKey, $payload);

                if ($result['success'] || ! $result['needs_currency_fallback']) {
                    break;
                }
            }
        }

        if (! $result['success']) {
            throw new \RuntimeException('Flutterwave: ' . $result['error']);
        }

        return [
            'url'              => $result['link'],
            'session_id'       => $txRef,
            'fallback_currency' => $payload['currency'],
            'fallback_amount'   => $payload['amount'],
        ];
    }

    /**
     * Post to /v3/payments and return a normalized result.
     */
    protected function postPaymentInit(string $secretKey, array $payload): array
    {
        try {
            $response = Http::withToken($secretKey)
                ->timeout(30)
                ->post(self::API_BASE . '/payments', $payload);
        } catch (\Throwable $e) {
            Log::error('Flutterwave v3 payment init request failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'needs_currency_fallback' => false, 'error' => 'Could not connect to Flutterwave: ' . $e->getMessage(), 'link' => ''];
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? '') !== 'success') {
            $message = $this->extractMessage($body);
            $isDcc   = stripos($message, 'DCC') !== false || stripos($message, 'currency') !== false;

            Log::error('Flutterwave v3 payment init failed', [
                'http_status' => $response->status(),
                'currency'    => $payload['currency'] ?? '',
                'response'    => $body,
            ]);

            return ['success' => false, 'needs_currency_fallback' => $isDcc, 'error' => $message, 'link' => ''];
        }

        $link = $body['data']['link'] ?? '';
        if ($link === '') {
            return ['success' => false, 'needs_currency_fallback' => false, 'error' => 'Flutterwave did not return a checkout link.', 'link' => ''];
        }

        return ['success' => true, 'needs_currency_fallback' => false, 'error' => '', 'link' => $link];
    }

    /**
     * Get the default/fallback currency for this Flutterwave account.
     * Reads from DB gateway settings, falls back to NGN.
     */
    protected function getDefaultCurrency(): string
    {
        try {
            $config = PaymentGatewayFactory::getGatewayCurrencyConfig('flutterwave');
            return strtoupper($config['default_currency'] ?? 'NGN');
        } catch (\Throwable) {
            return 'NGN';
        }
    }

    // ──────────────────────────────────────────────
    //  Transaction verification
    // ──────────────────────────────────────────────

    /**
     * Verify a Flutterwave v3 transaction by tx_ref.
     *
     * @return array{success: bool, transaction_id: string, status: string, amount: float|null, currency: string|null}
     */
    public function verifyTransaction(string $reference): array
    {
        try {
            $secretKey = $this->getSecretKey();

            $response = Http::withToken($secretKey)
                ->timeout(15)
                ->get(self::API_BASE . '/transactions/verify_by_reference', [
                    'tx_ref' => $reference,
                ]);
        } catch (\Throwable $e) {
            Log::error('Flutterwave v3 verify_by_reference failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'transaction_id' => '', 'status' => '', 'amount' => null, 'currency' => null];
        }

        $body   = $response->json() ?? [];
        $data   = $body['data'] ?? [];
        $status = strtolower($data['status'] ?? '');

        return [
            'success'        => in_array($status, ['successful', 'succeeded', 'completed'], true),
            'transaction_id' => (string) ($data['id'] ?? ''),
            'status'         => $status,
            'amount'         => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency'       => $data['currency'] ?? null,
        ];
    }

    /**
     * Verify a Flutterwave transaction by its numeric transaction id
     * (the value Flutterwave appends to redirect URLs as `transaction_id`).
     *
     * @return array{status: string, gateway_payment_id: ?string, transaction_id: ?string}
     */
    public function verifyPaymentStatus(string $sessionId): array
    {
        $result = ['status' => 'unknown', 'gateway_payment_id' => null, 'transaction_id' => null];

        try {
            $secretKey = $this->getSecretKey();

            $response = Http::withToken($secretKey)
                ->timeout(15)
                ->get(self::API_BASE . "/transactions/{$sessionId}/verify");

            if ($response->successful()) {
                $data = $response->json('data') ?? [];
                $result['gateway_payment_id'] = (string) ($data['tx_ref'] ?? $sessionId);
                $result['transaction_id']     = (string) ($data['id'] ?? '');

                $status = strtolower($data['status'] ?? '');
                if (in_array($status, ['successful', 'succeeded', 'completed'], true)) {
                    $result['status'] = 'paid';
                } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                    $result['status'] = 'failed';
                } else {
                    $result['status'] = 'pending';
                }
            }
        } catch (\Throwable $e) {
            Log::error("Flutterwave v3 verifyPaymentStatus failed: {$e->getMessage()}");
        }

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Webhook verification (Secret Hash via verif-hash)
    // ──────────────────────────────────────────────

    public function verifyWebhook(string $payload, string $signature): array
    {
        $result = [
            'valid'              => false,
            'event_type'         => '',
            'gateway_payment_id' => '',
            'transaction_id'     => '',
            'payment_id'         => null,
            'tenant_id'          => null,
            'registration_id'    => null,
            'status'             => 'unknown',
        ];

        $this->loadSettings();
        $secretHash = $this->resolvedCredentials['secret_hash'] ?? '';

        if (! empty($secretHash) && ! hash_equals($secretHash, $signature)) {
            Log::error('Flutterwave webhook hash mismatch');
            return $result;
        }

        $body = json_decode($payload, true);
        if (! $body) {
            return $result;
        }

        $result['valid']      = true;
        $result['event_type'] = $body['event'] ?? '';

        $data = $body['data'] ?? [];
        $meta = $data['meta'] ?? [];

        $eventType    = $result['event_type'];
        $chargeStatus = strtolower($data['status'] ?? '');

        if ($eventType === 'charge.completed' && in_array($chargeStatus, ['successful', 'succeeded', 'completed'], true)) {
            $result['gateway_payment_id'] = $data['tx_ref'] ?? '';
            $result['transaction_id']     = (string) ($data['id'] ?? $data['tx_ref'] ?? '');
            $result['payment_id']         = $meta['payment_id'] ?? null;
            $result['tenant_id']          = $meta['tenant_id'] ?? null;
            $result['registration_id']    = $meta['registration_id'] ?? null;
            $result['status']             = 'paid';
            $result['amount']             = isset($data['amount']) ? (float) $data['amount'] : null;
            $result['currency']           = $data['currency'] ?? null;
        }

        if ($eventType === 'charge.completed' && $chargeStatus === 'failed') {
            $result['gateway_payment_id'] = $data['tx_ref'] ?? '';
            $result['status']             = 'failed';
        }

        return $result;
    }
}
