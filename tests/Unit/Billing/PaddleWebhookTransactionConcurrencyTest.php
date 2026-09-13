<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Central\PaddleWebhookController;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantBillingPayment;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Paddle\PaddleCheckoutReference;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * syncSubscription() acquires the 'billing:cancel:{id}' lock but, before
 * this fix, kept using the $subscription instance resolved *before* the
 * lock — a concurrent writer (a customer's cancel/resume click, or another
 * webhook) could commit and release the lock in between, and the stale
 * object would then have its outdated field values written straight back
 * over the newer ones. handleCompletedTransaction()/handleFailedTransaction()/
 * handleAdjustment() had the same class of problem one level up: they never
 * took the lock at all, so two Paddle webhooks for the same subscription (or
 * one of them racing a customer's cancel/resume) could interleave freely.
 *
 * This file proves: (1) entering the lock now re-reads the row so a stale
 * snapshot can't clobber a newer write, (2) all three transaction handlers
 * now share the same lock namespace as syncSubscription()/cancel()/resume(),
 * so two events for the same subscription are serialized rather than
 * racing, and (3) the FASE 2 payment-transition guards (pending->failed,
 * pending|failed->paid, refunded/superseded terminal) and idempotency still
 * hold once everything runs inside that lock.
 */
class PaddleWebhookTransactionConcurrencyTest extends TestCase
{
    use BillingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        config(['services.paddle.starter_monthly_price_id' => 'pri_test_monthly']);

        DB::connection('central')->table('tenants')->insert(['id' => 'tenant-1']);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);
    }

    protected function tearDown(): void
    {
        // A few tests register TenantSubscription::retrieved() listeners to
        // simulate a concurrent writer; they must not leak onto every other
        // test in this process.
        TenantSubscription::flushEventListeners();

        parent::tearDown();
    }

    private function makeSubscription(array $overrides = []): TenantSubscription
    {
        return TenantSubscription::create(array_merge([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'ends_at' => now()->addDays(10),
        ], $overrides));
    }

    private function mapToPaddle(TenantSubscription $subscription, array $overrides = []): PaddleSubscription
    {
        return PaddleSubscription::create(array_merge([
            'tenant_id' => $subscription->tenant_id,
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ], $overrides));
    }

    private function callPrivate(string $method, array $args): mixed
    {
        $controller = new PaddleWebhookController();
        $ref = (new ReflectionClass($controller))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invoke($controller, ...$args);
    }

    private function syncSubscription(array $data, ?\Illuminate\Support\Carbon $occurredAt = null): void
    {
        $this->callPrivate('syncSubscription', [$data, $occurredAt, new PaddleCheckoutReference()]);
    }

    private function handleCompletedTransaction(string $eventId, array $data): void
    {
        $this->callPrivate('handleCompletedTransaction', [$eventId, $data, new PaddleCheckoutReference(), new SubscriptionLifecycleService()]);
    }

    private function handleFailedTransaction(string $eventId, array $data): void
    {
        $this->callPrivate('handleFailedTransaction', [$eventId, $data, new PaddleCheckoutReference(), new SubscriptionLifecycleService()]);
    }

    private function handleAdjustment(array $data): void
    {
        $this->callPrivate('handleAdjustment', [$data, new SubscriptionLifecycleService()]);
    }

    // ── 1. Stale model inside the lock can't clobber a concurrent write ──

    /**
     * A customer's cancel click commits cancellation_requested_at=T1 and
     * releases the lock between this webhook's initial resolveSubscription()
     * read and the lock closure running. Without refresh() inside the lock,
     * the closure's cancellationRequestedAt calculation
     * ($subscription->cancellation_requested_at ?: now()) would read the
     * pre-cancel in-memory null and mint a *new* timestamp, silently
     * discarding T1. With refresh(), it must read T1 and preserve it.
     */
    public function test_stale_subscription_inside_the_lock_cannot_overwrite_a_concurrently_requested_cancellation(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);

        $concurrentCancelAt = now()->subMinutes(5)->startOfSecond();
        $triggered = false;

        // Simulate the concurrent writer: fires once, the moment
        // resolveSubscription() first loads this row (before the lock).
        TenantSubscription::retrieved(function ($model) use (&$triggered, $subscription, $concurrentCancelAt) {
            if (! $triggered && $model->is($subscription)) {
                $triggered = true;
                DB::connection('central')->table('tenant_subscriptions')
                    ->where('id', $subscription->id)
                    ->update(['cancellation_requested_at' => $concurrentCancelAt]);
            }
        });

        $this->syncSubscription([
            'id' => 'sub_123',
            'status' => 'active',
            'scheduled_change' => ['action' => 'cancel', 'effective_at' => now()->addDays(10)->toIso8601String()],
        ]);

        $fresh = $subscription->fresh();
        $this->assertNotNull($fresh->cancellation_requested_at);
        $this->assertTrue(
            $fresh->cancellation_requested_at->equalTo($concurrentCancelAt),
            'Expected the concurrently-set cancellation timestamp to survive; got '.$fresh->cancellation_requested_at
        );
    }

    /**
     * Same staleness hazard, different sensitive field: a concurrent writer
     * already set starts_at; syncSubscription()'s own fallback
     * ($subscription->starts_at ?: $startedAt) must read that committed
     * value through refresh(), not overwrite it with its own fallback.
     */
    public function test_stale_subscription_inside_the_lock_does_not_overwrite_a_concurrently_set_starts_at(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_PENDING, 'starts_at' => null]);
        $this->mapToPaddle($subscription);

        $concurrentStartsAt = now()->subDays(3)->startOfSecond();
        $triggered = false;

        TenantSubscription::retrieved(function ($model) use (&$triggered, $subscription, $concurrentStartsAt) {
            if (! $triggered && $model->is($subscription)) {
                $triggered = true;
                DB::connection('central')->table('tenant_subscriptions')
                    ->where('id', $subscription->id)
                    ->update(['starts_at' => $concurrentStartsAt]);
            }
        });

        $this->syncSubscription([
            'id' => 'sub_123',
            'status' => 'active',
        ]);

        $fresh = $subscription->fresh();
        $this->assertTrue(
            $fresh->starts_at->equalTo($concurrentStartsAt),
            'Expected the concurrently-set starts_at to survive; got '.$fresh->starts_at
        );
    }

    // ── 2. All three transaction handlers share the cancel/resume lock ──

    public function test_handle_completed_transaction_is_serialized_by_the_same_subscription_lock(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);

        $heldLock = Cache::lock('billing:cancel:'.$subscription->id, 20);
        $heldLock->get();

        try {
            $this->expectException(RuntimeException::class);
            $this->handleCompletedTransaction('evt_1', [
                'id' => 'txn_1',
                'subscription_id' => 'sub_123',
                'currency_code' => 'USD',
                'items' => [['price_id' => 'pri_test_monthly']],
                'details' => ['totals' => ['total' => '1149']],
            ]);
        } finally {
            $heldLock->release();
            $this->assertDatabaseCount('tenant_billing_payments', 0, 'central');
        }
    }

    public function test_handle_failed_transaction_is_serialized_by_the_same_subscription_lock(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);

        $heldLock = Cache::lock('billing:cancel:'.$subscription->id, 20);
        $heldLock->get();

        try {
            $this->expectException(RuntimeException::class);
            $this->handleFailedTransaction('evt_1', [
                'id' => 'txn_1',
                'subscription_id' => 'sub_123',
                'currency_code' => 'USD',
                'details' => ['totals' => ['total' => '1149']],
            ]);
        } finally {
            $heldLock->release();
            $this->assertDatabaseCount('tenant_billing_payments', 0, 'central');
        }
    }

    public function test_handle_adjustment_refund_is_serialized_by_the_same_subscription_lock(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        $payment = TenantBillingPayment::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'plan_id' => 1,
            'amount' => 11.49,
            'currency' => 'USD',
            'status' => TenantBillingPayment::STATUS_PAID,
            'gateway' => 'paddle',
            'gateway_payment_id' => 'txn_1',
            'transaction_id' => 'txn_1',
            'metadata' => ['paddle_price_id' => 'pri_test_monthly'],
        ]);

        $heldLock = Cache::lock('billing:cancel:'.$subscription->id, 20);
        $heldLock->get();

        try {
            $this->expectException(RuntimeException::class);
            $this->handleAdjustment([
                'action' => 'refund',
                'status' => 'approved',
                'transaction_id' => 'txn_1',
                'id' => 'adj_1',
                'type' => 'full',
            ]);
        } finally {
            $heldLock->release();
            $this->assertSame(TenantBillingPayment::STATUS_PAID, $payment->fresh()->status);
        }
    }

    // ── 3. Serialized ordering produces a correct, deterministic outcome ──

    /**
     * transaction.completed vs. cancellation scheduling: a customer's cancel
     * commits (subscription now CANCELLED, lapsed) and releases the lock;
     * only then does a transaction.completed for a genuinely captured charge
     * run. It must see the *current* (cancelled) state through refresh(),
     * and — since real money was captured — correctly reactivate rather than
     * silently no-op on stale data. The two events are applied in a
     * well-defined order rather than interleaving.
     */
    public function test_completed_transaction_sees_fresh_state_after_a_concurrent_cancellation_commits(): void
    {
        $subscription = $this->makeSubscription(['ends_at' => now()->addDays(10)]);
        $this->mapToPaddle($subscription);

        $triggered = false;
        TenantSubscription::retrieved(function ($model) use (&$triggered, $subscription) {
            if (! $triggered && $model->is($subscription)) {
                $triggered = true;
                DB::connection('central')->table('tenant_subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'status' => TenantSubscription::STATUS_CANCELLED,
                        'ends_at' => now()->subDay(),
                        'cancelled_at' => now(),
                        'cancellation_requested_at' => null,
                    ]);
            }
        });

        $this->handleCompletedTransaction('evt_1', [
            'id' => 'txn_1',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'items' => [['price_id' => 'pri_test_monthly']],
            'details' => ['totals' => ['total' => '1149']],
            'billing_period' => [
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addMonth()->toIso8601String(),
            ],
        ]);

        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->ends_at->isFuture());
        $this->assertSame(TenantBillingPayment::STATUS_PAID, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);
    }

    /**
     * transaction.payment_failed vs paid: the FASE 2 guard (only
     * PENDING can become FAILED) must still hold when the handler runs
     * inside the lock — a failure event must not downgrade a payment that a
     * completed-transaction event already marked PAID.
     */
    public function test_failed_transaction_cannot_downgrade_an_already_paid_payment(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);

        $this->handleCompletedTransaction('evt_1', [
            'id' => 'txn_1',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'items' => [['price_id' => 'pri_test_monthly']],
            'details' => ['totals' => ['total' => '1149']],
            'billing_period' => [
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addMonth()->toIso8601String(),
            ],
        ]);
        $this->assertSame(TenantBillingPayment::STATUS_PAID, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);

        // A late/duplicated payment_failed for the *same* txn_id arrives
        // after it was already captured.
        $this->handleFailedTransaction('evt_2', [
            'id' => 'txn_1',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'details' => ['totals' => ['total' => '1149']],
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_PAID, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    /**
     * adjustment/refund vs completed tardío: a refund is processed first
     * (payment REFUNDED, terminal); a late/replayed transaction.completed
     * for the *same* txn_id must not resurrect the payment or reactivate
     * the subscription.
     */
    public function test_a_late_completed_transaction_cannot_reactivate_behind_a_processed_refund(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_CANCELLED, 'ends_at' => now()->subDay()]);
        $this->mapToPaddle($subscription, ['status' => 'canceled']);

        TenantBillingPayment::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'plan_id' => 1,
            'amount' => 11.49,
            'currency' => 'USD',
            'status' => TenantBillingPayment::STATUS_REFUNDED,
            'gateway' => 'paddle',
            'gateway_payment_id' => 'txn_1',
            'transaction_id' => 'txn_1',
            'metadata' => ['paddle_price_id' => 'pri_test_monthly'],
        ]);

        $this->handleCompletedTransaction('evt_late', [
            'id' => 'txn_1',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'items' => [['price_id' => 'pri_test_monthly']],
            'details' => ['totals' => ['total' => '1149']],
            'billing_period' => [
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addMonth()->toIso8601String(),
            ],
        ]);

        $this->assertSame(TenantBillingPayment::STATUS_REFUNDED, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    // ── 4. Idempotency is preserved once everything runs inside the lock ──

    public function test_repeated_handle_completed_transaction_calls_remain_idempotent(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);

        $payload = [
            'id' => 'txn_1',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'items' => [['price_id' => 'pri_test_monthly']],
            'details' => ['totals' => ['total' => '1149']],
            'billing_period' => [
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addMonth()->toIso8601String(),
            ],
        ];

        $this->handleCompletedTransaction('evt_1', $payload);
        $firstEndsAt = $subscription->fresh()->ends_at;

        $this->handleCompletedTransaction('evt_1', $payload);

        $this->assertDatabaseCount('tenant_billing_payments', 1, 'central');
        $this->assertSame(TenantBillingPayment::STATUS_PAID, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);
        $this->assertTrue($firstEndsAt->equalTo($subscription->fresh()->ends_at));
    }

    public function test_repeated_handle_failed_transaction_calls_remain_idempotent(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);

        $payload = [
            'id' => 'txn_1',
            'subscription_id' => 'sub_123',
            'currency_code' => 'USD',
            'items' => [['price_id' => 'pri_test_monthly']],
            'details' => ['totals' => ['total' => '1149']],
        ];

        $this->handleFailedTransaction('evt_1', $payload);
        $this->assertSame(TenantBillingPayment::STATUS_FAILED, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);

        $this->handleFailedTransaction('evt_1', $payload);

        $this->assertDatabaseCount('tenant_billing_payments', 1, 'central');
        $this->assertSame(TenantBillingPayment::STATUS_FAILED, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);
    }

    // ── 5. The realistic call path (outer transaction + row lock + email
    //      deferred via afterCommit) works end-to-end without deadlocking ──

    /**
     * handle() wraps the whole dispatch in one DB::connection('central')
     * transaction. lockedRefresh() takes a real lockForUpdate() row lock
     * inside that transaction, and SubscriptionLifecycleService defers its
     * outbound email via DB::afterCommit() specifically so that lock is
     * never held across the network call. This reproduces that exact
     * structure (rather than calling the handler bare, like the other tests
     * in this file) to prove the combination doesn't deadlock, doesn't throw,
     * and still lands the correct final state once the outer transaction
     * commits.
     */
    public function test_handle_completed_transaction_works_inside_the_real_outer_transaction_and_row_lock(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);

        DB::connection('central')->transaction(function () {
            $this->handleCompletedTransaction('evt_1', [
                'id' => 'txn_1',
                'subscription_id' => 'sub_123',
                'currency_code' => 'USD',
                'items' => [['price_id' => 'pri_test_monthly']],
                'details' => ['totals' => ['total' => '1149']],
                'billing_period' => [
                    'starts_at' => now()->toIso8601String(),
                    'ends_at' => now()->addMonth()->toIso8601String(),
                ],
            ]);
        });

        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame(TenantBillingPayment::STATUS_PAID, TenantBillingPayment::where('gateway_payment_id', 'txn_1')->first()->status);
    }
}
