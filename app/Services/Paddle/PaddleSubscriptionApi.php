<?php

declare(strict_types=1);

namespace App\Services\Paddle;

use App\Exceptions\PaddleApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Thin client for the Paddle subscription-cancellation endpoints. This is the
 * only place PRODEX calls out to Paddle's REST API — everywhere else Paddle
 * calls PRODEX via webhook. Base URL always follows services.paddle.environment
 * (sandbox vs live); never hardcode a host.
 */
class PaddleSubscriptionApi
{
    private const EFFECTIVE_FROM_VALUES = ['next_billing_period', 'immediately'];

    public function cancel(string $paddleSubscriptionId, string $effectiveFrom): array
    {
        if (! in_array($effectiveFrom, self::EFFECTIVE_FROM_VALUES, true)) {
            throw new InvalidArgumentException("Invalid effective_from value: {$effectiveFrom}");
        }

        return $this->request(
            'post',
            "/subscriptions/{$paddleSubscriptionId}/cancel",
            ['effective_from' => $effectiveFrom],
            "cancel {$paddleSubscriptionId} ({$effectiveFrom})"
        );
    }

    public function removeScheduledCancellation(string $paddleSubscriptionId): array
    {
        return $this->request(
            'patch',
            "/subscriptions/{$paddleSubscriptionId}",
            ['scheduled_change' => null],
            "remove scheduled cancellation for {$paddleSubscriptionId}"
        );
    }

    private function request(string $method, string $path, array $body, string $context): array
    {
        try {
            $response = Http::withToken((string) config('services.paddle.api_key'))
                ->timeout(15)
                ->{$method}($this->baseUrl().$path, $body);
        } catch (ConnectionException $e) {
            Log::error("Paddle API call could not be sent: {$context}");

            throw new PaddleApiException("Paddle API call could not be sent ({$context}): {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            Log::error("Paddle API call failed: {$context}", [
                'status' => $response->status(),
            ]);

            throw new PaddleApiException("Paddle API call failed ({$context}): HTTP {$response->status()}");
        }

        return (array) $response->json('data', []);
    }

    private function baseUrl(): string
    {
        return config('services.paddle.environment') === 'live'
            ? 'https://api.paddle.com'
            : 'https://sandbox-api.paddle.com';
    }
}
