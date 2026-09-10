<?php

declare(strict_types=1);

namespace App\Services\Paddle;

/**
 * Creates an opaque signed reference that binds a Paddle checkout to the
 * authenticated PRODEX tenant/subscription that initiated it.
 *
 * The reference is intentionally long-lived because Paddle copies custom_data
 * from the initial transaction to the subscription and then to future renewal
 * transactions. Webhooks can therefore resolve renewals without trusting raw
 * tenant IDs supplied by browser-controlled custom data.
 */
class PaddleCheckoutReference
{
    private string $key;

    public function __construct(?string $key = null)
    {
        $source = $key ?: (string) config('app.key', '');
        $this->key = hash('sha256', 'prodex:paddle:checkout:v1|'.$source, true);
    }

    public function issue(string $tenantId, int $subscriptionId, int $planId, string $billingCycle): string
    {
        $payload = json_encode([
            'v' => 1,
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId,
            'billing_cycle' => $billingCycle,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $encoded = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encoded, $this->key);

        return $encoded.'.'.$signature;
    }

    /**
     * @return array{tenant_id:string,subscription_id:int,plan_id:int,billing_cycle:string}|null
     */
    public function parse(?string $reference): ?array
    {
        if (! is_string($reference) || $reference === '' || ! str_contains($reference, '.')) {
            return null;
        }

        [$encoded, $signature] = explode('.', $reference, 2);
        if ($encoded === '' || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return null;
        }

        $expected = hash_hmac('sha256', $encoded, $this->key);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($encoded);
        if ($decoded === null) {
            return null;
        }

        try {
            $claims = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($claims)
            || ($claims['v'] ?? null) !== 1
            || ! is_string($claims['tenant_id'] ?? null)
            || trim($claims['tenant_id']) === ''
            || ! is_numeric($claims['subscription_id'] ?? null)
            || ! is_numeric($claims['plan_id'] ?? null)
            || ! in_array($claims['billing_cycle'] ?? null, ['monthly', 'yearly'], true)
        ) {
            return null;
        }

        return [
            'tenant_id' => (string) $claims['tenant_id'],
            'subscription_id' => (int) $claims['subscription_id'],
            'plan_id' => (int) $claims['plan_id'],
            'billing_cycle' => (string) $claims['billing_cycle'],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
