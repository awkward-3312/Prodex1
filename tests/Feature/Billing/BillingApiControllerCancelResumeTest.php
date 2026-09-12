<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Http\Controllers\Api\BillingApiController;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantSubscription;
use App\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * End-to-end wiring for the two tenant-facing billing endpoints: a
 * Paddle-mapped tenant's "cancel" must schedule at Paddle and keep access
 * (status stays ACTIVE); a tenant on another/no gateway keeps the legacy
 * immediate-cancel behavior untouched.
 */
class BillingApiControllerCancelResumeTest extends TestCase
{
    use BillingTestSchema;

    private Tenant $tenant;

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

        $this->tenant = new Tenant(['id' => 'tenant-1']);
        app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $this->tenant);

        $this->actingAs(new class implements Authenticatable {
            public int $id = 1;
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
            public function getAuthPasswordName() { return 'password'; }
            public function getAuthPassword() { return ''; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        });
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

    private function mapToPaddle(TenantSubscription $subscription): void
    {
        PaddleSubscription::create([
            'tenant_id' => $subscription->tenant_id,
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);
    }

    public function test_cancel_on_paddle_mapped_subscription_schedules_at_period_end_and_keeps_access(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        Http::fake(['*' => Http::response(['data' => ['status' => 'active']], 200)]);

        $response = app(BillingApiController::class)->cancelSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $payload['subscription']['status']);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        Http::assertSent(fn ($request) => $request['effective_from'] === 'next_billing_period');
    }

    public function test_cancel_on_non_paddle_subscription_keeps_legacy_immediate_behavior(): void
    {
        $subscription = $this->makeSubscription();
        Http::fake();

        $response = app(BillingApiController::class)->cancelSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $payload['subscription']['status']);
        Http::assertNothingSent();
    }

    public function test_cancel_on_non_paddle_subscription_still_shows_grace_period_message(): void
    {
        // cancel() flips status to CANCELLED synchronously but leaves ends_at
        // untouched, so the tenant keeps access until ends_at. The response
        // message must reflect that grace period instead of implying access
        // was lost immediately.
        $subscription = $this->makeSubscription(['ends_at' => now()->addDays(10)]);
        Http::fake();

        $response = app(BillingApiController::class)->cancelSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $payload['subscription']['status']);
        $this->assertStringContainsString('Permanecerá activa hasta', $payload['message']);
    }

    public function test_cancel_shows_plain_cancelled_message_when_no_future_grace_period_remains(): void
    {
        $subscription = $this->makeSubscription(['ends_at' => now()->subDay()]);
        Http::fake();

        $response = app(BillingApiController::class)->cancelSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame('La suscripción fue cancelada.', $payload['message']);
    }

    public function test_cancel_returns_502_and_does_not_mutate_when_paddle_call_fails(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $response = app(BillingApiController::class)->cancelSubscription();
        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->cancellation_requested_at);
    }

    public function test_resume_on_pending_cancellation_removes_paddle_schedule_and_keeps_active(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        $subscription->markCancellationRequested();
        Http::fake(['*' => Http::response(['data' => ['status' => 'active', 'scheduled_change' => null]], 200)]);

        $response = app(BillingApiController::class)->resumeSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertNull($subscription->fresh()->cancellation_requested_at);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_current_plan_shows_suspended_subscription_over_an_older_cancelled_row(): void
    {
        $olderCancelled = $this->makeSubscription(['status' => TenantSubscription::STATUS_CANCELLED, 'cancelled_at' => now()->subDays(30), 'ends_at' => now()->subDays(29)]);
        $olderCancelled->forceFill(['created_at' => now()->subDays(30)])->save();

        $newerSuspended = $this->makeSubscription(['status' => TenantSubscription::STATUS_SUSPENDED, 'cancellation_requested_at' => now()]);
        $newerSuspended->forceFill(['created_at' => now()])->save();

        $response = app(BillingApiController::class)->currentPlan();
        $payload = $response->getData(true);

        $this->assertSame($newerSuspended->id, $payload['subscription']['id']);
        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $payload['subscription']['status']);
    }

    public function test_current_plan_reports_pending_cancellation_as_still_resumable_and_active(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        $subscription->markCancellationRequested();

        $response = app(BillingApiController::class)->currentPlan();
        $payload = $response->getData(true);

        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $payload['subscription']['status']);
        $this->assertTrue($payload['subscription']['is_pending_cancellation']);
        $this->assertTrue($payload['subscription']['can_resume']);
        $this->assertFalse($payload['subscription']['is_cancelled']);
    }

    public function test_current_plan_does_not_report_pending_cancellation_for_a_plain_active_subscription(): void
    {
        $this->makeSubscription();

        $response = app(BillingApiController::class)->currentPlan();
        $payload = $response->getData(true);

        $this->assertFalse($payload['subscription']['is_pending_cancellation']);
        $this->assertFalse($payload['subscription']['can_resume']);
    }

    public function test_resume_response_payload_matches_cancel_response_shape(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        $subscription->markCancellationRequested();
        Http::fake(['*' => Http::response(['data' => ['status' => 'active', 'scheduled_change' => null]], 200)]);

        $response = app(BillingApiController::class)->resumeSubscription();
        $payload = $response->getData(true);

        $this->assertArrayHasKey('cancellation_requested_at', $payload['subscription']);
        $this->assertArrayHasKey('can_resume', $payload['subscription']);
        $this->assertArrayHasKey('is_cancelled', $payload['subscription']);
    }

    public function test_resume_on_suspended_pending_cancellation_removes_paddle_schedule_but_does_not_claim_full_success(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_SUSPENDED]);
        $this->mapToPaddle($subscription);
        $subscription->markCancellationRequested();
        Http::fake(['*' => Http::response(['data' => ['status' => 'past_due', 'scheduled_change' => null]], 200)]);

        $response = app(BillingApiController::class)->resumeSubscription();
        $payload = $response->getData(true);

        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
        $fresh = $subscription->fresh();
        $this->assertNull($fresh->cancellation_requested_at);
        $this->assertSame(TenantSubscription::STATUS_SUSPENDED, $fresh->status);
        // The tenant is still blocked — the response must not claim access
        // was restored.
        $this->assertStringContainsString('suspend', mb_strtolower($payload['message']));
    }

    public function test_resume_refuses_a_paddle_mapped_subscription_already_cancelled_at_paddle(): void
    {
        $subscription = $this->makeSubscription(['status' => TenantSubscription::STATUS_CANCELLED, 'cancelled_at' => now(), 'ends_at' => now()->addDays(5)]);
        $this->mapToPaddle($subscription);
        Http::fake();

        $response = app(BillingApiController::class)->resumeSubscription();
        $payload = $response->getData(true);

        $this->assertFalse($payload['success']);
        $this->assertSame(422, $response->getStatusCode());
        Http::assertNothingSent();
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $subscription->fresh()->status);
    }

    public function test_resume_finds_an_older_resumable_row_even_when_the_newest_candidate_row_is_not_resumable(): void
    {
        // Newest row: CANCELLED but its grace period already lapsed — not
        // resumable. An older row is still within its grace period and IS
        // resumable. latest()->first() alone would pick the newer one and
        // wrongly report "nothing to resume".
        $resumable = $this->makeSubscription([
            'status' => TenantSubscription::STATUS_CANCELLED,
            'cancelled_at' => now()->subDays(10),
            'ends_at' => now()->addDays(5),
        ]);
        $resumable->forceFill(['created_at' => now()->subDays(10)])->save();

        $notResumable = $this->makeSubscription([
            'status' => TenantSubscription::STATUS_CANCELLED,
            'cancelled_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        $notResumable->forceFill(['created_at' => now()])->save();
        Http::fake();

        $response = app(BillingApiController::class)->resumeSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame($resumable->id, $payload['subscription']['id']);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $resumable->fresh()->status);
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $notResumable->fresh()->status);
    }

    public function test_cancel_treats_a_concurrently_cancelled_subscription_as_already_resolved(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        $retrievals = 0;

        // Simulate a webhook confirming the cancellation between the
        // controller's initial SELECT (retrieval #1) and the lock closure's
        // refresh() (retrieval #2), which is exactly the race window the
        // guard under test protects against.
        TenantSubscription::retrieved(function ($model) use (&$retrievals, $subscription) {
            if ($model->is($subscription)) {
                $retrievals++;
                if ($retrievals === 1) {
                    DB::connection('central')->table('tenant_subscriptions')
                        ->where('id', $subscription->id)
                        ->update(['status' => TenantSubscription::STATUS_CANCELLED, 'cancellation_requested_at' => null]);
                }
            }
        });
        Http::fake(['*' => Http::response(['data' => ['status' => 'canceled']], 200)]);

        $response = app(BillingApiController::class)->cancelSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        Http::assertNothingSent();
        $this->assertSame(TenantSubscription::STATUS_CANCELLED, $payload['subscription']['status']);
    }

    public function test_cancel_returns_409_when_a_concurrent_request_already_holds_the_lock(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        Http::fake();

        $heldLock = Cache::lock('billing:cancel:'.$subscription->id, 20);
        $heldLock->get();

        try {
            $response = app(BillingApiController::class)->cancelSubscription();
            $payload = $response->getData(true);

            $this->assertFalse($payload['success']);
            $this->assertSame(409, $response->getStatusCode());
            Http::assertNothingSent();
        } finally {
            $heldLock->release();
        }
    }

    public function test_cancel_is_idempotent_when_already_pending(): void
    {
        $subscription = $this->makeSubscription();
        $this->mapToPaddle($subscription);
        $subscription->markCancellationRequested();
        Http::fake();

        $response = app(BillingApiController::class)->cancelSubscription();
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        Http::assertNothingSent();
    }
}
