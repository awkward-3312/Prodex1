<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaypalGateway implements PaymentGatewayInterface
{
    use HasGatewaySettings;

    public function getKey(): string
    {
        return 'paypal';
    }

    protected function getRequiredCredentialKeys(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function isAvailable(): bool
    {
        return $this->isGatewayAvailable();
    }

    public function getDisplayInfo(): array
    {
        return [
            'key'         => 'paypal',
            'label'       => 'PayPal',
            'description' => 'Accept payments via PayPal accounts and credit/debit cards.',
            'icon'        => 'bi-paypal',
            'color'       => '#003087',
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
                'paypal_order_id' => $result['session_id'],
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

        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->withOptions(['verify' => $this->shouldVerifySsl()])
            ->post($this->getBaseUrl() . '/v2/checkout/orders', [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => strtoupper($currency),
                        'value'         => number_format($amount, 2, '.', ''),
                    ],
                    'description' => $description,
                    'custom_id'   => json_encode($metadata),
                ]],
                'application_context' => [
                    'brand_name'          => config('app.name'),
                    'return_url'          => $successUrl,
                    'cancel_url'          => $cancelUrl,
                    'user_action'         => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]);

        if (! $response->successful()) {
            Log::error('PayPal order creation failed', ['response' => $response->json()]);
            throw new \RuntimeException('Failed to create PayPal order.');
        }

        $order     = $response->json();
        $approveLink = collect($order['links'])->firstWhere('rel', 'approve');

        return [
            'url'        => $approveLink['href'] ?? '',
            'session_id' => $order['id'],
        ];
    }

    public function verifyPaymentStatus(string $sessionId): array
    {
        $result = ['status' => 'unknown', 'gateway_payment_id' => null, 'transaction_id' => null];

        try {
            $this->loadSettings();
            $token   = $this->getAccessToken();
            $baseUrl = $this->getBaseUrl();

            $response = Http::withToken($token)
                ->withOptions(['verify' => $this->shouldVerifySsl()])
                ->get("{$baseUrl}/v2/checkout/orders/{$sessionId}");

            if ($response->successful()) {
                $order = $response->json();
                $result['gateway_payment_id'] = $order['id'] ?? $sessionId;

                $captures = $order['purchase_units'][0]['payments']['captures'] ?? [];
                if (! empty($captures)) {
                    $result['transaction_id'] = $captures[0]['id'] ?? null;
                }

                $status = strtoupper($order['status'] ?? '');
                if ($status === 'COMPLETED' || $status === 'APPROVED') {
                    $result['status'] = 'paid';
                } elseif (in_array($status, ['VOIDED', 'CANCELLED'])) {
                    $result['status'] = 'failed';
                } else {
                    $result['status'] = 'pending';
                }
            }
        } catch (\Throwable $e) {
            Log::error("PayPal verifyPaymentStatus failed: {$e->getMessage()}");
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
        $webhookId = $this->resolvedCredentials['webhook_id'] ?? '';

        $body = json_decode($payload, true);
        if (! $body) {
            return $result;
        }

        try {
            $verification = Http::withToken($this->getAccessToken())
                ->withOptions(['verify' => $this->shouldVerifySsl()])
                ->post($this->getBaseUrl() . '/v1/notifications/verify-webhook-signature', [
                    'auth_algo'         => request()->header('PAYPAL-AUTH-ALGO', ''),
                    'cert_url'          => request()->header('PAYPAL-CERT-URL', ''),
                    'transmission_id'   => request()->header('PAYPAL-TRANSMISSION-ID', ''),
                    'transmission_sig'  => $signature,
                    'transmission_time' => request()->header('PAYPAL-TRANSMISSION-TIME', ''),
                    'webhook_id'        => $webhookId,
                    'webhook_event'     => $body,
                ]);

            if (! $verification->successful() || ($verification->json('verification_status') !== 'SUCCESS')) {
                Log::error('PayPal webhook verification failed', ['response' => $verification->json()]);
                return $result;
            }
        } catch (\Throwable $e) {
            Log::error("PayPal webhook verification error: {$e->getMessage()}");
            return $result;
        }

        $result['valid']      = true;
        $result['event_type'] = $body['event_type'] ?? '';

        $resource = $body['resource'] ?? [];

        // ── Successful payment events ────────────────────────────────────────
        //
        // PAYMENT.CAPTURE.COMPLETED  — capture confirmed, money transferred (Orders API v2)
        // PAYMENT.SALE.COMPLETED     — sale completed (Payments API v1 fallback)
        //
        // NOTE: CHECKOUT.ORDER.APPROVED is intentionally excluded. That event fires
        // when the user approves on PayPal but BEFORE the capture — money has NOT
        // moved yet. Activating subscriptions on approval would grant access before
        // payment is actually settled.
        //
        if (in_array($result['event_type'], [
            'PAYMENT.CAPTURE.COMPLETED',
            'PAYMENT.SALE.COMPLETED',
        ], true)) {
            // Orders API v2: custom_id lives on purchase_units or at resource root.
            // Payments API v1 (SALE): custom field carries the same JSON blob.
            $rawCustom = $resource['purchase_units'][0]['custom_id']
                ?? $resource['custom_id']
                ?? $resource['custom']        // v1 SALE events
                ?? '{}';

            $meta = json_decode($rawCustom, true) ?: [];

            // For SALE events the resource id is the sale/capture id; for ORDER
            // events it is the order id. Store both so findPayment() can resolve.
            $result['gateway_payment_id'] = $resource['id'] ?? '';
            $result['transaction_id']     = $resource['id'] ?? '';
            $result['payment_id']         = $meta['payment_id'] ?? null;
            $result['tenant_id']          = $meta['tenant_id'] ?? null;
            $result['registration_id']    = $meta['registration_id'] ?? null;
            $result['status']             = 'paid';
        }

        // ── Refund / reversal events ─────────────────────────────────────────
        //
        // PAYMENT.CAPTURE.REFUNDED  — refund issued against a capture (v2)
        // PAYMENT.SALE.REFUNDED     — refund issued against a sale    (v1)
        // PAYMENT.SALE.REVERSED     — chargeback / reversal           (v1)
        //
        elseif (in_array($result['event_type'], [
            'PAYMENT.CAPTURE.REFUNDED',
            'PAYMENT.SALE.REFUNDED',
            'PAYMENT.SALE.REVERSED',
        ], true)) {
            // The refund resource does NOT carry custom_id.
            // For CAPTURE refunds: the "up" link points to the original capture.
            //   → extract capture ID → stored as transaction_id on our payment row.
            // For SALE refunds: resource.sale_id holds the original sale ID.
            $captureId = null;

            if ($result['event_type'] === 'PAYMENT.CAPTURE.REFUNDED') {
                $upLink = collect($resource['links'] ?? [])
                    ->firstWhere('rel', 'up');

                if ($upLink) {
                    // href: https://api.paypal.com/v2/payments/captures/{CAPTURE_ID}
                    $captureId = basename(parse_url($upLink['href'], PHP_URL_PATH));
                }
            } else {
                // v1 sale — sale_id is the id that was stored as transaction_id
                $captureId = $resource['sale_id'] ?? $resource['id'] ?? null;
            }

            // transaction_id lookup in findPayment() will resolve our record
            $result['transaction_id']     = $captureId;
            $result['gateway_payment_id'] = $resource['id']; // refund ID itself
            $result['status']             = 'refunded';
        }

        // ── Failure / denial events ──────────────────────────────────────────
        //
        // PAYMENT.CAPTURE.DENIED  — capture attempt denied
        //
        elseif ($result['event_type'] === 'PAYMENT.CAPTURE.DENIED') {
            $result['gateway_payment_id'] = $resource['id'] ?? '';
            $result['status']             = 'failed';
        }

        return $result;
    }

    public function captureOrder(string $orderId): array
    {
        $this->loadSettings();

        $response = Http::withToken($this->getAccessToken())
            ->withOptions(['verify' => $this->shouldVerifySsl()])
            ->withBody('{}', 'application/json')
            ->post($this->getBaseUrl() . "/v2/checkout/orders/{$orderId}/capture");

        $data = $response->json();

        // ORDER_ALREADY_CAPTURED — payment was already completed (e.g. webhook fired first).
        // Fetch the order details to get the capture ID and return success.
        if (! $response->successful()) {
            $issue = $data['details'][0]['issue'] ?? '';
            if ($issue === 'ORDER_ALREADY_CAPTURED') {
                return $this->getOrderCapture($orderId);
            }

            Log::error('PayPal capture failed', [
                'order_id' => $orderId,
                'status'   => $response->status(),
                'response' => $data,
            ]);
            return ['success' => false, 'order_id' => $orderId, 'capture_id' => null, 'status' => null];
        }

        $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;

        return [
            'success'    => $capture && ($capture['status'] ?? '') === 'COMPLETED',
            'order_id'   => $data['id'] ?? $orderId,
            'capture_id' => $capture['id'] ?? null,
            'status'     => $capture['status'] ?? null,
        ];
    }

    /**
     * Fetch an already-completed order and return its capture details.
     * Used when ORDER_ALREADY_CAPTURED is returned by the capture endpoint.
     */
    public function getOrderCapture(string $orderId): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->withOptions(['verify' => $this->shouldVerifySsl()])
            ->get($this->getBaseUrl() . "/v2/checkout/orders/{$orderId}");

        if (! $response->successful()) {
            Log::error('PayPal get order failed', ['order_id' => $orderId, 'response' => $response->json()]);
            return ['success' => false, 'order_id' => $orderId, 'capture_id' => null, 'status' => null];
        }

        $data    = $response->json();
        $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;

        return [
            'success'    => ($data['status'] ?? '') === 'COMPLETED' && $capture,
            'order_id'   => $data['id'] ?? $orderId,
            'capture_id' => $capture['id'] ?? null,
            'status'     => $capture['status'] ?? null,
        ];
    }

    protected function getAccessToken(): string
    {
        $baseUrl = $this->getBaseUrl();

        try {
            $response = Http::asForm()
                ->withBasicAuth(
                    $this->resolvedCredentials['client_id'],
                    $this->resolvedCredentials['client_secret']
                )
                ->withOptions(['verify' => $this->shouldVerifySsl()])
                ->post($baseUrl . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (\Throwable $e) {
            Log::error('PayPal access token request exception', [
                'url'     => $baseUrl . '/v1/oauth2/token',
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to obtain PayPal access token: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            Log::error('PayPal access token request failed', [
                'status'   => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);
            throw new \RuntimeException(
                'Failed to obtain PayPal access token. HTTP ' . $response->status()
                . ': ' . ($response->json('error_description') ?? $response->body())
            );
        }

        return $response->json('access_token');
    }

    protected function shouldVerifySsl(): bool
    {
        // Always verify TLS for production PayPal domains.
        // Only skip verification when explicitly hitting the sandbox AND running
        // on a local/dev environment — never disable for live API endpoints.
        $baseUrl = $this->getBaseUrl();
        $isSandbox = str_contains($baseUrl, 'sandbox');

        return ! ($isSandbox && app()->environment(['local', 'development', 'testing']));
    }

    protected function getBaseUrl(): string
    {
        $row = \DB::connection('central')
            ->table('payment_gateway_settings')
            ->where('gateway', 'paypal')
            ->first();

        $testMode = $row ? (bool) $row->test_mode : true;

        return $testMode
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }
}
