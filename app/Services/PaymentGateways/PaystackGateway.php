<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackGateway implements PaymentGatewayInterface
{
    use HasGatewaySettings;

    public function getKey(): string
    {
        return 'paystack';
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
            'key'         => 'paystack',
            'label'       => 'Paystack',
            'description' => 'Accept payments via cards, bank transfers, and mobile money in Africa.',
            'icon'        => 'bi-bank',
            'color'       => '#00C3F7',
        ];
    }

    public function createCheckoutSession(
        TenantBillingPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): string {
        // Resolve email: tenant admin email → fallback
        $email = 'customer@billing.local';
        $tenant = $payment->tenant;
        if ($tenant) {
            $email = $tenant->admin_email ?? $email;
        }

        // Use gateway_amount/gateway_currency if conversion was applied
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
                'email'      => $email,
            ],
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );

        $payment->update([
            'gateway_payment_id' => $result['session_id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'paystack_reference' => $result['session_id'],
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
        $this->loadSettings();

        $reference = 'pstk_' . bin2hex(random_bytes(16));

        // Resolve customer email from metadata or context
        $email = ! empty($metadata['email']) ? $metadata['email'] : 'customer@billing.local';
        if (! empty($metadata['registration_id'])) {
            $reg = \App\Models\Central\PendingRegistration::find($metadata['registration_id']);
            if ($reg && $reg->admin_email) {
                $email = $reg->admin_email;
            }
        }

        $payload = [
            'email'        => $email,
            'amount'       => (int) round($amount * 100), // Paystack expects amount in kobo/pesewas
            'currency'     => strtoupper($currency),
            'reference'    => $reference,
            'callback_url' => $successUrl,
            'metadata'     => array_merge($metadata, [
                'product_name' => $productName,
                'description'  => $description,
                'cancel_url'   => $cancelUrl,
            ]),
        ];

        $response = Http::withToken($this->resolvedCredentials['secret_key'])
            ->post('https://api.paystack.co/transaction/initialize', $payload);

        // If the merchant's Paystack account doesn't support the requested
        // currency, retry without it so Paystack uses the account default.
        if (! $response->successful() && ($response->json('code') === 'unsupported_currency')) {
            unset($payload['currency']);
            $payload['metadata']['original_currency'] = strtoupper($currency);

            $response = Http::withToken($this->resolvedCredentials['secret_key'])
                ->post('https://api.paystack.co/transaction/initialize', $payload);
        }

        if (! $response->successful() || ! $response->json('status')) {
            Log::error('Paystack transaction init failed', ['response' => $response->json()]);
            throw new \RuntimeException('Failed to initialize Paystack transaction.');
        }

        $data = $response->json('data');

        return [
            'url'        => $data['authorization_url'],
            'session_id' => $data['reference'],
        ];
    }

    /**
     * Verify a transaction server-side using Paystack's Verify endpoint.
     *
     * This MUST be called when the user returns from Paystack to confirm
     * the payment was actually successful. Never trust the redirect alone —
     * always verify server-side to prevent users forging success callbacks.
     *
     * @return array{success: bool, reference: string, transaction_id: string|null, status: string|null, amount: int|null, currency: string|null}
     */
    public function verifyTransaction(string $reference): array
    {
        $this->loadSettings();

        $response = Http::withToken($this->resolvedCredentials['secret_key'])
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful() || ! $response->json('status')) {
            Log::error('Paystack transaction verification failed', [
                'reference' => $reference,
                'response'  => $response->json(),
            ]);
            return [
                'success'        => false,
                'reference'      => $reference,
                'transaction_id' => null,
                'status'         => null,
                'amount'         => null,
                'currency'       => null,
            ];
        }

        $data = $response->json('data');

        return [
            'success'        => ($data['status'] ?? '') === 'success',
            'reference'      => $data['reference'] ?? $reference,
            'transaction_id' => isset($data['id']) ? (string) $data['id'] : null,
            'status'         => $data['status'] ?? null,
            'amount'         => $data['amount'] ?? null,   // in kobo
            'currency'       => $data['currency'] ?? null,
        ];
    }

    public function verifyPaymentStatus(string $sessionId): array
    {
        $result = ['status' => 'unknown', 'gateway_payment_id' => null, 'transaction_id' => null];

        try {
            $this->loadSettings();

            $response = Http::withToken($this->resolvedCredentials['secret_key'])
                ->get("https://api.paystack.co/transaction/verify/{$sessionId}");

            if ($response->successful()) {
                $data = $response->json('data');
                $result['gateway_payment_id'] = $data['reference'] ?? $sessionId;
                $result['transaction_id'] = (string) ($data['id'] ?? '');

                $status = strtolower($data['status'] ?? '');
                if ($status === 'success') {
                    $result['status'] = 'paid';
                } elseif (in_array($status, ['failed', 'abandoned', 'reversed'])) {
                    $result['status'] = 'failed';
                } else {
                    $result['status'] = 'pending';
                }
            }
        } catch (\Throwable $e) {
            Log::error("Paystack verifyPaymentStatus failed: {$e->getMessage()}");
        }

        return $result;
    }

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

        // Paystack signs webhooks with HMAC-SHA512 using the secret key.
        // This is the primary security gate — reject if signature doesn't match.
        $expectedSig = hash_hmac('sha512', $payload, $this->resolvedCredentials['secret_key']);

        if (! hash_equals($expectedSig, $signature)) {
            Log::error('Paystack webhook signature mismatch');
            return $result;
        }

        $body = json_decode($payload, true);
        if (! $body) {
            return $result;
        }

        $result['valid']      = true;
        $result['event_type'] = $body['event'] ?? '';

        $data = $body['data'] ?? [];
        $meta = $data['metadata'] ?? [];

        // ── Successful charge ────────────────────────────────────────────
        if ($result['event_type'] === 'charge.success') {
            $result['gateway_payment_id'] = $data['reference'] ?? '';
            $result['transaction_id']     = (string) ($data['id'] ?? $data['reference'] ?? '');
            $result['payment_id']         = $meta['payment_id'] ?? null;
            $result['tenant_id']          = $meta['tenant_id'] ?? null;
            $result['registration_id']    = $meta['registration_id'] ?? null;
            $result['status']             = 'paid';
            // Paystack reports amounts in kobo — normalize to major units so
            // the webhook handler can compare against the expected charge.
            $result['amount']             = isset($data['amount']) ? ((int) $data['amount']) / 100 : null;
            $result['currency']           = $data['currency'] ?? null;
        }

        // ── Failed charge ────────────────────────────────────────────────
        elseif ($result['event_type'] === 'charge.failed') {
            $result['gateway_payment_id'] = $data['reference'] ?? '';
            $result['transaction_id']     = (string) ($data['id'] ?? $data['reference'] ?? '');
            $result['payment_id']         = $meta['payment_id'] ?? null;
            $result['tenant_id']          = $meta['tenant_id'] ?? null;
            $result['registration_id']    = $meta['registration_id'] ?? null;
            $result['status']             = 'failed';
        }

        // ── Refund events ────────────────────────────────────────────────
        //
        // refund.processed — Paystack confirms a refund has been processed.
        // The refund object nests the original transaction under `transaction`.
        //
        elseif ($result['event_type'] === 'refund.processed') {
            $transaction = $data['transaction'] ?? [];
            $refundMeta  = $transaction['metadata'] ?? $meta;

            $result['gateway_payment_id'] = (string) ($data['id'] ?? '');           // refund ID
            $result['transaction_id']     = (string) ($transaction['id'] ?? '');     // original txn ID
            $result['payment_id']         = $refundMeta['payment_id'] ?? null;
            $result['tenant_id']          = $refundMeta['tenant_id'] ?? null;
            $result['registration_id']    = $refundMeta['registration_id'] ?? null;
            $result['status']             = 'refunded';
        }

        // ── Transfer reversal (chargeback) ───────────────────────────────
        elseif ($result['event_type'] === 'transfer.reversed') {
            $result['gateway_payment_id'] = $data['reference'] ?? '';
            $result['transaction_id']     = (string) ($data['id'] ?? '');
            $result['status']             = 'refunded';
        }

        return $result;
    }

}
