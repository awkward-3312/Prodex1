<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Http\Controllers\Central\PaddleWebhookController;
use App\Models\Central\PaddleSubscription;
use App\Models\Central\TenantSubscription;
use App\Services\Paddle\PaddleCheckoutReference;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\Support\BillingTestSchema;
use Tests\TestCase;

/**
 * A customer's cancel/resume click makes a live Paddle API call before
 * writing locally (BillingApiController::withSubscriptionLock). If an
 * incoming subscription.* webhook for the same subscription could
 * read-modify-write the row while that's in flight, one write silently
 * clobbers the other. The webhook must serialize against the same lock
 * namespace ('billing:cancel:{subscription id}'), not just against other
 * webhook deliveries.
 */
class PaddleWebhookSubscriptionLockTest extends TestCase
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

    private function syncSubscription(array $data): void
    {
        $controller = new PaddleWebhookController();
        $method = (new ReflectionClass($controller))->getMethod('syncSubscription');
        $method->setAccessible(true);
        $method->invoke($controller, $data, null, new PaddleCheckoutReference());
    }

    public function test_sync_subscription_refuses_to_run_while_a_billing_action_holds_the_subscription_lock(): void
    {
        $subscription = TenantSubscription::create([
            'tenant_id' => 'tenant-1',
            'plan_id' => 1,
            'billing_cycle' => 'monthly',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'ends_at' => now()->addDays(10),
        ]);
        PaddleSubscription::create([
            'tenant_id' => 'tenant-1',
            'tenant_subscription_id' => $subscription->id,
            'paddle_subscription_id' => 'sub_123',
            'paddle_price_id' => 'pri_test_monthly',
            'status' => 'active',
        ]);

        $heldLock = Cache::lock('billing:cancel:'.$subscription->id, 20);
        $heldLock->get();

        $threw = false;
        try {
            $this->syncSubscription([
                'id' => 'sub_123',
                'status' => 'canceled',
                'canceled_at' => now()->toIso8601String(),
            ]);
        } catch (RuntimeException $e) {
            $threw = true;
        } finally {
            $heldLock->release();
        }

        $this->assertTrue($threw, 'Expected a RuntimeException when the subscription lock is already held.');
        // The webhook must not have mutated anything while locked out.
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
    }
}
