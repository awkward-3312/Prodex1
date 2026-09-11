<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Exceptions\PaddleApiException;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantSubscription;
use App\Services\Billing\SubscriptionCancellationService;
use App\Services\Paddle\PaddleSubscriptionApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * The dashboard "cancel" action used to only flip local status — Paddle was
 * never told, so it would keep billing a subscription PRODEX considered
 * cancelled. This service is the single place that now decides, per
 * subscription, whether cancelling means "ask Paddle to schedule it for
 * period end" (customer self-service — access must survive until ends_at)
 * or "confirmed immediate revocation" (admin override / no Paddle mapping).
 */
class SubscriptionCancellationServiceTest extends TestCase
{
    use BillingTestSchema;

    private SubscriptionCancellationService $service;

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

        $this->service = new SubscriptionCancellationService(new PaddleSubscriptionApi());
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

    private function mapToPaddle(TenantSubscription $subscription, string $paddleSubscriptionId = 'sub_123'): PaddleSubscription
    {
        return PaddleSubscription::create([
            'tenant_id' => $subscription->tenant_id,
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => $paddleSubscriptionId,
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);
    }

    public function test_schedule_cancellation_calls_paddle_with_next_billing_period_and_keeps_access(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        Http::fake(['*' => Http::response(['data' => ['status' => 'active']], 200)]);

        $this->service->scheduleCancellationAtPeriodEnd($subscription);

        Http::assertSent(fn ($request) => $request['effective_from'] === 'next_billing_period');
        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertNotNull($fresh->cancellation_requested_at);
    }

    public function test_schedule_cancellation_without_paddle_mapping_falls_back_to_immediate_local_cancel(): void
    {
        $subscription = $this->makeSubscription();
        Http::fake();

        $this->service->scheduleCancellationAtPeriodEnd($subscription);

        Http::assertNothingSent();
        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $fresh->status);
    }

    public function test_schedule_cancellation_does_not_mutate_subscription_when_paddle_call_fails(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

        try {
            $this->service->scheduleCancellationAtPeriodEnd($subscription);
            $this->fail('Expected PaddleApiException');
        } catch (PaddleApiException $e) {
            // expected
        }

        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->cancellation_requested_at);
    }

    public function test_cancel_immediately_calls_paddle_with_immediately_and_cancels_locally(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        Http::fake(['*' => Http::response(['data' => ['status' => 'canceled']], 200)]);

        $this->service->cancelImmediately($subscription);

        Http::assertSent(fn ($request) => $request['effective_from'] === 'immediately');
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_cancel_immediately_still_cancels_locally_when_paddle_api_call_fails(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);

        $this->service->cancelImmediately($subscription);

        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_resume_scheduled_cancellation_removes_it_from_paddle_and_clears_local_flag(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        $subscription->markCancellationRequested();
        Http::fake(['*' => Http::response(['data' => ['status' => 'active', 'scheduled_change' => null]], 200)]);

        $this->service->resumeScheduledCancellation($subscription);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH');
        $fresh = $subscription->fresh();
        $this->assertNull($fresh->cancellation_requested_at);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
    }

    public function test_resume_scheduled_cancellation_without_paddle_mapping_just_clears_local_flag(): void
    {
        $subscription = $this->makeSubscription();
        $subscription->markCancellationRequested();
        Http::fake();

        $this->service->resumeScheduledCancellation($subscription);

        Http::assertNothingSent();
        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_resume_falls_back_to_legacy_resume_when_already_actually_cancelled_and_not_paddle_mapped(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'ends_at' => now()->addDays(5)]);
        Http::fake();

        $this->service->resumeScheduledCancellation($subscription);

        Http::assertNothingSent();
        $fresh = $subscription->fresh();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
    }

    public function test_resume_refuses_to_reactivate_a_paddle_mapped_subscription_already_cancelled_at_paddle(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'ends_at' => now()->addDays(5)]);
        $this->mapToPaddle($subscription);
        Http::fake();

        $this->expectException(\App\Exceptions\SubscriptionNotResumableException::class);

        try {
            $this->service->resumeScheduledCancellation($subscription);
        } finally {
            Http::assertNothingSent();
            $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
        }
    }
}
