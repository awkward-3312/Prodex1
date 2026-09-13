<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Console\Commands\CheckSubscriptionExpiry;
use App\Models\Central\SubscriptionReminder;
use App\Models\Central\TenantSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * CheckSubscriptionExpiry used to read/write TenantSubscription rows outside
 * the 'billing:cancel:{id}' lock that Paddle webhooks, cancel, and resume
 * all serialize through — the exact race that fix/billing-subscription-
 * concurrency closed for those writers was still open on this one. This
 * file proves: the cron now shares that lock, a stale pre-lock read can't
 * decide or overwrite a concurrent writer's committed state, SUSPENDED
 * subscriptions are finalized once their ends_at lapses (reusing the
 * existing EXPIRED terminal state — nothing new), and repeated runs stay
 * idempotent.
 */
class CheckSubscriptionExpiryConcurrencyTest extends TestCase
{
    use BillingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        Schema::connection('central')->create('subscription_reminders', function ($table) {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->unsignedBigInteger('tenant_subscription_id')->nullable();
            $table->string('type', 32);
            $table->string('channel', 16);
            $table->unsignedSmallInteger('offset_days')->default(0);
            $table->date('reference_date')->nullable();
            $table->string('target')->nullable();
            $table->string('status', 16)->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('central')->create('email_templates', function ($table) {
            $table->id();
            $table->string('trigger_key');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::connection('central')->table('tenants')->insert([
            'id' => 'tenant-1',
            'data' => json_encode(['status' => 'active', 'admin_email' => 'admin@example.com']),
        ]);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);
    }

    private function makeSubscription(array $overrides = []): TenantSubscription
    {
        return TenantSubscription::create(array_merge([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'ends_at' => now()->subDay(),
        ], $overrides));
    }

    private function runCheckExpired(): void
    {
        $command = new CheckSubscriptionExpiry();
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        ));
        $method = (new ReflectionClass($command))->getMethod('checkExpired');
        $method->setAccessible(true);
        $method->invoke($command);
    }

    // ── Cron vs. concurrent billing actions ─────────────────────────────

    /**
     * A cancel/resume/webhook action holding the subscription lock is
     * mid-flight for this exact row when the cron reaches it. The cron must
     * back off (skip, log, move on) rather than force a decision on data it
     * can't safely read — it'll pick the row up on the next run.
     */
    public function test_cron_skips_a_subscription_whose_lock_is_held_by_a_concurrent_cancel(): void
    {
        $subscription = $this->makeSubscription();

        $heldLock = Cache::lock('billing:cancel:'.$subscription->id, 20);
        $heldLock->get();

        try {
            $this->runCheckExpired();
        } finally {
            $heldLock->release();
        }

        // Untouched — the cron backed off instead of racing the lock holder.
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }

    /**
     * cron vs. paid webhook: between the cron's initial scan and it acquiring
     * the lock, a transaction.completed webhook (or any concurrent writer)
     * renews the subscription — pushing ends_at into the future and
     * reactivating it. The cron's locked re-read must see that and skip,
     * never overwrite a genuinely-renewed subscription with EXPIRED.
     */
    public function test_cron_does_not_expire_a_subscription_renewed_concurrently_by_a_paid_webhook(): void
    {
        $subscription = $this->makeSubscription();

        $triggered = false;
        TenantSubscription::retrieved(function ($model) use (&$triggered, $subscription) {
            if (! $triggered && $model->is($subscription) && $model->status === TenantSubscription::STATUS_ACTIVE) {
                $triggered = true;
                DB::connection('central')->table('tenant_subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'ends_at' => now()->addMonth(),
                    ]);
            }
        });

        $this->runCheckExpired();

        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->ends_at->isFuture());

        TenantSubscription::flushEventListeners();
    }

    /**
     * cron vs. resume: between the scan and the lock, the customer resumes a
     * CANCELLED-but-still-in-grace subscription back to ACTIVE with a future
     * ends_at. The cron must not clobber that with EXPIRED.
     */
    public function test_cron_does_not_expire_a_subscription_resumed_concurrently(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_CANCELLED]);

        $triggered = false;
        TenantSubscription::retrieved(function ($model) use (&$triggered, $subscription) {
            if (! $triggered && $model->is($subscription)) {
                $triggered = true;
                DB::connection('central')->table('tenant_subscriptions')
                    ->where('id', $subscription->id)
                    ->update([
                        'status' => TenantSubscription::STATUS_ACTIVE,
                        'ends_at' => now()->addMonth(),
                        'cancelled_at' => null,
                    ]);
            }
        });

        $this->runCheckExpired();

        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->ends_at->isFuture());

        TenantSubscription::flushEventListeners();
    }

    // ── SUSPENDED finalization (Prioridad 2) ────────────────────────────

    public function test_suspended_subscription_past_ends_at_is_finalized_to_expired(): void
    {
        $subscription = $this->makeSubscription([
            'status' => TenantSubscription::STATUS_SUSPENDED,
            'ends_at' => now()->subDays(3),
        ]);

        $this->runCheckExpired();

        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $subscription->fresh()->status);
    }

    public function test_suspended_subscription_with_pending_cancellation_clears_the_flag_and_notifies_plan_ended(): void
    {
        $subscription = $this->makeSubscription([
            'status' => TenantSubscription::STATUS_SUSPENDED,
            'ends_at' => now()->subDays(3),
            'cancellation_requested_at' => now()->subDays(5),
        ]);

        $this->runCheckExpired();

        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $fresh->status);
        $this->assertNull($fresh->cancellation_requested_at);

        $reminder = SubscriptionReminder::where('tenant_subscription_id', $subscription->id)->first();
        $this->assertSame(SubscriptionReminder::TYPE_PLAN_ENDED, $reminder->type);
    }

    public function test_suspended_subscription_not_yet_past_ends_at_is_left_alone(): void
    {
        $subscription = $this->makeSubscription([
            'status' => TenantSubscription::STATUS_SUSPENDED,
            'ends_at' => now()->addDays(3),
        ]);

        $this->runCheckExpired();

        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_repeated_cron_runs_are_idempotent(): void
    {
        $subscription = $this->makeSubscription();

        $this->runCheckExpired();
        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $subscription->fresh()->status);

        $this->runCheckExpired();

        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $subscription->fresh()->status);
        $this->assertSame(
            1,
            SubscriptionReminder::where('tenant_subscription_id', $subscription->id)->count()
        );
    }
}
