<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Tenant\PaddleBillingController;
use App\Models\Central\PaddleCheckoutAttempt;
use App\Services\Paddle\PaddleCheckoutReference;
use App\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * A double-click, a page refresh, or two parallel requests for the same
 * tenant+plan+cycle must not each mint their own valid 'initiated'
 * PaddleCheckoutAttempt — every one of those attempts is independently
 * usable to claim a subscription (PaddleWebhookController::resolveSubscription),
 * so two live attempts completed at Paddle could create two subscriptions
 * for the same tenant. This must be closed server-side, not left to the
 * frontend disabling a button.
 */
class PaddleCheckoutAttemptIdempotencyTest extends TestCase
{
    use BillingTestSchema;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();

        config([
            'services.paddle.environment' => 'sandbox',
            'services.paddle.sandbox_tenant' => 'tenant-1',
            'services.paddle.starter_monthly_price_id' => 'pri_test_monthly',
            'services.paddle.starter_yearly_price_id' => 'pri_test_yearly',
            'services.paddle.client_side_token' => 'test_client_token',
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

    private function prepare(string $cycle = 'monthly'): array
    {
        $request = Request::create('/tenant/paddle/prepare', 'POST', [
            'plan_id' => 1,
            'billing_cycle' => $cycle,
        ]);

        $controller = new PaddleBillingController();
        $response = $controller->prepare($request, new PaddleCheckoutReference());

        return [$response->getStatusCode(), $response->getData(true)];
    }

    public function test_a_second_prepare_call_reuses_the_still_valid_attempt_instead_of_creating_a_new_one(): void
    {
        [$status1, $payload1] = $this->prepare();
        $this->assertSame(200, $status1);

        [$status2, $payload2] = $this->prepare();
        $this->assertSame(200, $status2);

        $this->assertSame(1, PaddleCheckoutAttempt::count());
        $this->assertSame($payload1['custom_data']['prodex_ref'], $payload2['custom_data']['prodex_ref']);
    }

    public function test_three_rapid_prepare_calls_for_the_same_tenant_plan_cycle_produce_exactly_one_usable_attempt(): void
    {
        $this->prepare();
        $this->prepare();
        $this->prepare();

        $this->assertSame(1, PaddleCheckoutAttempt::count());
        $this->assertSame(
            1,
            PaddleCheckoutAttempt::where('tenant_id', 'tenant-1')
                ->where('status', 'initiated')
                ->where('expires_at', '>', now())
                ->count()
        );
    }

    /**
     * A genuinely concurrent request (another process/request already
     * holding the same lock) must be rejected rather than proceeding on
     * stale/unserialized data.
     */
    public function test_a_truly_concurrent_prepare_call_is_rejected_while_the_lock_is_held(): void
    {
        $heldLock = Cache::lock('paddle:prepare:tenant-1:1:monthly', 10);
        $heldLock->get();

        try {
            [$status, $payload] = $this->prepare();
            $this->assertSame(409, $status);
            $this->assertFalse($payload['success']);
        } finally {
            $heldLock->release();
        }

        $this->assertSame(0, PaddleCheckoutAttempt::count());
    }

    public function test_an_expired_attempt_is_not_reused_and_a_fresh_one_is_created(): void
    {
        $old = PaddleCheckoutAttempt::create([
            'reference' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => 'initiated',
            'expires_at' => now()->subMinute(),
        ]);

        [$status, $payload] = $this->prepare();

        $this->assertSame(200, $status);
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame(2, PaddleCheckoutAttempt::count());

        $new = PaddleCheckoutAttempt::where('status', 'initiated')->first();
        $this->assertNotNull($new);
        $this->assertNotSame($old->reference, $new->reference);
    }

    public function test_a_claimed_attempt_is_not_reused_and_a_fresh_one_is_created(): void
    {
        $claimed = PaddleCheckoutAttempt::create([
            'reference' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => 'claimed',
            'claimed_at' => now(),
            'expires_at' => now()->addHours(12),
        ]);

        [$status] = $this->prepare();

        $this->assertSame(200, $status);
        $this->assertSame(2, PaddleCheckoutAttempt::count());
        $this->assertSame('claimed', $claimed->fresh()->status);
        $this->assertSame(
            1,
            PaddleCheckoutAttempt::where('status', 'initiated')->where('id', '!=', $claimed->id)->count()
        );
    }

    public function test_a_different_billing_cycle_does_not_reuse_an_attempt_for_another_cycle(): void
    {
        $this->prepare('monthly');
        $this->prepare('yearly');

        $this->assertSame(2, PaddleCheckoutAttempt::count());
        $this->assertSame(
            1,
            PaddleCheckoutAttempt::where('billing_cycle', 'monthly')->count()
        );
        $this->assertSame(
            1,
            PaddleCheckoutAttempt::where('billing_cycle', 'yearly')->count()
        );
    }
}
