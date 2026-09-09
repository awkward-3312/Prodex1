<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DLocalPaymentGatewayContractTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativePath;
        $content = file_get_contents($path);

        $this->assertNotFalse($content, "Unable to read {$relativePath}");

        return $content;
    }

    public function test_dlocal_is_registered_without_replacing_existing_gateways(): void
    {
        $factory = $this->source('app/Services/PaymentGateways/PaymentGatewayFactory.php');

        $this->assertStringContainsString("'dlocal'      => DLocalGateway::class", $factory);
        $this->assertStringContainsString("'stripe'      => StripeGateway::class", $factory);
        $this->assertStringContainsString("'paypal'      => PaypalGateway::class", $factory);
        $this->assertStringContainsString("'offline'     => OfflineGateway::class", $factory);
    }

    public function test_dlocal_card_checkout_uses_hosted_secure_redirect(): void
    {
        $gateway = $this->source('app/Services/PaymentGateways/DLocalGateway.php');

        $this->assertStringContainsString("'payment_method_id'   => 'CARD'", $gateway);
        $this->assertStringContainsString("'payment_method_flow' => 'REDIRECT'", $gateway);
        $this->assertStringContainsString("request('POST', '/secure_payments'", $gateway);
        $this->assertStringNotContainsString("'card' =>", $gateway);
        $this->assertStringNotContainsString("'cvv'", $gateway);
        $this->assertStringNotContainsString("'number' =>", $gateway);
    }

    public function test_dlocal_webhook_requires_hmac_signature(): void
    {
        $gateway = $this->source('app/Services/PaymentGateways/DLocalGateway.php');

        $this->assertStringContainsString("request()->header('X-Date'", $gateway);
        $this->assertStringContainsString("request()->header('Authorization'", $gateway);
        $this->assertStringContainsString("hash_hmac(\n            'sha256'", $gateway);
        $this->assertStringContainsString('hash_equals', $gateway);
    }

    public function test_3ds_completed_is_not_treated_as_paid(): void
    {
        $gateway = $this->source('app/Services/PaymentGateways/DLocalGateway.php');

        $this->assertStringContainsString("'PAID', 'APPROVED' => 'paid'", $gateway);
        $this->assertStringContainsString("'COMPLETED', 'PENDING' => 'pending'", $gateway);
    }

    public function test_central_american_currency_mapping_matches_dlocal_markets(): void
    {
        $factory = $this->source('app/Services/PaymentGateways/PaymentGatewayFactory.php');

        $this->assertStringContainsString("'HN' => 'HNL'", $factory);
        $this->assertStringContainsString("'GT' => 'GTQ'", $factory);
        $this->assertStringContainsString("'SV', 'PA' => 'USD'", $factory);
        $this->assertStringContainsString("'NI' => 'NIO'", $factory);
        $this->assertStringContainsString("'CR' => 'CRC'", $factory);
    }

    public function test_dlocal_routes_are_isolated_from_existing_gateway_routes(): void
    {
        $routes = $this->source('routes/dlocal.php');
        $provider = $this->source('app/Providers/RouteServiceProvider.php');

        $this->assertStringContainsString("'/webhook/dlocal'", $routes);
        $this->assertStringContainsString("'/payments/dlocal/return'", $routes);
        $this->assertStringContainsString("routes/dlocal.php", $provider);
        $this->assertStringContainsString("routes/tenant_dlocal.php", $provider);
    }
}
