<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Console\Commands\CheckSubscriptionExpiry;
use App\Models\Central\SubscriptionReminder;
use App\Models\Central\TenantSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * A subscription with cancellation_requested_at set stays ACTIVE past its
 * own ends_at if Paddle's subscription.canceled webhook is late/lost — the
 * daily expiry cron is the backstop that must still finalize it correctly:
 * as a cancellation reaching its end, not a plain unexpected expiry, and it
 * must not leave the now-meaningless flag behind on the terminal row.
 */
class CheckSubscriptionExpiryPendingCancellationTest extends TestCase
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
        $method = (new \ReflectionClass($command))->getMethod('checkExpired');
        $method->setAccessible(true);
        $method->invoke($command);
    }

    public function test_expiring_a_pending_cancellation_clears_the_flag(): void
    {
        $subscription = $this->makeSubscription(['cancellation_requested_at' => now()->subDays(2)]);

        $this->runCheckExpired();

        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $fresh->status);
        $this->assertNull($fresh->cancellation_requested_at);
    }

    public function test_expiring_a_pending_cancellation_records_a_plan_ended_reminder_not_a_plain_expired_one(): void
    {
        $subscription = $this->makeSubscription(['cancellation_requested_at' => now()->subDays(2)]);

        $this->runCheckExpired();

        $reminder = SubscriptionReminder::where('tenant_subscription_id', $subscription->id)->first();
        $this->assertNotNull($reminder);
        $this->assertSame(SubscriptionReminder::TYPE_PLAN_ENDED, $reminder->type);
    }

    public function test_expiring_a_subscription_with_no_pending_cancellation_still_records_a_plain_expired_reminder(): void
    {
        $subscription = $this->makeSubscription();

        $this->runCheckExpired();

        $reminder = SubscriptionReminder::where('tenant_subscription_id', $subscription->id)->first();
        $this->assertNotNull($reminder);
        $this->assertSame(SubscriptionReminder::TYPE_EXPIRED, $reminder->type);
        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $subscription->fresh()->status);
    }
}
