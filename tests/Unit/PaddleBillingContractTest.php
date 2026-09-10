<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Paddle\PaddleCheckoutReference;
use App\Services\Paddle\PaddleWebhookVerifier;
use PHPUnit\Framework\TestCase;

class PaddleBillingContractTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath;
        $content = file_get_contents($path);

        $this->assertNotFalse($content, "Unable to read {$relativePath}");

        return $content;
    }

    public function test_checkout_reference_is_signed_and_tamper_evident(): void
    {
        $service = new PaddleCheckoutReference('unit-test-app-key');
        $reference = '7d3f2750-c323-4e81-9ed3-ec9293ea8a4d';

        $signed = $service->issue($reference);

        $this->assertSame($reference, $service->parse($signed));
        $this->assertNull($service->parse($signed.'tampered'));
        $this->assertNull($service->parse(str_replace('7d3f2750', '8d3f2750', $signed)));
    }

    public function test_webhook_signature_uses_raw_body_timestamp_and_hmac_sha256(): void
    {
        $verifier = new PaddleWebhookVerifier();
        $secret = 'pdl_ntfset_unit_test_secret';
        $body = '{"event_id":"evt_test","event_type":"subscription.updated","data":{"id":"sub_test"}}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.':'.$body, $secret);

        $this->assertTrue($verifier->verify(
            $body,
            "ts={$timestamp};h1={$signature}",
            $secret,
            30
        ));

        $this->assertFalse($verifier->verify(
            $body.' ',
            "ts={$timestamp};h1={$signature}",
            $secret,
            30
        ));

        $oldTimestamp = $timestamp - 120;
        $oldSignature = hash_hmac('sha256', $oldTimestamp.':'.$body, $secret);
        $this->assertFalse($verifier->verify(
            $body,
            "ts={$oldTimestamp};h1={$oldSignature}",
            $secret,
            30
        ));
    }

    public function test_paddle_webhook_is_isolated_serialized_and_does_not_use_session_or_csrf_routes(): void
    {
        $routes = $this->source('routes/paddle.php');
        $provider = $this->source('app/Providers/RouteServiceProvider.php');
        $middleware = $this->source('app/Http/Middleware/SerializePaddleWebhook.php');

        $this->assertStringContainsString("'/webhook/paddle'", $routes);
        $this->assertStringContainsString('SerializePaddleWebhook::class', $routes);
        $this->assertStringContainsString("routes/paddle.php", $provider);
        $this->assertStringContainsString("'tenant_paddle.php'", $provider);
        $this->assertStringNotContainsString("middleware('web')", $routes);
        $this->assertStringContainsString("Cache::lock('paddle:webhook:'", $middleware);
        $this->assertStringContainsString('->block(10', $middleware);
    }

    public function test_tenant_checkout_gets_server_signed_custom_data_before_opening_paddle(): void
    {
        $checkout = $this->source('resources/src/views/app/pages/billing/checkout.vue');
        $tenantRoutes = $this->source('routes/tenant_paddle.php');

        $this->assertStringContainsString('axios.post("/api/billing/paddle/prepare"', $checkout);
        $this->assertStringContainsString('customData: data.custom_data', $checkout);
        $this->assertStringContainsString('data.custom_data?.prodex_ref', $checkout);
        $this->assertStringContainsString("prefix('billing/paddle')", $tenantRoutes);
        $this->assertStringContainsString("'/prepare'", $tenantRoutes);
        $this->assertStringContainsString("middleware(['auth:api'])", $tenantRoutes);
    }

    public function test_paddle_price_is_enforced_server_side_for_subscription_and_transaction_records(): void
    {
        $guard = $this->source('app/Services/Paddle/PaddlePriceGuard.php');
        $mapping = $this->source('app/Models/Central/PaddleSubscription.php');
        $payment = $this->source('app/Models/Central/TenantBillingPayment.php');

        $this->assertStringContainsString('assertMatchesSubscription', $guard);
        $this->assertStringContainsString('hash_equals($expected, $actual)', $guard);
        $this->assertStringContainsString('services.paddle.starter_monthly_price_id', $guard);
        $this->assertStringContainsString('services.paddle.starter_yearly_price_id', $guard);
        $this->assertStringContainsString('PaddlePriceGuard::class', $mapping);
        $this->assertStringContainsString('PaddlePriceGuard::class', $payment);
        $this->assertStringContainsString("\$payment->gateway === 'paddle'", $payment);
    }

    public function test_webhook_has_idempotency_mapping_and_provider_owned_periods(): void
    {
        $controller = $this->source('app/Http/Controllers/Central/PaddleWebhookController.php');
        $lifecycle = $this->source('app/Services/Billing/SubscriptionLifecycleService.php');
        $migration = $this->source('database/migrations/2026_09_10_083500_create_paddle_billing_tables.php');

        $this->assertStringContainsString("header('Paddle-Signature'", $controller);
        $this->assertStringContainsString('$request->getContent()', $controller);
        $this->assertStringContainsString('PaddleWebhookEvent::firstOrCreate', $controller);
        $this->assertStringContainsString('lockForUpdate()', $controller);
        $this->assertStringContainsString("\$eventType === 'transaction.completed'", $controller);
        $this->assertStringContainsString("str_starts_with(\$eventType, 'subscription.')", $controller);
        $this->assertStringContainsString('markProviderPaid', $controller);
        $this->assertStringContainsString('public function markProviderPaid', $lifecycle);
        $this->assertStringContainsString("create('paddle_checkout_attempts'", $migration);
        $this->assertStringContainsString("create('paddle_subscriptions'", $migration);
        $this->assertStringContainsString("create('paddle_webhook_events'", $migration);
    }

    public function test_paddle_payments_are_labeled_without_joining_legacy_gateway_factory(): void
    {
        $payment = $this->source('app/Models/Central/TenantBillingPayment.php');
        $factory = $this->source('app/Services/PaymentGateways/PaymentGatewayFactory.php');

        $this->assertStringContainsString("'paddle'      => 'Paddle'", $payment);
        $this->assertStringNotContainsString("'paddle'      => PaddleGateway::class", $factory);
    }
}
