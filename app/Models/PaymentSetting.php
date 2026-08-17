<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant payment gateway credentials (Stripe) for tenant-facing
 * payments: POS, sales, and the storefront. Lives in the tenant database so
 * every tenant charges into its own Stripe account. Not to be confused with
 * App\Models\Central\PaymentGatewaySetting, which holds the platform's own
 * gateway used for subscription billing.
 */
class PaymentSetting extends Model
{
    protected $table = 'payment_settings';

    protected $fillable = [
        'stripe_key', 'stripe_secret', 'card_processing_mode',
    ];

    public const CARD_MODE_EXTERNAL_TERMINAL = 'external_terminal';
    public const CARD_MODE_STRIPE = 'stripe';

    public static function allowedCardProcessingModes(): array
    {
        return [
            self::CARD_MODE_EXTERNAL_TERMINAL,
            self::CARD_MODE_STRIPE,
        ];
    }

    public function effectiveCardProcessingMode(): string
    {
        return in_array($this->card_processing_mode, self::allowedCardProcessingModes(), true)
            ? $this->card_processing_mode
            : self::CARD_MODE_EXTERNAL_TERMINAL;
    }

    public function hasStripeCredentials(): bool
    {
        return trim((string) $this->stripe_key) !== '' && trim((string) $this->stripe_secret) !== '';
    }

    /**
     * Return the single settings row, creating defaults if it does not exist yet.
     */
    public static function current()
    {
        return static::first() ?: static::create([]);
    }
}
