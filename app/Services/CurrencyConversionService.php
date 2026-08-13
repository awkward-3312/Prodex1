<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    /**
     * Resolve the conversion needed for a gateway checkout.
     *
     * Returns a locked conversion record that should be stored on the payment.
     *
     * @return array{
     *     gateway_currency: string,
     *     gateway_amount: float,
     *     exchange_rate: float,
     *     conversion_applied: bool
     * }
     */
    public static function resolve(
        float $systemAmount,
        string $systemCurrency,
        array $supportedCurrencies,
        string $gatewayDefaultCurrency
    ): array {
        $systemCurrency = strtoupper($systemCurrency);
        $supported = array_map('strtoupper', $supportedCurrencies);

        // Gateway supports the system currency — no conversion needed
        if (in_array($systemCurrency, $supported, true)) {
            return [
                'gateway_currency'   => $systemCurrency,
                'gateway_amount'     => $systemAmount,
                'exchange_rate'      => 1.0,
                'conversion_applied' => false,
            ];
        }

        // Conversion required
        $targetCurrency = strtoupper($gatewayDefaultCurrency);
        $rate = self::getExchangeRate($systemCurrency, $targetCurrency);

        $convertedAmount = round($systemAmount * $rate, 2);

        // Apply currency-specific rounding (e.g. NGN/JPY/KRW have no decimals)
        $convertedAmount = self::roundForCurrency($convertedAmount, $targetCurrency);

        return [
            'gateway_currency'   => $targetCurrency,
            'gateway_amount'     => $convertedAmount,
            'exchange_rate'      => $rate,
            'conversion_applied' => true,
        ];
    }

    /**
     * Get exchange rate from cache or API.
     * Cached for 1 hour to avoid excessive API calls.
     */
    public static function getExchangeRate(string $from, string $to): float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exchange_rate:{$from}:{$to}";

        return Cache::remember($cacheKey, 3600, function () use ($from, $to) {
            return self::fetchRateFromApi($from, $to);
        });
    }

    /**
     * Fetch rate from a free exchange rate API.
     * Uses exchangerate-api.com (free tier: 1500 requests/month).
     * Falls back to frankfurter.app if primary fails.
     */
    protected static function fetchRateFromApi(string $from, string $to): float
    {
        // Primary: exchangerate-api.com (open, no key needed for /latest/)
        try {
            $response = Http::timeout(10)
                ->get("https://open.er-api.com/v6/latest/{$from}");

            if ($response->successful()) {
                $rate = $response->json("rates.{$to}");
                if ($rate && $rate > 0) {
                    return (float) $rate;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Primary exchange rate API failed: {$e->getMessage()}");
        }

        // Fallback: frankfurter.app (ECB data, free, no key)
        try {
            $response = Http::timeout(10)
                ->get("https://api.frankfurter.app/latest", [
                    'from'   => $from,
                    'to'     => $to,
                    'amount' => 1,
                ]);

            if ($response->successful()) {
                $rate = $response->json("rates.{$to}");
                if ($rate && $rate > 0) {
                    return (float) $rate;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Fallback exchange rate API failed: {$e->getMessage()}");
        }

        Log::error("All exchange rate APIs failed for {$from} → {$to}");
        throw new \RuntimeException(
            "Unable to fetch exchange rate from {$from} to {$to}. Please try again later or contact support."
        );
    }

    /**
     * Round amount according to currency conventions.
     * Zero-decimal currencies (JPY, KRW, NGN, etc.) should be whole numbers.
     */
    protected static function roundForCurrency(float $amount, string $currency): float
    {
        $zeroDecimal = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX', 'RWF', 'BIF', 'XOF', 'XAF', 'GNF', 'PYG'];

        if (in_array($currency, $zeroDecimal, true)) {
            return (float) round($amount);
        }

        return round($amount, 2);
    }
}
