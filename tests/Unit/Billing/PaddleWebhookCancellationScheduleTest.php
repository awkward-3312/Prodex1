<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Central\PaddleWebhookController;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Paddle\PaddleCheckoutReference;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * Exercises PaddleWebhookController::applySubscriptionStatus() directly
 * (via reflection, like syncSubscription's other private-method tests in
 * this codebase) for the two edges that decide whether a locally-tracked
 * cancellation_requested_at flag stays consistent with what Paddle actually
 * reports:
 *
 *   - status=canceled must clear it (Paddle confirms the cancellation the
 *     flag was tracking actually took effect).
 *   - status=active with no scheduled_change must clear it too (the tenant
 *     undid the cancellation through Paddle's own customer portal, outside
 *     PRODEX — a stale local flag must not disagree with Paddle).
 *   - status=active WITH scheduled_change present must preserve it (Paddle
 *     still has the period-end cancellation scheduled; nothing changed).
 */
class PaddleWebhookCancellationScheduleTest extends TestCase
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

    private function makeSubscription(array $overrides = []): TenantSubscription
    {
        return TenantSubscription::create(array_merge([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'status' => TenantSubscription::STATUS_ACTIVE,
            'ends_at' => now()->addDays(10),
            'cancellation_requested_at' => now(),
        ], $overrides));
    }

    private function applyStatus(TenantSubscription $subscription, string $status, ?array $scheduledChange, array $data = []): void
    {
        $controller = new PaddleWebhookController();
        $method = (new ReflectionClass($controller))->getMethod('applySubscriptionStatus');
        $method->setAccessible(true);
        $method->invoke($controller, $subscription, $data, $status, null, now()->addDays(10), null, now(), $scheduledChange);
    }

    public function test_suspended_status_preserves_pending_flag_when_still_scheduled_at_paddle(): void
    {
        $subscription = $this->makeSubscription();

        $this->applyStatus($subscription, 'past_due', ['action' => 'cancel', 'effective_at' => now()->addDays(10)->toIso8601String()]);

        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_suspended_status_clears_stale_pending_flag_when_no_longer_scheduled(): void
    {
        $subscription = $this->makeSubscription();

        $this->applyStatus($subscription, 'past_due', null);

        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_canceled_status_clears_pending_cancellation_flag(): void
    {
        $subscription = $this->makeSubscription();

        $this->applyStatus($subscription, 'canceled', null, ['canceled_at' => now()->toIso8601String()]);

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_active_status_with_no_scheduled_change_clears_stale_pending_flag(): void
    {
        $subscription = $this->makeSubscription();

        $this->applyStatus($subscription, 'active', null);

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_active_status_with_scheduled_cancel_change_preserves_pending_flag(): void
    {
        $subscription = $this->makeSubscription();

        $this->applyStatus($subscription, 'active', ['action' => 'cancel', 'effective_at' => now()->addDays(10)->toIso8601String()]);

        $this->assertNotNull($subscription->fresh()->cancellation_requested_at);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    /**
     * A delayed/retried transaction.completed reactivating a lapsed
     * subscription proves Paddle actually renewed it — not cancelled it — so
     * any stale local "pending cancellation" flag must be cleared, exactly
     * like the subscription.* active branch already does.
     */
    public function test_completed_transaction_reactivation_clears_stale_pending_cancellation_flag(): void
    {
        config(['services.paddle.starter_monthly_price_id' => 'pri_test_monthly']);

        $subscription = $this->makeSubscription([
            'billing_cycle' => 'monthly',
            'ends_at' => now()->subDay(),
            'cancellation_requested_at' => now()->subDays(2),
        ]);

        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);

        $controller = new PaddleWebhookController();
        $method = (new ReflectionClass($controller))->getMethod('handleCompletedTransaction');
        $method->setAccessible(true);
        $method->invoke($controller, 'evt_1', [
            'id' => 'txn_1',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'items' => [['price_id' => 'pri_test_monthly']],
            'details' => ['totals' => ['total' => '1149']],
            'billing_period' => ['starts_at' => now()->toIso8601String(), 'ends_at' => now()->addMonth()->toIso8601String()],
        ], new PaddleCheckoutReference(), new SubscriptionLifecycleService());

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    /**
     * A scheduled_change that is NOT a cancellation (e.g. a scheduled pause
     * or plan change) must not be mistaken for "the cancellation is still
     * scheduled" — only action=cancel means that.
     */
    public function test_active_status_with_non_cancel_scheduled_change_clears_stale_pending_flag(): void
    {
        $subscription = $this->makeSubscription();

        $this->applyStatus($subscription, 'active', ['action' => 'pause', 'effective_at' => now()->addDays(10)->toIso8601String()]);

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    /**
     * A cancellation scheduled through Paddle's own customer portal (never
     * touching PRODEX's cancel endpoint) must still be reflected locally —
     * not just preserved once PRODEX already knew about it.
     */
    public function test_active_status_establishes_pending_flag_when_paddle_reports_a_cancel_schedule_prodex_did_not_know_about(): void
    {
        $subscription = $this->makeSubscription(['cancellation_requested_at' => null]);

        $this->applyStatus($subscription, 'active', ['action' => 'cancel', 'effective_at' => now()->addDays(10)->toIso8601String()]);

        $this->assertNotNull($subscription->fresh()->cancellation_requested_at);
    }
}
