<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Central\PaddleWebhookController;
use App\Models\Central\PaddleCheckoutAttempt;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\PaddleWebhookEvent;
use App\Models\Central\TenantSubscription;
use App\Services\Paddle\PaddleCheckoutReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * A late Paddle webhook (e.g. redelivered after network trouble, or simply
 * slow) must not be able to create or claim a PRODEX subscription through a
 * checkout attempt whose TTL already lapsed — that reference is dead the
 * moment it expires, whether or not the lazy sweep in
 * PaddleBillingController::prepare() already flipped its status to
 * 'expired'. Legitimate follow-up webhooks for an attempt that was already
 * claimed before expiry must keep working.
 */
class PaddleWebhookExpiredCheckoutAttemptTest extends TestCase
{
    use BillingTestSchema;

    private PaddleCheckoutReference $references;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        config(['services.paddle.starter_monthly_price_id' => 'pri_test_monthly']);

        DB::connection('central')->table('tenants')->insert(['id' => 'tenant-1']);
        DB::connection('central')->table('plans')->insert([
            'id' => 1, 'name' => 'Starter', 'slug' => 'starter', 'price' => 11.49,
        ]);

        $this->references = new PaddleCheckoutReference();
    }

    private function makeAttempt(array $overrides = []): PaddleCheckoutAttempt
    {
        return PaddleCheckoutAttempt::create(array_merge([
            'reference' => (string) Str::uuid(),
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => 'initiated',
            'expires_at' => now()->addDay(),
        ], $overrides));
    }

    private function syncSubscription(array $data): void
    {
        $controller = new PaddleWebhookController();
        $method = (new ReflectionClass($controller))->getMethod('syncSubscription');
        $method->setAccessible(true);
        $method->invoke($controller, $data, null, $this->references);
    }

    private function subscriptionPayload(PaddleCheckoutAttempt $attempt, string $paddleSubscriptionId, string $status = 'active'): array
    {
        return [
            'id' => $paddleSubscriptionId,
            'status' => $status,
            'items' => [['price_id' => 'pri_test_monthly']],
            'custom_data' => ['prodex_ref' => $this->references->issue($attempt->reference)],
        ];
    }

    public function test_a_webhook_cannot_create_a_subscription_through_an_expired_checkout_attempt(): void
    {
        $attempt = $this->makeAttempt(['expires_at' => now()->subMinute()]);

        $this->expectException(RuntimeException::class);

        try {
            $this->syncSubscription($this->subscriptionPayload($attempt, 'sub_late_1'));
        } finally {
            $this->assertDatabaseCount('paddle_subscriptions', 0, 'central');
            $this->assertSame(0, TenantSubscription::count());
            $this->assertNull($attempt->fresh()->tenant_subscription_id);
            $this->assertNull($attempt->fresh()->paddle_subscription_id);
        }
    }

    public function test_a_webhook_cannot_create_a_subscription_through_an_attempt_not_left_initiated(): void
    {
        // Belt-and-suspenders: whatever put this unclaimed row into a
        // non-'initiated' status (expires_at itself is still in the
        // future), it must not be treated as claimable.
        $attempt = $this->makeAttempt(['status' => 'expired', 'expires_at' => now()->addHour()]);

        $this->expectException(RuntimeException::class);

        try {
            $this->syncSubscription($this->subscriptionPayload($attempt, 'sub_late_2'));
        } finally {
            $this->assertDatabaseCount('paddle_subscriptions', 0, 'central');
            $this->assertSame(0, TenantSubscription::count());
        }
    }

    public function test_a_webhook_still_claims_a_fresh_unexpired_checkout_attempt(): void
    {
        $attempt = $this->makeAttempt();

        $this->syncSubscription($this->subscriptionPayload($attempt, 'sub_fresh_1'));

        $this->assertSame('claimed', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->tenant_subscription_id);
        $this->assertDatabaseHas('paddle_subscriptions', [
            'paddle_subscription_id' => 'sub_fresh_1',
        ], 'central');
    }

    public function test_a_later_webhook_still_updates_a_subscription_claimed_before_its_attempt_expired(): void
    {
        $attempt = $this->makeAttempt(['expires_at' => now()->addMinute()]);
        $this->syncSubscription($this->subscriptionPayload($attempt, 'sub_claimed_1', 'active'));

        $subscriptionId = $attempt->fresh()->tenant_subscription_id;
        $this->assertNotNull($subscriptionId);

        // Time passes: the attempt's original TTL lapses, but it was already
        // claimed. A later lifecycle webhook for the same Paddle
        // subscription must still update the existing mapping.
        $attempt->fresh()->update(['expires_at' => now()->subDay()]);

        $this->syncSubscription($this->subscriptionPayload($attempt, 'sub_claimed_1', 'canceled'));

        $this->assertSame('canceled', PaddleSubscription::where('paddle_subscription_id', 'sub_claimed_1')->first()->status);
        $this->assertSame($subscriptionId, $attempt->fresh()->tenant_subscription_id);
    }

    /**
     * End-to-end through the real HTTP endpoint (signature verification
     * included): Paddle must receive 200 for an event it can never make
     * succeed, not 500 — a 500 makes Paddle retry (and alert) forever on an
     * expired checkout attempt that will never become claimable.
     */
    public function test_webhook_endpoint_acknowledges_rather_than_retries_an_expired_attempt_event(): void
    {
        config(['services.paddle.webhook_secret' => 'test_webhook_secret']);

        $attempt = $this->makeAttempt(['expires_at' => now()->subMinute()]);

        $payload = [
            'event_id' => 'evt_expired_1',
            'event_type' => 'subscription.created',
            'occurred_at' => now()->toIso8601String(),
            'data' => $this->subscriptionPayload($attempt, 'sub_late_http_1'),
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.':'.$rawBody, 'test_webhook_secret');

        $response = $this->call(
            'POST',
            '/webhook/paddle',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Paddle-Signature' => "ts={$timestamp};h1={$signature}",
            ],
            $rawBody
        );

        $response->assertStatus(200);
        $this->assertSame('ignored', PaddleWebhookEvent::where('event_id', 'evt_expired_1')->first()->status);
        $this->assertDatabaseCount('paddle_subscriptions', 0, 'central');
        $this->assertSame(0, TenantSubscription::count());
    }
}
