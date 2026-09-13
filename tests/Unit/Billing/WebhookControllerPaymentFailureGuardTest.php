<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Central\WebhookController;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * No payment gateway's failure/past-due webhook may downgrade a payment that
 * already settled (paid, refunded, or superseded) to 'failed' — a late or
 * out-of-order failure event must never cut a tenant's access for money that
 * already cleared. The guard lives centrally in
 * SubscriptionLifecycleService::markFailed(); WebhookController must funnel
 * through it (not TenantBillingPayment::markFailed() directly) for every
 * gateway that terminates in this generic handler.
 */
class WebhookControllerPaymentFailureGuardTest extends TestCase
{
    use BillingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        DB::connection('central')->table('tenants')->insert(['id' => 'tenant-1']);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);
    }

    private function makeSubscriptionAndPayment(string $paymentStatus, string $subscriptionStatus = TenantSubscription::STATUS_ACTIVE): TenantBillingPayment
    {
        $subscription = TenantSubscription::create([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => $subscriptionStatus,
            'ends_at' => now()->addDays(10),
        ]);

        return TenantBillingPayment::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'plan_id' => 1,
            'amount' => 11.49,
            'currency' => 'USD',
            'status' => $paymentStatus,
            'gateway_payment_id' => 'gw_pay_1',
        ]);
    }

    private function handlePaymentFailure(array $result): void
    {
        $controller = new WebhookController();
        $method = (new ReflectionClass($controller))->getMethod('handlePaymentFailure');
        $method->setAccessible(true);
        $method->invoke($controller, $result);
    }

    public static function gatewayProvider(): array
    {
        // The exact gateway set this generic handler must stay compatible
        // with (routes/webhook.php dispatches all of these to
        // WebhookController::handle -> handlePaymentFailure).
        return [
            'stripe' => ['stripe'],
            'paypal' => ['paypal'],
            'paystack' => ['paystack'],
            'flutterwave' => ['flutterwave'],
            'mollie' => ['mollie'],
            'dlocal' => ['dlocal'],
        ];
    }

    /**
     * @dataProvider gatewayProvider
     */
    public function test_a_failure_webhook_from_any_gateway_cannot_downgrade_an_already_paid_payment(string $gateway): void
    {
        $payment = $this->makeSubscriptionAndPayment(TenantBillingPayment::STATUS_PAID);
        $subscription = $payment->subscription;

        $this->handlePaymentFailure([
            'gateway' => $gateway,
            'payment_id' => $payment->id,
            'status' => 'failed',
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    /**
     * @dataProvider gatewayProvider
     */
    public function test_a_failure_webhook_from_any_gateway_cannot_downgrade_a_refunded_payment(string $gateway): void
    {
        $payment = $this->makeSubscriptionAndPayment(TenantBillingPayment::STATUS_REFUNDED, TenantSubscription::STATUS_CANCELLED);

        $this->handlePaymentFailure([
            'gateway' => $gateway,
            'payment_id' => $payment->id,
            'status' => 'failed',
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_a_failure_webhook_still_marks_a_genuinely_pending_payment_failed_and_cuts_access(): void
    {
        $payment = $this->makeSubscriptionAndPayment(TenantBillingPayment::STATUS_PENDING, TenantSubscription::STATUS_PENDING);
        $subscription = $payment->subscription;

        $this->handlePaymentFailure([
            'gateway' => 'stripe',
            'payment_id' => $payment->id,
            'status' => 'failed',
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_FAILED, $subscription->fresh()->status);
    }

    public function test_a_redelivered_failure_webhook_for_an_already_failed_payment_is_a_no_op(): void
    {
        $payment = $this->makeSubscriptionAndPayment(TenantBillingPayment::STATUS_FAILED, TenantSubscription::STATUS_FAILED);
        $subscription = $payment->subscription;

        $this->handlePaymentFailure([
            'gateway' => 'stripe',
            'payment_id' => $payment->id,
            'status' => 'failed',
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_FAILED, $subscription->fresh()->status);
    }
}
