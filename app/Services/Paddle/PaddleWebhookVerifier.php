<?php

declare(strict_types=1);

namespace App\Services\Paddle;

class PaddleWebhookVerifier
{
    public function verify(string $rawBody, string $signatureHeader, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($rawBody === '' || $signatureHeader === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(';', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 'ts' && is_string($value) && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'h1' && is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value)) {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if ($toleranceSeconds > 0 && abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
