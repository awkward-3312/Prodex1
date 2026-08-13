<?php

namespace App\Services\PaymentGateways;

use App\Models\Central\PaymentGatewaySetting;

abstract class AbstractGateway implements PaymentGatewayInterface
{
    protected PaymentGatewaySetting $setting;

    public function __construct(PaymentGatewaySetting $setting)
    {
        $this->setting = $setting;
    }

    protected function credential(string $key, ?string $default = null): ?string
    {
        return $this->setting->getCredential($key, $default);
    }

    protected function isTestMode(): bool
    {
        return (bool) $this->setting->test_mode;
    }
}
