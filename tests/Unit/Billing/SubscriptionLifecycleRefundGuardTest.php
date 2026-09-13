<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Central\PaddleWebhookController;
use App\Http\Controllers\Central\WebhookController;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * markRefunded() must only accept PAID -> REFUNDED (idempotent no-op if
 * already refunded). Refunding a PENDING, FAILED, or SUPERSEDED payment
 * would fabricate a refund for money that was never actually collected on
 * that row.
 */
class SubscriptionLifecycleRefundGuardTest extends TestCase
{
    use BillingTestSchema;

    private SubscriptionLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        DB::connection('central')->table('tenants')->insert(['id' => 'tenant-1']);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);

        $this->lifecycle = app(SubscriptionLifecycleService::class);
    }

    private function makePayment(string $status, string $subscriptionStatus = TenantSubscription::STATUS_ACTIVE): TenantBillingPayment
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
            'status' => $status,
            'gateway' => 'stripe',
            'gateway_payment_id' => 'gw_pay_1',
        ]);
    }

    public function test_a_paid_payment_can_be_refunded(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_PAID);

        $this->lifecycle->markRefunded($payment, 'refund_1');

        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_refunded_to_refunded_is_idempotent(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_REFUNDED);

        $this->lifecycle->markRefunded($payment, 'refund_2');

        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_a_pending_payment_cannot_be_refunded(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_PENDING);

        $this->lifecycle->markRefunded($payment, 'refund_3');

        $this->assertSame(TenantBillingPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_failed_payment_cannot_be_refunded(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_FAILED);

        $this->lifecycle->markRefunded($payment, 'refund_4');

        $this->assertSame(TenantBillingPayment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_a_superseded_payment_cannot_be_refunded(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_SUPERSEDED);

        $this->lifecycle->markRefunded($payment, 'refund_5');

        $this->assertSame(TenantBillingPayment::STATUS_SUPERSEDED, $payment->fresh()->status);
    }

    /**
     * End-to-end through the generic (Stripe/PayPal/etc) gateway webhook: a
     * refunded event for a payment that was never actually paid must not
     * fabricate a refund, nor cancel the subscription as if money had been
     * given back.
     */
    public function test_generic_gateway_refund_webhook_ignores_a_never_paid_payment(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_PENDING);
        $subscription = $payment->subscription;

        $controller = new WebhookController();
        $method = (new ReflectionClass($controller))->getMethod('handlePaymentRefund');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'payment_id' => $payment->id,
            'gateway_payment_id' => 'refund_evt_1',
            'status' => 'refunded',
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    public function test_generic_gateway_refund_webhook_cancels_subscription_for_a_genuinely_paid_payment(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_PAID);
        $subscription = $payment->subscription;

        $controller = new WebhookController();
        $method = (new ReflectionClass($controller))->getMethod('handlePaymentRefund');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'payment_id' => $payment->id,
            'gateway_payment_id' => 'refund_evt_2',
            'status' => 'refunded',
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    /**
     * A Paddle chargeback event redelivered (or arriving) after the same
     * payment was already refunded ordinarily must still suspend the
     * subscription — the money was genuinely collected and given back at
     * some point, so this is not the "payment never collected anything"
     * case the guard exists to refuse.
     */
    public function test_paddle_chargeback_on_an_already_refunded_payment_still_suspends_the_subscription(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_REFUNDED, TenantSubscription::STATUS_ACTIVE);
        $payment->update(['gateway' => 'paddle', 'gateway_payment_id' => 'txn_cb_1', 'transaction_id' => 'txn_cb_1']);
        $subscription = $payment->subscription;

        $controller = new PaddleWebhookController();
        $method = (new ReflectionClass($controller))->getMethod('handleAdjustment');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'action' => 'chargeback',
            'status' => 'approved',
            'transaction_id' => 'txn_cb_1',
            'id' => 'adj_cb_1',
            'type' => 'full',
        ], $this->lifecycle);

        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
    }

    /**
     * A chargeback referencing a payment that never actually collected
     * money (pending/failed/superseded) must not suspend the subscription —
     * there is nothing real to charge back.
     */
    public function test_paddle_chargeback_on_a_never_paid_payment_does_not_suspend_the_subscription(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_PENDING, TenantSubscription::STATUS_ACTIVE);
        $payment->update(['gateway' => 'paddle', 'gateway_payment_id' => 'txn_cb_2', 'transaction_id' => 'txn_cb_2']);
        $subscription = $payment->subscription;

        $controller = new PaddleWebhookController();
        $method = (new ReflectionClass($controller))->getMethod('handleAdjustment');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'action' => 'chargeback',
            'status' => 'approved',
            'transaction_id' => 'txn_cb_2',
            'id' => 'adj_cb_2',
            'type' => 'full',
        ], $this->lifecycle);

        $this->assertSame(TenantBillingPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }
}
