<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Http\Controllers\Central\Super\TenantSubscriptionController;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * Super-admin "cancel" is an emergency override: it must revoke access
 * immediately regardless of whether Paddle can be reached, but it should
 * still best-effort notify Paddle when a mapping exists so the tenant isn't
 * billed again after an admin already cut them off locally.
 */
class TenantSubscriptionControllerAdminCancelTest extends TestCase
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

        DB::connection('central')->table('tenants')->insert(['id' => 'tenant-1']);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);
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

    public function test_admin_cancel_notifies_paddle_immediately_and_cancels_locally(): void
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

        app(TenantSubscriptionController::class)->cancel(new Request(), $subscription);

        Http::assertSent(fn ($request) => $request['effective_from'] === 'immediately');
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_admin_cancel_still_cancels_locally_when_paddle_is_unreachable(): void
    {
        $subscription = $this->makeSubscription();
        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);

        app(TenantSubscriptionController::class)->cancel(new Request(), $subscription);

        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_admin_cancel_without_paddle_mapping_still_cancels_locally(): void
    {
        $subscription = $this->makeSubscription();
        Http::fake();

        app(TenantSubscriptionController::class)->cancel(new Request(), $subscription);

        Http::assertNothingSent();
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }
}
