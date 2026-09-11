<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Models\Central\TenantSubscription;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * A subscription that has requested cancellation-at-period-end must stay
 * ACTIVE (and therefore keep tenant access) until Paddle confirms the
 * cancellation actually took effect. cancellation_requested_at is the local
 * marker for that in-flight state — it must never itself flip `status`.
 */
class TenantSubscriptionCancellationStateTest extends TestCase
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
        ], $overrides));
    }

    public function test_marking_cancellation_requested_does_not_change_status(): void
    {
        $subscription = $this->makeSubscription();

        $subscription->markCancellationRequested();

        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_marking_cancellation_requested_does_not_change_ends_at(): void
    {
        $endsAt = now()->addDays(10);
        $subscription = $this->makeSubscription(['ends_at' => $endsAt]);

        $subscription->markCancellationRequested();

        $this->assertEqualsWithDelta($endsAt->timestamp, $subscription->fresh()->ends_at->timestamp, 1);
    }

    public function test_is_pending_cancellation_true_only_when_active_and_requested(): void
    {
        $subscription = $this->makeSubscription();
        $this->assertFalse($subscription->isPendingCancellation());

        $subscription->markCancellationRequested();
        $this->assertTrue($subscription->fresh()->isPendingCancellation());
    }

    public function test_is_pending_cancellation_false_once_actually_cancelled(): void
    {
        $subscription = $this->makeSubscription();
        $subscription->markCancellationRequested();
        $subscription->refresh();

        $subscription->cancel();

        $this->assertFalse($subscription->fresh()->isPendingCancellation());
    }

    public function test_clear_cancellation_request_reactivates_without_touching_ends_at(): void
    {
        $endsAt = now()->addDays(10);
        $subscription = $this->makeSubscription(['ends_at' => $endsAt]);
        $subscription->markCancellationRequested();

        $subscription->clearCancellationRequest();

        $fresh = $subscription->fresh();
        $this->assertNull($fresh->cancellation_requested_at);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertEqualsWithDelta($endsAt->timestamp, $fresh->ends_at->timestamp, 1);
    }

    public function test_is_pending_cancellation_true_while_trial_with_flag_set(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_TRIAL, 'cancellation_requested_at' => now()]);

        $this->assertTrue($subscription->isPendingCancellation());
    }

    public function test_is_pending_cancellation_true_while_suspended_with_flag_set(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_SUSPENDED, 'cancellation_requested_at' => now()]);

        $this->assertTrue($subscription->isPendingCancellation());
    }

    public function test_is_pending_cancellation_false_once_actually_cancelled_even_with_flag_set(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_CANCELLED, 'cancellation_requested_at' => now()]);

        $this->assertFalse($subscription->isPendingCancellation());
    }

    public function test_transition_to_active_clears_a_stale_pending_cancellation_flag(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_SUSPENDED, 'cancellation_requested_at' => now()]);

        $subscription->transitionTo(TenantSubscription::STATUS_ACTIVE);

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_resume_clears_a_stale_pending_cancellation_flag(): void
    {
        $subscription = $this->makeSubscription([
            'status' => TenantSubscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'ends_at' => now()->addDays(5),
        ]);
        $subscription->update(['cancellation_requested_at' => now()]);

        $subscription->resume();

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_renew_clears_a_stale_pending_cancellation_flag(): void
    {
        $subscription = $this->makeSubscription();
        $subscription->markCancellationRequested();

        $subscription->renew();

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_activate_clears_a_stale_pending_cancellation_flag(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_SUSPENDED]);
        $subscription->update(['cancellation_requested_at' => now()]);

        $subscription->activate();

        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_cancel_still_sets_cancelled_status_immediately_for_the_admin_immediate_path(): void
    {
        $subscription = $this->makeSubscription();

        $subscription->cancel();

        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
    }
}
