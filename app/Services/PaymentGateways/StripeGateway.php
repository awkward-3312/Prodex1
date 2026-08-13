<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Support\Facades\Log;

class StripeGateway implements PaymentGatewayInterface
{
    use HasGatewaySettings;

    public function getKey(): string
    {
        return 'stripe';
    }

    protected function getRequiredCredentialKeys(): array
    {
        return ['publishable_key', 'secret_key'];
    }

    public function isAvailable(): bool
    {
        return $this->isGatewayAvailable();
    }

    public function getDisplayInfo(): array
    {
        return [
            'key'         => 'stripe',
            'label'       => 'Stripe',
            'description' => 'Accept credit cards, debit cards, and other payment methods via Stripe.',
            'icon'        => 'bi-credit-card-2-front',
            'color'       => '#635BFF',
        ];
    }

    public function createCheckoutSession(
        TenantBillingPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): string {
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
            ],
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );

        $payment->update([
            'gateway_payment_id' => $result['session_id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'stripe_session_id' => $result['session_id'],
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
        $stripe = new \Stripe\StripeClient($this->resolvedCredentials['secret_key']);

        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'line_items'           => [[
                'price_data' => [
                    'currency'     => strtolower($currency),
                    'unit_amount'  => (int) round($amount * 100),
                    'product_data' => [
                        'name'        => $productName,
                        'description' => $description,
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata'    => $metadata,
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ]);

        return [
            'url'        => $session->url,
            'session_id' => $session->id,
        ];
    }

    public function verifyPaymentStatus(string $sessionId): array
    {
        $result = ['status' => 'unknown', 'gateway_payment_id' => null, 'transaction_id' => null];

        try {
            $this->loadSettings();
            $stripe = new \Stripe\StripeClient($this->resolvedCredentials['secret_key']);
            $session = $stripe->checkout->sessions->retrieve($sessionId);

            $result['gateway_payment_id'] = $session->id;
            $result['transaction_id'] = $session->payment_intent ?? $session->id;

            if ($session->payment_status === 'paid') {
                $result['status'] = 'paid';
            } elseif ($session->status === 'expired') {
                $result['status'] = 'failed';
            } else {
                $result['status'] = 'pending';
            }
        } catch (\Throwable $e) {
            Log::error("Stripe verifyPaymentStatus failed: {$e->getMessage()}");
        }

        return $result;
    }

    public function verifyWebhook(string $payload, string $signature): array
    {
        $this->loadSettings();
        $webhookSecret = $this->resolvedCredentials['webhook_secret'] ?? '';

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

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (\Exception $e) {
            Log::error("Stripe webhook signature verification failed: {$e->getMessage()}");
            return $result;
        }

        $result['valid']      = true;
        $result['event_type'] = $event->type;

        $sessionEvents = [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
        ];

        if (in_array($event->type, $sessionEvents, true)) {
            $session = $event->data->object;

            $result['gateway_payment_id'] = $session->id;
            $result['transaction_id']     = $session->payment_intent ?? $session->id;
            $result['payment_id']         = $session->metadata->payment_id ?? null;
            $result['tenant_id']          = $session->metadata->tenant_id ?? null;
            $result['registration_id']    = $session->metadata->registration_id ?? null;

            if ($event->type === 'checkout.session.async_payment_failed') {
                $result['status'] = 'failed';
            } elseif ($event->type === 'checkout.session.async_payment_succeeded') {
                $result['status'] = 'paid';
            } else {
                $result['status'] = ($session->payment_status === 'paid') ? 'paid' : 'pending';
            }
        }

        return $result;
    }

    /**
     * Override to handle legacy DB field name (public_key -> publishable_key).
     * Credentials come exclusively from the central payment_gateway_settings
     * table — no .env fallback.
     */
    protected function resolveCredentials(array $dbCreds): array
    {
        return [
            'publishable_key' => $this->decryptIfNeeded($dbCreds['public_key'] ?? $dbCreds['publishable_key'] ?? ''),
            'secret_key' => $this->decryptIfNeeded($dbCreds['secret_key'] ?? ''),
            'webhook_secret' => $this->decryptIfNeeded($dbCreds['webhook_secret'] ?? ''),
        ];
    }
}
