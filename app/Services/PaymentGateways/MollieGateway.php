<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mollie gateway (Payments API v2).
 *
 * Flow:
 *   - createCheckoutUrl()  → POST /v2/payments, returns the hosted checkout URL
 *                            and the Mollie payment id (tr_xxxxx) used as session id.
 *   - verifyPaymentStatus()→ GET /v2/payments/{id}, used on redirect return.
 *   - verifyWebhook()      → Mollie POSTs only the payment `id` (form-encoded, no
 *                            signature). Security comes from re-fetching the payment
 *                            from the API with the secret key, never from the payload.
 *
 * Credentials: a single API key (test_xxx for sandbox, live_xxx for production).
 * The key prefix itself determines the environment.
 */
class MollieGateway implements PaymentGatewayInterface
{
    protected const API_BASE = 'https://api.mollie.com/v2';

    /**
     * Currencies Mollie expects with zero decimal places.
     * Everything else is formatted with two decimals.
     */
    protected const ZERO_DECIMAL_CURRENCIES = ['JPY', 'ISK'];

    use HasGatewaySettings;

    public function getKey(): string
    {
        return 'mollie';
    }

    protected function getRequiredCredentialKeys(): array
    {
        return ['api_key'];
    }

    public function isAvailable(): bool
    {
        return $this->isGatewayAvailable();
    }

    public function getDisplayInfo(): array
    {
        return [
            'key'         => 'mollie',
            'label'       => 'Mollie',
            'description' => 'Accept cards, iDEAL, Bancontact, PayPal, and more via Mollie.',
            'icon'        => 'bi-credit-card',
            'color'       => '#0B7BFF',
        ];
    }

    protected function getApiKey(): string
    {
        $this->loadSettings();
        $apiKey = trim($this->resolvedCredentials['api_key'] ?? '');

        if ($apiKey === '') {
            throw new \RuntimeException('Mollie API key is not configured.');
        }

        return $apiKey;
    }

    /**
     * Format an amount as a Mollie "value" string matching the currency's decimals.
     */
    protected function formatAmount(float $amount, string $currency): string
    {
        $decimals = in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;

        return number_format($amount, $decimals, '.', '');
    }

    /**
     * Build the webhook URL for Mollie. Returns null for non-public hosts
     * (localhost / *.test), since Mollie rejects unreachable webhook URLs at
     * payment creation. In that case we rely on redirect-return verification.
     *
     * The URL is always anchored to the CENTRAL app domain (config('app.url')),
     * never route()/url(): the `/webhook/{gateway}` route lives in the central
     * route group, so generating it from a tenant request context would emit a
     * tenant-subdomain URL where the route does not exist.
     */
    protected function webhookUrl(): ?string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        $host = parse_url($base, PHP_URL_HOST) ?: '';

        if (
            $base === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
        ) {
            return null;
        }

        return $base . '/webhook/mollie';
    }

    // ──────────────────────────────────────────────
    //  Hosted checkout
    // ──────────────────────────────────────────────

    public function createCheckoutSession(
        TenantBillingPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): string {
        $chargeAmount   = $payment->gateway_amount ?? $payment->amount;
        $chargeCurrency = $payment->gateway_currency ?? $payment->currency;

        $result = $this->createCheckoutUrl(
            amount: (float) $chargeAmount,
            currency: $chargeCurrency,
            productName: ($payment->plan->name ?? 'Subscription') . ' Plan',
            description: ucfirst($payment->billing_cycle) . ' subscription',
            metadata: [
                'payment_id' => $payment->id,
                'tenant_id'  => $payment->tenant_id,
            ],
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );

        $payment->update([
            'gateway_payment_id' => $result['session_id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'mollie_payment_id' => $result['session_id'],
            ]),
        ]);

        return $result['url'];
    }

    public function createCheckoutUrl(
        float $amount,
        string $currency,
        string $productName,
        string $description,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): array {
        $apiKey = $this->getApiKey();

        $meta = array_filter([
            'payment_id'      => $metadata['payment_id'] ?? null,
            'tenant_id'       => $metadata['tenant_id'] ?? null,
            'registration_id' => $metadata['registration_id'] ?? null,
        ], fn ($v) => $v !== null);

        $payload = [
            'amount' => [
                'currency' => strtoupper($currency),
                'value'    => $this->formatAmount($amount, $currency),
            ],
            'description' => mb_substr($productName . ' — ' . $description, 0, 255),
            'redirectUrl' => $successUrl,
            'cancelUrl'   => $cancelUrl,
            'metadata'    => (object) $meta,
        ];

        if ($webhookUrl = $this->webhookUrl()) {
            $payload['webhookUrl'] = $webhookUrl;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post(self::API_BASE . '/payments', $payload);
        } catch (\Throwable $e) {
            Log::error('Mollie payment creation request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Could not connect to Mollie: ' . $e->getMessage());
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['id'])) {
            $message = $body['detail'] ?? $body['title'] ?? 'Unknown error';
            Log::error('Mollie payment creation failed', [
                'http_status' => $response->status(),
                'response'    => $body,
            ]);
            throw new \RuntimeException('Mollie: ' . $message);
        }

        $checkoutUrl = $body['_links']['checkout']['href'] ?? '';
        if ($checkoutUrl === '') {
            throw new \RuntimeException('Mollie did not return a checkout URL.');
        }

        return [
            'url'        => $checkoutUrl,
            'session_id' => $body['id'],
        ];
    }

    // ──────────────────────────────────────────────
    //  Transaction verification
    // ──────────────────────────────────────────────

    /**
     * Fetch a Mollie payment object by id.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchPayment(string $paymentId): ?array
    {
        try {
            $apiKey = $this->getApiKey();

            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->get(self::API_BASE . '/payments/' . $paymentId);

            if (! $response->successful()) {
                Log::error('Mollie fetch payment failed', [
                    'http_status' => $response->status(),
                    'payment_id'  => $paymentId,
                ]);
                return null;
            }

            return $response->json() ?? null;
        } catch (\Throwable $e) {
            Log::error("Mollie fetchPayment failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Map a Mollie payment status (and refund state) to our normalized status.
     */
    protected function mapStatus(array $payment): string
    {
        $status = strtolower($payment['status'] ?? '');

        if ($status === 'paid') {
            // A fully refunded payment still reports status "paid"; detect via amountRefunded.
            $paid     = (float) ($payment['amount']['value'] ?? 0);
            $refunded = (float) ($payment['amountRefunded']['value'] ?? 0);

            if ($refunded > 0 && $refunded >= $paid) {
                return 'refunded';
            }

            return 'paid';
        }

        if (in_array($status, ['failed', 'canceled', 'expired'], true)) {
            return 'failed';
        }

        return 'pending';
    }

    public function verifyPaymentStatus(string $sessionId): array
    {
        $result = ['status' => 'unknown', 'gateway_payment_id' => null, 'transaction_id' => null];

        $payment = $this->fetchPayment($sessionId);
        if (! $payment) {
            return $result;
        }

        $result['gateway_payment_id'] = $payment['id'] ?? $sessionId;
        $result['transaction_id']     = $payment['id'] ?? $sessionId;
        $result['status']             = $this->mapStatus($payment);

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Webhook (Mollie posts only the payment id)
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

        // Mollie sends "id=tr_xxxx" form-encoded; there is no signature header.
        parse_str($payload, $parsed);
        $paymentId = $parsed['id'] ?? '';

        if ($paymentId === '' || ! str_starts_with($paymentId, 'tr_')) {
            Log::warning('Mollie webhook missing payment id.');
            return $result;
        }

        // Re-fetch from the API with our secret key — this IS the verification.
        $payment = $this->fetchPayment($paymentId);
        if (! $payment) {
            return $result;
        }

        $meta = $payment['metadata'] ?? [];
        if (is_object($meta)) {
            $meta = (array) $meta;
        }

        $result['valid']              = true;
        $result['event_type']         = 'payment.' . strtolower($payment['status'] ?? 'unknown');
        $result['gateway_payment_id'] = $payment['id'] ?? $paymentId;
        $result['transaction_id']     = $payment['id'] ?? $paymentId;
        $result['payment_id']         = $meta['payment_id'] ?? null;
        $result['tenant_id']          = $meta['tenant_id'] ?? null;
        $result['registration_id']    = $meta['registration_id'] ?? null;
        $result['status']             = $this->mapStatus($payment);

        return $result;
    }

    /**
     * Convenience used by the registration/billing return-verification flows.
     *
     * @return array{success: bool, transaction_id: string}
     */
    public function verifyTransaction(string $reference): array
    {
        $status = $this->verifyPaymentStatus($reference);

        return [
            'success'        => $status['status'] === 'paid',
            'transaction_id' => (string) ($status['transaction_id'] ?? ''),
        ];
    }
}
