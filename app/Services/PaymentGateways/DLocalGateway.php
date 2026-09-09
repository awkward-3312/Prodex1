<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\TenantBillingPayment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DLocalGateway implements PaymentGatewayInterface
{
    use HasGatewaySettings;

    private const PROD_BASE_URL = 'https://api.dlocal.com';
    private const SANDBOX_BASE_URL = 'https://sandbox.dlocal.com';

    public function getKey(): string
    {
        return 'dlocal';
    }

    protected function getRequiredCredentialKeys(): array
    {
        return ['x_login', 'x_trans_key', 'secret_key'];
    }

    public function isAvailable(): bool
    {
        return $this->isGatewayAvailable();
    }

    public function getDisplayInfo(): array
    {
        return [
            'key'         => 'dlocal',
            'label'       => 'dLocal',
            'description' => 'Regional card payments for Latin America through dLocal.',
            'icon'        => 'bi-credit-card-2-front',
            'color'       => '#6C3BFF',
        ];
    }

    public function createCheckoutSession(
        TenantBillingPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): string {
        $tenant = $payment->tenant;
        $meta = $payment->metadata ?? [];

        $country = strtoupper((string) ($meta['dlocal_country'] ?? $tenant?->country_code ?? 'HN'));
        $payer = $meta['dlocal_payer'] ?? [];

        if (empty($payer['name'])) {
            $payer['name'] = (string) ($tenant?->company_name ?? 'PRODEX Customer');
        }
        if (empty($payer['email'])) {
            $payer['email'] = (string) ($tenant?->admin_email ?? '');
        }
        if (empty($payer['phone']) && ! empty($tenant?->owner_phone)) {
            $payer['phone'] = (string) $tenant->owner_phone;
        }
        $payer['user_reference'] = (string) ($payer['user_reference'] ?? $tenant?->id ?? ('payment-' . $payment->id));

        $result = $this->createCheckoutUrl(
            amount: (float) ($payment->gateway_amount ?? $payment->amount),
            currency: (string) ($payment->gateway_currency ?? $payment->currency),
            productName: ($payment->plan->name ?? 'Subscription') . ' Plan',
            description: ucfirst($payment->billing_cycle) . ' subscription',
            metadata: [
                'payment_id'     => $payment->id,
                'tenant_id'      => $payment->tenant_id,
                'dlocal_country' => $country,
                'dlocal_payer'   => $payer,
            ],
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        );

        $payment->update([
            'gateway_payment_id' => $result['session_id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'dlocal_payment_id' => $result['session_id'],
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

        $country = strtoupper((string) ($metadata['dlocal_country'] ?? 'HN'));
        $payer = (array) ($metadata['dlocal_payer'] ?? []);

        foreach (['name', 'email', 'document', 'birth_date'] as $required) {
            if (empty($payer[$required])) {
                throw new \InvalidArgumentException("dLocal payer field [{$required}] is required.");
            }
        }

        if (empty($payer['user_reference'])) {
            $payer['user_reference'] = ! empty($metadata['tenant_id'])
                ? (string) $metadata['tenant_id']
                : 'registration-' . (string) ($metadata['registration_id'] ?? uniqid());
        }

        $orderId = ! empty($metadata['payment_id'])
            ? 'payment-' . (int) $metadata['payment_id']
            : 'registration-' . (int) ($metadata['registration_id'] ?? 0);

        if ($orderId === 'registration-0') {
            throw new \InvalidArgumentException('dLocal requires an internal payment or registration identifier.');
        }

        $returnState = Crypt::encryptString(json_encode([
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ], JSON_UNESCAPED_SLASHES));

        $centralBase = rtrim((string) config('app.url'), '/');

        $body = [
            'amount'              => round($amount, 2),
            'currency'            => strtoupper($currency),
            'country'             => $country,
            'payment_method_id'   => 'CARD',
            'payment_method_flow' => 'REDIRECT',
            'payer'               => array_filter([
                'name'           => (string) $payer['name'],
                'email'          => (string) $payer['email'],
                'document'       => preg_replace('/[^A-Za-z0-9]/', '', (string) $payer['document']),
                'birth_date'     => (string) $payer['birth_date'],
                'phone'          => ! empty($payer['phone']) ? (string) $payer['phone'] : null,
                'user_reference' => (string) $payer['user_reference'],
                'ip'             => ! empty($payer['ip']) ? (string) $payer['ip'] : null,
                'device_id'      => ! empty($payer['device_id']) ? (string) $payer['device_id'] : null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'order_id'         => $orderId,
            'description'      => trim($productName . ' - ' . $description),
            'notification_url' => $centralBase . '/webhook/dlocal',
            'callback_url'     => $centralBase . '/payments/dlocal/return?state=' . rawurlencode($returnState),
        ];

        $response = $this->request('POST', '/payments', $body);

        $paymentId = (string) ($response['id'] ?? '');
        $redirectUrl = (string) ($response['redirect_url'] ?? '');

        if ($paymentId === '' || $redirectUrl === '') {
            throw new \RuntimeException('dLocal did not return a payment ID and redirect URL.');
        }

        return [
            'url'        => $redirectUrl,
            'session_id' => $paymentId,
        ];
    }

    public function verifyPaymentStatus(string $sessionId): array
    {
        $result = [
            'status'             => 'unknown',
            'gateway_payment_id' => $sessionId,
            'transaction_id'     => $sessionId,
        ];

        try {
            $data = $this->request('GET', '/payments/' . rawurlencode($sessionId));
            $result['status'] = $this->normalizeStatus((string) ($data['status'] ?? ''));
            $result['gateway_payment_id'] = (string) ($data['id'] ?? $sessionId);
            $result['transaction_id'] = (string) ($data['id'] ?? $sessionId);
        } catch (\Throwable $e) {
            Log::error("dLocal verifyPaymentStatus failed: {$e->getMessage()}");
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
            'amount'             => null,
            'currency'           => null,
        ];

        $headerData = json_decode($signature, true);
        if (! is_array($headerData)) {
            $headerData = [
                'date' => (string) request()->header('X-Date', ''),
                'signature' => (string) (request()->header('Signature') ?: request()->header('Authorization', '')),
            ];
        }

        $date = (string) ($headerData['date'] ?? '');
        $receivedSignature = $this->normalizeSignature((string) ($headerData['signature'] ?? ''));

        if ($date === '' || $receivedSignature === '') {
            return $result;
        }

        $this->loadSettings();
        $expected = hash_hmac(
            'sha256',
            $this->resolvedCredentials['x_login'] . $date . $payload,
            $this->resolvedCredentials['secret_key']
        );

        if (! hash_equals(strtolower($expected), strtolower($receivedSignature))) {
            return $result;
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return $result;
        }

        $orderId = (string) ($data['order_id'] ?? '');
        if (preg_match('/^payment-(\d+)$/', $orderId, $m)) {
            $result['payment_id'] = (int) $m[1];
        } elseif (preg_match('/^registration-(\d+)$/', $orderId, $m)) {
            $result['registration_id'] = (int) $m[1];
        }

        $result['valid'] = true;
        $result['event_type'] = 'payment.' . strtolower((string) ($data['status'] ?? 'updated'));
        $result['gateway_payment_id'] = (string) ($data['id'] ?? '');
        $result['transaction_id'] = (string) ($data['id'] ?? '');
        $result['status'] = $this->normalizeStatus((string) ($data['status'] ?? ''));
        $result['amount'] = isset($data['amount']) ? (float) $data['amount'] : null;
        $result['currency'] = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;

        return $result;
    }

    public function verifyCallback(array $input): bool
    {
        $paymentId = (string) ($input['paymentId'] ?? '');
        $status = (string) ($input['status'] ?? '');
        $date = (string) ($input['date'] ?? '');
        $received = $this->normalizeSignature((string) ($input['signature'] ?? ''));

        if ($paymentId === '' || $status === '' || $date === '' || $received === '') {
            return false;
        }

        $this->loadSettings();
        $requestBody = '{status:' . $status . ',paymentId:' . $paymentId . '}';
        $expected = hash_hmac(
            'sha256',
            $this->resolvedCredentials['x_login'] . $date . $requestBody,
            $this->resolvedCredentials['secret_key']
        );

        return hash_equals(strtolower($expected), strtolower($received));
    }

    protected function request(string $method, string $path, array $body = []): array
    {
        $this->loadSettings();

        $jsonBody = $body === [] ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonBody === false) {
            throw new \RuntimeException('Unable to encode dLocal request payload.');
        }

        $date = now('UTC')->format('Y-m-d\TH:i:s.v\Z');
        $signature = hash_hmac(
            'sha256',
            $this->resolvedCredentials['x_login'] . $date . $jsonBody,
            $this->resolvedCredentials['secret_key']
        );

        $client = Http::timeout(30)
            ->acceptJson()
            ->withHeaders([
                'X-Date'        => $date,
                'X-Login'       => $this->resolvedCredentials['x_login'],
                'X-Trans-Key'   => $this->resolvedCredentials['x_trans_key'],
                'X-Version'     => '2.1',
                'User-Agent'    => 'PRODEX/1.0',
                'Authorization' => 'V2-HMAC-SHA256, Signature: ' . $signature,
                'Content-Type'  => 'application/json',
            ]);

        $url = $this->baseUrl() . $path;
        $response = strtoupper($method) === 'GET'
            ? $client->get($url)
            : $client->withBody($jsonBody, 'application/json')->send(strtoupper($method), $url);

        if (! $response->successful()) {
            Log::error('dLocal API request failed', [
                'status' => $response->status(),
                'path'   => $path,
                'body'   => $response->json(),
            ]);
            throw new \RuntimeException('dLocal request failed with HTTP ' . $response->status() . '.');
        }

        return (array) $response->json();
    }

    protected function baseUrl(): string
    {
        return $this->isTestMode() ? self::SANDBOX_BASE_URL : self::PROD_BASE_URL;
    }

    protected function isTestMode(): bool
    {
        try {
            return (bool) DB::connection('central')
                ->table('payment_gateway_settings')
                ->where('gateway', 'dlocal')
                ->value('test_mode');
        } catch (\Throwable) {
            return true;
        }
    }

    protected function normalizeSignature(string $signature): string
    {
        $signature = trim($signature);
        if (preg_match('/Signature:\s*([a-f0-9]+)/i', $signature, $m)) {
            return $m[1];
        }
        return preg_replace('/[^a-f0-9]/i', '', $signature) ?? '';
    }

    protected function normalizeStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'PAID', 'APPROVED' => 'paid',
            'COMPLETED', 'PENDING' => 'pending',
            'REJECTED', 'FAILED', 'CANCELLED', 'CANCELED', 'ERROR' => 'failed',
            'REFUNDED' => 'refunded',
            default => 'unknown',
        };
    }
}
