<?php

namespace App\Services\PaymentGateways;

class PaymentGatewayFactory
{
    protected static array $gateways = [
        'dlocal'      => DLocalGateway::class,
        'stripe'      => StripeGateway::class,
        'paypal'      => PaypalGateway::class,
        'paystack'    => PaystackGateway::class,
        'flutterwave' => FlutterwaveGateway::class,
        'mollie'      => MollieGateway::class,
        'offline'     => OfflineGateway::class,
    ];

    public static function resolve(string $key): ?PaymentGatewayInterface
    {
        $class = self::$gateways[$key] ?? null;

        if (! $class || ! class_exists($class)) {
            return null;
        }

        $instance = new $class();

        if (! $instance->isAvailable()) {
            return null;
        }

        return $instance;
    }

    /**
     * Resolve a gateway for webhook processing.
     * Unlike resolve(), this only requires valid credentials -- not is_active.
     * Webhooks must be processed even if the gateway is disabled for new checkouts.
     */
    public static function resolveForWebhook(string $key): ?PaymentGatewayInterface
    {
        $class = self::$gateways[$key] ?? null;

        if (! $class || ! class_exists($class)) {
            return null;
        }

        $instance = new $class();

        if (method_exists($instance, 'hasValidCredentials') && $instance->hasValidCredentials()) {
            return $instance;
        }

        if ($instance->isAvailable()) {
            return $instance;
        }

        return null;
    }

    /**
     * @return array<string, array{key: string, label: string, icon: string, color: string}>
     */
    public static function getAvailableGateways(): array
    {
        $available = [];

        foreach (self::$gateways as $key => $class) {
            if (! class_exists($class)) {
                continue;
            }

            $instance = new $class();

            if ($instance->isAvailable()) {
                $info = $instance->getDisplayInfo();
                $info['currency_config'] = self::getGatewayCurrencyConfig($key);
                $available[$key] = $info;
            }
        }

        return $available;
    }

    /**
     * Load per-gateway currency configuration from the database.
     * Falls back to built-in defaults if not configured.
     *
     * @return array{supported_currencies: string[], default_currency: string}
     */
    public static function getGatewayCurrencyConfig(string $key): array
    {
        $row = \DB::connection('central')
            ->table('payment_gateway_settings')
            ->where('gateway', $key)
            ->first();

        if ($row && ! empty($row->supported_currencies)) {
            $supported = json_decode($row->supported_currencies, true);
            if (is_array($supported) && count($supported) > 0) {
                return [
                    'supported_currencies' => array_map('strtoupper', $supported),
                    'default_currency'     => strtoupper($row->default_currency ?: $supported[0]),
                ];
            }
        }

        return self::getDefaultCurrencyPresets()[$key] ?? [
            'supported_currencies' => ['USD'],
            'default_currency'     => 'USD',
        ];
    }

    public static function getDefaultCurrencyPresets(): array
    {
        return [
            'dlocal' => [
                'supported_currencies' => ['USD', 'HNL', 'GTQ', 'CRC', 'PAB', 'MXN', 'COP', 'PEN', 'CLP', 'BRL', 'ARS', 'UYU', 'PYG', 'BOB', 'DOP'],
                'default_currency'     => 'HNL',
            ],
            'stripe' => [
                'supported_currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SGD', 'HKD', 'NZD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'BRL', 'MXN', 'INR'],
                'default_currency'     => 'USD',
            ],
            'paypal' => [
                'supported_currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SGD', 'HKD', 'NZD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'BRL', 'MXN'],
                'default_currency'     => 'USD',
            ],
            'paystack' => [
                'supported_currencies' => ['NGN', 'GHS', 'ZAR', 'KES'],
                'default_currency'     => 'NGN',
            ],
            'flutterwave' => [
                'supported_currencies' => ['NGN', 'GHS', 'ZAR', 'KES', 'TZS', 'UGX', 'RWF', 'XOF', 'XAF'],
                'default_currency'     => 'NGN',
            ],
            'mollie' => [
                'supported_currencies' => ['EUR', 'USD', 'GBP', 'CHF', 'DKK', 'NOK', 'SEK', 'PLN', 'CAD', 'AUD', 'HUF', 'CZK'],
                'default_currency'     => 'EUR',
            ],
        ];
    }

    /**
     * Local settlement currency expected by dLocal for each supported LATAM
     * processing country. Nicaragua and El Salvador primarily use USD in the
     * gateway flow; Panama is also configured as USD for predictable checkout.
     */
    public static function getDLocalCountryCurrency(string $country): string
    {
        return match (strtoupper($country)) {
            'HN' => 'HNL',
            'GT' => 'GTQ',
            'SV', 'NI', 'PA' => 'USD',
            'CR' => 'CRC',
            'MX' => 'MXN',
            'CO' => 'COP',
            'PE' => 'PEN',
            'CL' => 'CLP',
            'BR' => 'BRL',
            'AR' => 'ARS',
            'UY' => 'UYU',
            'PY' => 'PYG',
            'BO' => 'BOB',
            'DO' => 'DOP',
            default => 'USD',
        };
    }

    public static function getAllGatewayDefinitions(): array
    {
        $definitions = [];

        foreach (self::$gateways as $key => $class) {
            if (! class_exists($class)) {
                continue;
            }

            $instance = new $class();
            $info     = $instance->getDisplayInfo();
            $info['fields'] = self::getFieldsForGateway($key);
            $definitions[$key] = $info;
        }

        return $definitions;
    }

    protected static function getFieldsForGateway(string $key): array
    {
        return match ($key) {
            'dlocal' => [
                'x_login' => [
                    'label'       => 'X-Login',
                    'placeholder' => 'Merchant X-Login',
                    'secret'      => false,
                    'help'        => 'From dLocal Dashboard > Settings > API credentials.',
                ],
                'x_trans_key' => [
                    'label'       => 'X-Trans-Key',
                    'placeholder' => 'Merchant transaction key',
                    'secret'      => true,
                ],
                'secret_key' => [
                    'label'       => 'Secret Key',
                    'placeholder' => 'dLocal API secret key',
                    'secret'      => true,
                    'help'        => 'Used to sign API requests, callbacks and payment notifications.',
                ],
            ],
            'stripe' => [
                'publishable_key' => [
                    'label'       => 'Publishable Key',
                    'placeholder' => 'pk_test_...',
                    'secret'      => false,
                ],
                'secret_key' => [
                    'label'       => 'Secret Key',
                    'placeholder' => 'sk_test_...',
                    'secret'      => true,
                ],
                'webhook_secret' => [
                    'label'       => 'Webhook Secret',
                    'placeholder' => 'whsec_...',
                    'secret'      => true,
                ],
            ],
            'paypal' => [
                'client_id' => [
                    'label'       => 'Client ID',
                    'placeholder' => 'AX...',
                    'secret'      => false,
                ],
                'client_secret' => [
                    'label'       => 'Client Secret',
                    'placeholder' => 'EL...',
                    'secret'      => true,
                ],
                'webhook_id' => [
                    'label'       => 'Webhook ID',
                    'placeholder' => 'e.g. 5GP028...',
                    'secret'      => false,
                ],
            ],
            'paystack' => [
                'public_key' => [
                    'label'       => 'Public Key',
                    'placeholder' => 'pk_test_...',
                    'secret'      => false,
                ],
                'secret_key' => [
                    'label'       => 'Secret Key',
                    'placeholder' => 'sk_test_...',
                    'secret'      => true,
                ],
            ],
            'flutterwave' => [
                'public_key' => [
                    'label'       => 'Public Key',
                    'placeholder' => 'FLWPUBK_TEST-...',
                    'secret'      => false,
                    'help'        => 'Starts with FLWPUBK_ (from Flutterwave dashboard)',
                ],
                'secret_key' => [
                    'label'       => 'Secret Key',
                    'placeholder' => 'FLWSECK_TEST-...',
                    'secret'      => true,
                    'help'        => 'Starts with FLWSECK_ (from Flutterwave dashboard)',
                ],
                'encryption_key' => [
                    'label'       => 'Encryption Key',
                    'placeholder' => 'Your Flutterwave Encryption Key',
                    'secret'      => true,
                ],
                'secret_hash' => [
                    'label'       => 'Secret Hash (Webhook)',
                    'placeholder' => 'Set in Flutterwave Dashboard > Webhooks',
                    'secret'      => true,
                ],
            ],
            'mollie' => [
                'api_key' => [
                    'label'       => 'API Key',
                    'placeholder' => 'test_... or live_...',
                    'secret'      => true,
                    'help'        => 'From Mollie Dashboard > Developers > API keys. Use a test_ key for sandbox, live_ for production.',
                ],
            ],
            'offline' => [],
            default => [],
        };
    }
}
