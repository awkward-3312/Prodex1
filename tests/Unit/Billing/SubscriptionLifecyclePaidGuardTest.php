<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Central\PaddleWebhookController;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Paddle\PaddleCheckoutReference;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * A late/replayed "paid" signal (a redelivered webhook, a gateway
 * return-URL revisited after the fact, a Paddle transaction.completed for a
 * txn_id whose payment was since refunded/superseded) must never resurrect
 * a payment that already settled as refunded or superseded — that would
 * silently reactivate a subscription for money that no longer backs it.
 *
 * Valid sources for "paid" are PENDING (first settlement) and FAILED (the
 * provider genuinely recovered the *same* attempt/transaction — Paddle
 * reuses the same txn_id across a payment_failed + later transaction.completed
 * for one recovered attempt, which findOrCreateTransactionPayment() maps to
 * the same TenantBillingPayment row via gateway_payment_id).
 */
class SubscriptionLifecyclePaidGuardTest extends TestCase
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

    private function makePayment(string $status, string $subscriptionStatus = TenantSubscription::STATUS_PENDING): TenantBillingPayment
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
            'gateway_payment_id' => 'gw_pay_1',
        ]);
    }

    public function test_pending_can_be_marked_paid(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_PENDING);

        $outcome = $this->lifecycle->markPaid($payment);

        $this->assertFalse($outcome['refused']);
        $this->assertSame(TenantBillingPayment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_a_recovered_failed_payment_can_be_marked_paid(): void
    {
        // The provider genuinely retried and captured the same attempt.
        $payment = $this->makePayment(TenantBillingPayment::STATUS_FAILED);

        $outcome = $this->lifecycle->markPaid($payment);

        $this->assertFalse($outcome['refused']);
        $this->assertSame(TenantBillingPayment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_a_refunded_payment_cannot_be_reactivated_to_paid(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_REFUNDED, TenantSubscription::STATUS_CANCELLED);
        $subscription = $payment->subscription;

        $outcome = $this->lifecycle->markPaid($payment);

        $this->assertTrue($outcome['refused']);
        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_a_superseded_payment_cannot_be_reactivated_to_paid(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_SUPERSEDED, TenantSubscription::STATUS_PENDING);

        $outcome = $this->lifecycle->markPaid($payment);

        $this->assertTrue($outcome['refused']);
        $this->assertSame(TenantBillingPayment::STATUS_SUPERSEDED, $payment->fresh()->status);
    }

    public function test_provider_paid_also_refuses_a_refunded_payment(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_REFUNDED, TenantSubscription::STATUS_CANCELLED);

        $outcome = $this->lifecycle->markProviderPaid($payment);

        $this->assertTrue($outcome['refused']);
        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_provider_paid_still_allows_a_recovered_failed_transaction(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_FAILED);

        $outcome = $this->lifecycle->markProviderPaid($payment);

        $this->assertFalse($outcome['refused']);
        $this->assertSame(TenantBillingPayment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_already_paid_is_a_no_op_not_a_refusal(): void
    {
        $payment = $this->makePayment(TenantBillingPayment::STATUS_PAID, TenantSubscription::STATUS_ACTIVE);

        $outcome = $this->lifecycle->markPaid($payment);

        $this->assertTrue($outcome['already_paid']);
        $this->assertFalse($outcome['refused']);
    }

    /**
     * End-to-end through PaddleWebhookController::handleCompletedTransaction:
     * a transaction.completed for a txn_id whose payment is already refunded
     * must not reactivate the subscription either — the "money captured, so
     * grant access now" fallback in that handler must stay conditioned on
     * markProviderPaid() actually having applied.
     */
    public function test_paddle_transaction_completed_does_not_reactivate_a_subscription_behind_a_refunded_payment(): void
    {
        config(['services.paddle.starter_monthly_price_id' => 'pri_test_monthly']);

        $subscription = TenantSubscription::create([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => TenantSubscription::STATUS_CANCELLED,
            'ends_at' => now()->subDay(),
        ]);

        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'canceled',
        ]);

        TenantBillingPayment::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'plan_id' => 1,
            'amount' => 11.49,
            'currency' => 'USD',
            'status' => TenantBillingPayment::STATUS_REFUNDED,
            'gateway' => 'paddle',
            'gateway_payment_id' => 'txn_123',
            'transaction_id' => 'txn_123',
            'metadata' => ['paddle_price_id' => 'pri_test_monthly'],
        ]);

        $controller = new PaddleWebhookController();
        $method = (new ReflectionClass($controller))->getMethod('handleCompletedTransaction');
        $method->setAccessible(true);
        $method->invoke($controller, 'evt_1', [
            'id' => 'txn_123',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'details' => ['totals' => ['total' => '1149']],
        ], new PaddleCheckoutReference(), app(SubscriptionLifecycleService::class));

        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, TenantBillingPayment::where('gateway_payment_id', 'txn_123')->first()->status);
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }
}
