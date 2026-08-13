<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PaymentGatewaySetting extends Model
{
    protected $connection = 'central';

    protected $table = 'payment_gateway_settings';

    protected $fillable = [
        'gateway',
        'is_active',
        'test_mode',
        'credentials',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'test_mode' => 'boolean',
        'credentials' => 'array',
    ];

    /**
     * Gateway definitions: each gateway's configurable credential fields.
     * Fields marked 'secret' will render as password inputs and be encrypted at rest.
     */
    public const GATEWAYS = [
        'stripe' => [
            'label'       => 'Stripe',
            'icon'        => 'bi-stripe',
            'color'       => '#635bff',
            'description' => 'Accept credit/debit card payments worldwide.',
            'fields'      => [
                'public_key'     => ['label' => 'Publishable Key', 'secret' => false, 'placeholder' => 'pk_test_...'],
                'secret_key'     => ['label' => 'Secret Key',      'secret' => true,  'placeholder' => 'sk_test_...'],
                'webhook_secret' => ['label' => 'Webhook Secret',  'secret' => true,  'placeholder' => 'whsec_...'],
            ],
        ],
        'paypal' => [
            'label'       => 'PayPal',
            'icon'        => 'bi-paypal',
            'color'       => '#003087',
            'description' => 'Accept PayPal payments and credit cards.',
            'fields'      => [
                'client_id'     => ['label' => 'Client ID',     'secret' => false, 'placeholder' => 'AV...'],
                'client_secret' => ['label' => 'Client Secret', 'secret' => true,  'placeholder' => 'EL...'],
                'webhook_id'    => ['label' => 'Webhook ID',    'secret' => false, 'placeholder' => 'WH-...'],
            ],
        ],
        'paystack' => [
            'label'       => 'Paystack',
            'icon'        => 'bi-credit-card-2-back',
            'color'       => '#00c3f7',
            'description' => 'Simple payments for African businesses.',
            'fields'      => [
                'public_key' => ['label' => 'Public Key', 'secret' => false, 'placeholder' => 'pk_test_...'],
                'secret_key' => ['label' => 'Secret Key', 'secret' => true,  'placeholder' => 'sk_test_...'],
            ],
        ],
        'flutterwave' => [
            'label'       => 'Flutterwave',
            'icon'        => 'bi-credit-card-fill',
            'color'       => '#f5a623',
            'description' => 'Payments infrastructure for Africa and beyond.',
            'fields'      => [
                'public_key'     => ['label' => 'Public Key',     'secret' => false, 'placeholder' => 'FLWPUBK_TEST-...'],
                'secret_key'     => ['label' => 'Secret Key',     'secret' => true,  'placeholder' => 'FLWSECK_TEST-...'],
                'encryption_key' => ['label' => 'Encryption Key', 'secret' => true,  'placeholder' => 'FLWENCR_TEST-...'],
            ],
        ],
        'mollie' => [
            'label'       => 'Mollie',
            'icon'        => 'bi-credit-card',
            'color'       => '#0B7BFF',
            'description' => 'Accept cards, iDEAL, Bancontact, PayPal, and more via Mollie.',
            'fields'      => [
                'api_key' => ['label' => 'API Key', 'secret' => true, 'placeholder' => 'test_... or live_...'],
            ],
        ],
    ];

    /**
     * Encrypt secret fields before saving.
     */
    public function setCredentialsAttribute($value): void
    {
        if (is_array($value)) {
            $gatewayDef = self::GATEWAYS[$this->gateway] ?? null;
            if ($gatewayDef) {
                foreach ($gatewayDef['fields'] as $key => $field) {
                    if (!empty($field['secret']) && !empty($value[$key])) {
                        $value[$key] = Crypt::encryptString($value[$key]);
                    }
                }
            }
        }

        $this->attributes['credentials'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Get a decrypted credential value.
     */
    public function getCredential(string $key, ?string $default = null): ?string
    {
        $creds = $this->credentials ?? [];
        $raw = $creds[$key] ?? $default;

        if ($raw === null || $raw === '') {
            return $default;
        }

        $gatewayDef = self::GATEWAYS[$this->gateway] ?? null;
        $isSecret = $gatewayDef['fields'][$key]['secret'] ?? false;

        if ($isSecret) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Exception $e) {
                return $raw;
            }
        }

        return $raw;
    }

    /**
     * Get all credentials with secrets decrypted.
     */
    public function getDecryptedCredentials(): array
    {
        $gatewayDef = self::GATEWAYS[$this->gateway] ?? null;
        if (!$gatewayDef) {
            return $this->credentials ?? [];
        }

        $result = [];
        foreach ($gatewayDef['fields'] as $key => $field) {
            $result[$key] = $this->getCredential($key, '');
        }

        return $result;
    }

    /**
     * Get all active gateways.
     */
    public static function getActiveGateways(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('is_active', true)->get();
    }

    /**
     * Load a specific gateway's settings.
     */
    public static function forGateway(string $gateway): ?self
    {
        return static::where('gateway', $gateway)->first();
    }
}
