<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Log;

/**
 * Thin, fail-safe bridge between business events (sales, payments, orders)
 * and the WhatsApp sending pipeline. Every public method is wrapped so a
 * messaging failure can never break the surrounding transaction/response.
 *
 * Each method is gated on (a) the global toggle + tenant connection
 * (WhatsAppService::isAvailable) and (b) the tenant's per-trigger automation
 * switch (WhatsappSetting::automationEnabled).
 */
class WhatsAppNotifier
{
    /** Fired after a sale/invoice is created. */
    public static function saleCreated(Sale $sale): void
    {
        self::fire('invoice_created', $sale, [
            '{{payment_status}}' => $sale->payment_statut ?? 'unpaid',
        ]);
    }

    /** Fired after a payment is recorded against a sale. */
    public static function paymentReceived(Sale $sale): void
    {
        self::fire('payment_received', $sale, [
            '{{payment_status}}' => $sale->payment_statut ?? 'paid',
        ]);
    }

    /**
     * Resolve recipient + variables for a sale and dispatch the template.
     */
    protected static function fire(string $automationKey, Sale $sale, array $extra = []): void
    {
        try {
            $settings = WhatsappSetting::instance();

            if (! $settings->automationEnabled($automationKey)) {
                return;
            }

            $service = app(WhatsAppService::class);

            if (! $service->isAvailable()) {
                return;
            }

            $sale->loadMissing('client');
            $client = $sale->client;

            $to = self::resolveNumber($client->phone ?? null, $settings->default_country_code);

            if (! $to) {
                return; // no usable phone number — silently skip
            }

            $variables = array_merge([
                '{{customer_name}}'  => $client->name ?? '',
                '{{invoice_number}}' => $sale->Ref ?? '',
                '{{total_amount}}'   => number_format((float) $sale->GrandTotal, 2),
            ], $extra);

            $service->sendTemplate($automationKey, $to, $variables);
        } catch (\Throwable $e) {
            Log::warning("WhatsAppNotifier {$automationKey} failed: " . $e->getMessage());
        }
    }

    /**
     * Normalize a phone number to digits, prepending the configured default
     * country code when the number looks local (no country prefix).
     */
    protected static function resolveNumber(?string $phone, ?string $defaultCountryCode): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (empty($digits)) {
            return null;
        }

        // Local numbers (commonly starting with 0) get the default country code.
        if ($defaultCountryCode) {
            $cc = preg_replace('/[^0-9]/', '', $defaultCountryCode);
            if ($cc && str_starts_with($digits, '0')) {
                $digits = $cc . ltrim($digits, '0');
            }
        }

        return $digits;
    }
}
