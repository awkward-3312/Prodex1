<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Http\Controllers\Central\Super\TenantSubscriptionController;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * The admin edit form's generic status field can also drive a subscription
 * to CANCELLED (it's a valid ACTIVE -> CANCELLED transition), not just the
 * dedicated Cancel button. That path must go through the same
 * SubscriptionCancellationService — otherwise Paddle is never told and keeps
 * billing a subscription the admin just marked cancelled.
 */
class TenantSubscriptionControllerUpdateCancelTest extends TestCase
{
    use BillingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        config([
            'services.paddle.api_key' => 'test_api_key_123',
            'services.paddle.environment' => 'sandbox',
            'services.paddle.starter_monthly_price_id' => 'pri_test_monthly',
        ]);

        DB::connection('central')->table('tenants')->insert([
            'id' => 'tenant-1',
            'data' => json_encode(['status' => 'active']),
        ]);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);

        // The admin form's `exists:plans,id` validation rule queries the
        // DEFAULT connection (not `central`), so the default-connection
        // sqlite database needs its own matching row purely to satisfy it.
        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function ($table) {
                $table->id();
            });
        }
        DB::table('plans')->insert(['id' => 1]);
    }

    private function makeSubscription(): TenantSubscription
    {
        return TenantSubscription::create([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'ends_at' => now()->addDays(10),
        ]);
    }

    public function test_generic_update_to_cancelled_notifies_paddle_when_mapped(): void
    {
        $subscription = $this->makeSubscription();
        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);
        Http::fake(['*' => Http::response(['data' => ['status' => 'canceled']], 200)]);

        $request = Request::create('/x', 'POST', [
            'plan_id' => 1,
            'status' => 'cancelled',
        ]);

        app(TenantSubscriptionController::class)->update($request, $subscription);

        Http::assertSent(fn ($r) => $r['effective_from'] === 'immediately');
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_generic_update_reactivating_a_pending_cancellation_notifies_paddle(): void
    {
        $subscription = $this->makeSubscription();
        $subscription->update(['status' => TenantSubscription::STATUS_SUSPENDED, 'cancellation_requested_at' => now()]);
        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);
        Http::fake(['*' => Http::response(['data' => ['status' => 'active', 'scheduled_change' => null]], 200)]);

        $request = Request::create('/x', 'POST', [
            'plan_id' => 1,
            'status' => 'active',
        ]);

        app(TenantSubscriptionController::class)->update($request, $subscription);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->cancellation_requested_at);
    }

    public function test_generic_update_with_unchanged_active_status_still_resumes_a_pending_cancellation(): void
    {
        $subscription = $this->makeSubscription();
        $subscription->markCancellationRequested();
        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);
        Http::fake(['*' => Http::response(['data' => ['status' => 'active', 'scheduled_change' => null]], 200)]);

        $request = Request::create('/x', 'POST', [
            'plan_id' => 1,
            'status' => 'active',
        ]);

        app(TenantSubscriptionController::class)->update($request, $subscription);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_generic_update_to_non_cancelled_status_does_not_call_paddle(): void
    {
        $subscription = $this->makeSubscription();
        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);
        Http::fake();

        $request = Request::create('/x', 'POST', [
            'plan_id' => 1,
            'status' => 'suspended',
        ]);

        app(TenantSubscriptionController::class)->update($request, $subscription);

        Http::assertNothingSent();
        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
    }
}
