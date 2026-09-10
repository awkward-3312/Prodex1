<?php

declare(strict_types=1);

namespace App\Services\Paddle;

/**
 * Signs opaque checkout-attempt references before they are sent to Paddle as
 * custom_data. The actual tenant/plan mapping stays server-side in the central
 * database and cannot be forged by browser-controlled input.
 */
class PaddleCheckoutReference
{
    private string $key;

    public function __construct(?string $key = null)
    {
        $source = $key ?: (string) config('app.key', '');
        $this->key = hash('sha256', 'prodex:paddle:checkout:v1|'.$source, true);
    }

    public function issue(string $attemptReference): string
    {
        $reference = strtolower(trim($attemptReference));
        $signature = hash_hmac('sha256', $reference, $this->key);

        return 'v1.'.$reference.'.'.$signature;
    }

    public function parse(?string $signedReference): ?string
    {
        if (! is_string($signedReference) || $signedReference === '') {
            return null;
        }

        $parts = explode('.', $signedReference);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }

        [, $reference, $signature] = $parts;
        $reference = strtolower(trim($reference));

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $reference)
            || ! preg_match('/^[a-f0-9]{64}$/', $signature)
        ) {
            return null;
        }

        $expected = hash_hmac('sha256', $reference, $this->key);

        return hash_equals($expected, $signature) ? $reference : null;
    }
}
